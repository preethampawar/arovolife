<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Exceptions\PaymentMismatchException;
use App\Modules\Payments\Exceptions\RazorpayApiException;
use App\Modules\Payments\Exceptions\SignatureVerificationException;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Services\PaymentGatewayResolver;
use App\Modules\Payments\Services\RazorpayGateway;
use App\Modules\Payments\Support\RazorpayPayloadScrubber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The buyer's side of a Razorpay payment: the page that opens the Checkout
 * modal, the callback the modal posts back, the failure/dismiss record and
 * the status poll. Everything here is 404 unless Razorpay is the active
 * gateway, and every route authorises the way the confirmation page does —
 * the order must be in this session's `recent_order_nos` or belong to the
 * signed-in user.
 *
 * Nothing here trusts the browser about money. The callback carries a
 * signature, but the order is marked paid only after the payment is fetched
 * from Razorpay's API and checked against the intent
 * (PaymentConfirmationService).
 */
final class PaymentController extends Controller
{
    /** How long the "confirming your payment" page keeps polling. */
    public const CONFIRM_POLL_SECONDS = 120;

    public function __construct(
        private readonly PaymentGatewayResolver $resolver,
        private readonly RazorpayGateway $razorpay,
        private readonly PaymentConfirmationService $confirmation,
        private readonly RazorpayPayloadScrubber $scrubber,
    ) {}

    public function pay(Request $request, string $orderNo): View|RedirectResponse
    {
        $this->ensureRazorpay();
        $order = $this->ownedOrder($request, $orderNo);

        if ($order->paid_at !== null) {
            return redirect()->route('shop.confirmation', $order->order_no);
        }

        if ($order->status !== Order::STATUS_PLACED) {
            return view('shop.pay-unavailable', ['order' => $order, 'reason' => 'closed']);
        }

        $intent = $this->intentFor($order);

        if ($intent === null || $intent->gateway_order_id === null) {
            // Placement redirected here before the gateway order existed
            // (a transient gateway error). One more try; the intent key
            // makes it safe.
            try {
                $intent = $this->razorpay->createIntent($order, 'order:'.$order->id);
            } catch (Throwable $e) {
                Log::error('Pay page: could not create the gateway order', ['order_id' => $order->id, 'exception' => $e]);

                return view('shop.pay-unavailable', ['order' => $order, 'reason' => 'gateway']);
            }
        }

        if ($intent->expires_at !== null && $intent->expires_at->isPast()) {
            return view('shop.pay-unavailable', ['order' => $order, 'reason' => 'expired']);
        }

        $remainingSeconds = $intent->expires_at === null
            ? 900
            : max(60, (int) Carbon::now()->diffInSeconds($intent->expires_at, false));

        return view('shop.pay', [
            'order' => $order,
            'intent' => $intent,
            'keyId' => $this->razorpay->publicKeyId(),
            'timeoutSeconds' => $remainingSeconds,
            'confirming' => $request->boolean('confirming'),
            'pollSeconds' => self::CONFIRM_POLL_SECONDS,
        ]);
    }

    /** What the Checkout modal returns on success. */
    public function callback(Request $request, string $orderNo): RedirectResponse
    {
        $this->ensureRazorpay();
        $order = $this->ownedOrder($request, $orderNo);

        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:64'],
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ]);

        $intent = $this->intentFor($order);
        if ($intent === null) {
            throw new NotFoundHttpException;
        }

        try {
            $result = $this->confirmation->confirmFromCallback(
                $intent,
                $validated['razorpay_order_id'],
                $validated['razorpay_payment_id'],
                $validated['razorpay_signature'],
            );
        } catch (SignatureVerificationException|PaymentMismatchException $e) {
            // Already recorded and, for a mismatch, alerted. The buyer gets a
            // neutral message: if money did leave their account the webhook or
            // the reconciler will find it and it is refunded or confirmed
            // from the gateway's own record, never from this post.
            return redirect()->route('shop.pay', $order->order_no)
                ->with('payment_error', 'We could not verify that payment. If an amount was deducted it will be matched to your order or returned automatically.');
        } catch (RazorpayApiException $e) {
            // Razorpay unreachable for the fetch: the webhook will confirm.
            return redirect()->route('shop.pay', ['orderNo' => $order->order_no, 'confirming' => 1]);
        }

        return match ($result->status) {
            ConfirmationResult::CONFIRMED,
            ConfirmationResult::ALREADY_CONFIRMED => redirect()->route('shop.confirmation', $order->order_no),
            ConfirmationResult::FAILED => redirect()->route('shop.pay', $order->order_no)
                ->with('payment_error', 'That payment did not go through. You have not been charged — please try again.'),
            ConfirmationResult::LATE_CAPTURE => redirect()->route('shop.pay', $order->order_no),
            default => redirect()->route('shop.pay', ['orderNo' => $order->order_no, 'confirming' => 1]),
        };
    }

    /** The modal reported a failed attempt, or the buyer closed it. Record only. */
    public function failure(Request $request, string $orderNo): JsonResponse
    {
        $this->ensureRazorpay();
        $order = $this->ownedOrder($request, $orderNo);
        $intent = $this->intentFor($order);

        $validated = $request->validate([
            'kind' => ['required', 'in:failed,dismissed'],
            'error' => ['nullable', 'array'],
        ]);

        /** @var array<string, mixed> $error */
        $error = $validated['error'] ?? [];
        $scrubbed = $this->scrubber->scrub($error);

        PaymentEvent::create([
            'order_id' => $order->id,
            'payment_intent_id' => $intent?->id,
            'gateway' => PaymentIntent::GATEWAY_RAZORPAY,
            'direction' => PaymentEvent::DIRECTION_CALLBACK,
            'event_type' => 'checkout.'.$validated['kind'],
            'gateway_payment_id' => isset($error['metadata']['payment_id']) ? (string) $error['metadata']['payment_id'] : null,
            'signature_verified' => false,
            'payload' => $scrubbed,
            'error' => $validated['kind'] === 'failed'
                ? trim((string) ($error['code'] ?? 'UNKNOWN').': '.(string) ($error['description'] ?? ''))
                : 'buyer closed the checkout',
        ]);

        // The attempt lives in payment_events; the intent keeps the last
        // gateway reason for the admin screen but its status is untouched —
        // the buyer can still try again, and a later capture must win.
        if ($intent !== null && $validated['kind'] === 'failed' && $intent->status !== PaymentIntent::STATUS_CAPTURED) {
            $intent->update([
                'attempt_count' => $intent->attempt_count + 1,
                'error_code' => isset($error['code']) ? mb_substr((string) $error['code'], 0, 64) : null,
                'error_description' => isset($error['description']) ? mb_substr((string) $error['description'], 0, 255) : null,
            ]);
        }

        return response()->json(['recorded' => true]);
    }

    /**
     * Polled by the "confirming your payment" state. Asks the gateway at
     * most once every few seconds, so a buyer whose callback was lost is
     * confirmed without waiting for the webhook or the reconciler.
     */
    public function status(Request $request, string $orderNo): JsonResponse
    {
        $this->ensureRazorpay();
        $order = $this->ownedOrder($request, $orderNo);

        if ($order->paid_at === null && $order->status === Order::STATUS_PLACED) {
            $intent = $this->intentFor($order);
            $stale = $intent !== null && ($intent->last_synced_at === null || $intent->last_synced_at->lt(Carbon::now()->subSeconds(5)));
            if ($intent !== null && $stale && $intent->gateway_order_id !== null) {
                try {
                    $this->confirmation->syncAndConfirm($intent, PaymentIntent::CONFIRMED_VIA_RECONCILE);
                } catch (Throwable $e) {
                    // Recorded by the service; the poll just reports the state.
                }
                $order->refresh();
            }
        }

        if ($order->paid_at !== null) {
            return response()->json(['status' => 'paid', 'redirect' => route('shop.confirmation', $order->order_no)]);
        }

        return response()->json([
            'status' => $order->status === Order::STATUS_PLACED ? 'pending' : 'closed',
        ]);
    }

    private function ensureRazorpay(): void
    {
        if (! $this->resolver->isRazorpay()) {
            throw new NotFoundHttpException;
        }
    }

    /** Same rule as the confirmation page: the session that placed it, or its owner. */
    private function ownedOrder(Request $request, string $orderNo): Order
    {
        $order = Order::with('customer')->where('order_no', $orderNo)->first();
        if ($order === null || $order->payment_method !== Order::PAYMENT_ONLINE) {
            throw new NotFoundHttpException;
        }

        $recent = (array) $request->session()->get('recent_order_nos', []);
        $userId = $request->user()?->id;
        $owns = $userId !== null && $order->customer !== null && $order->customer->user_id === $userId;

        if (! in_array($order->order_no, $recent, true) && ! $owns) {
            throw new NotFoundHttpException;
        }

        return $order;
    }

    private function intentFor(Order $order): ?PaymentIntent
    {
        return PaymentIntent::where('order_id', $order->id)
            ->where('gateway', PaymentIntent::GATEWAY_RAZORPAY)
            ->orderByDesc('id')
            ->first();
    }
}
