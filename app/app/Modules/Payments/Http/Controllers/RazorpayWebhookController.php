<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Jobs\ProcessRazorpayWebhookJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\RazorpayClient;
use App\Modules\Payments\Support\RazorpayPayloadScrubber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Razorpay → us. The primary authority for "this order is paid".
 *
 * Three rules, in order:
 *   1. The signature over the RAW body must verify with the webhook secret,
 *      or the request is a 400 and nothing is stored. Sender IP and
 *      user-agent are not trusted; only the signature is.
 *   2. Every verified event is stored once, keyed on Razorpay's
 *      `x-razorpay-event-id` header. Razorpay retries and re-orders
 *      deliveries; the unique index makes a duplicate a 200 no-op.
 *   3. Storing and applying are separate. The handler returns 200 as soon
 *      as the event is on disk and a job is queued — a slow confirmation
 *      must not make Razorpay give up and retry into a duplicate. Applying
 *      it (which re-fetches the payment from the API before marking paid)
 *      happens in ProcessRazorpayWebhookJob.
 */
final class RazorpayWebhookController extends Controller
{
    public function __construct(
        private readonly RazorpayClient $client,
        private readonly RazorpayPayloadScrubber $scrubber,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->client->configured()) {
            // No secret to verify against: this endpoint does not exist.
            throw new NotFoundHttpException;
        }

        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! $this->client->verifyWebhookSignature($raw, $signature)) {
            Log::channel('payments')->warning('razorpay webhook rejected: signature did not verify', [
                'ip' => $request->ip(),
                'length' => strlen($raw),
            ]);

            return response()->json(['error' => 'signature'], 400);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $eventType = (string) ($body['event'] ?? 'unknown');
        $eventId = (string) $request->header('X-Razorpay-Event-Id', '');
        if ($eventId === '') {
            // Older deliveries may omit the header; fall back to a digest of
            // the body so a retry of the same delivery still dedupes.
            $eventId = 'sha256:'.hash('sha256', $raw);
        }

        [$gatewayOrderId, $gatewayPaymentId] = $this->referencesIn($body);
        $intent = $gatewayOrderId === null ? null : PaymentIntent::where('gateway_order_id', $gatewayOrderId)->first();

        try {
            $event = PaymentEvent::create([
                'order_id' => $intent?->order_id,
                'payment_intent_id' => $intent?->id,
                'gateway' => PaymentIntent::GATEWAY_RAZORPAY,
                'direction' => PaymentEvent::DIRECTION_WEBHOOK,
                'event_type' => $eventType,
                'gateway_event_id' => $eventId,
                'gateway_payment_id' => $gatewayPaymentId,
                'signature_verified' => true,
                'payload' => $this->scrubber->scrub($body),
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::channel('payments')->info('razorpay webhook duplicate ignored', ['event' => $eventType, 'event_id' => $eventId]);

            return response()->json(['status' => 'duplicate']);
        }

        ProcessRazorpayWebhookJob::dispatch($event->id);

        return response()->json(['status' => 'queued', 'id' => $event->id]);
    }

    /**
     * The gateway order and payment ids named in the event, whichever
     * entity carries them. Refund events name their payment; order events
     * name themselves.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: string|null, 1: string|null}
     */
    private function referencesIn(array $body): array
    {
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

        $payment = $payload['payment']['entity'] ?? null;
        if (is_array($payment)) {
            return [
                isset($payment['order_id']) ? (string) $payment['order_id'] : null,
                isset($payment['id']) ? (string) $payment['id'] : null,
            ];
        }

        $order = $payload['order']['entity'] ?? null;
        if (is_array($order) && isset($order['id'])) {
            return [(string) $order['id'], null];
        }

        $refund = $payload['refund']['entity'] ?? null;
        if (is_array($refund) && isset($refund['payment_id'])) {
            $paymentId = (string) $refund['payment_id'];
            $intent = PaymentIntent::where('gateway_payment_id', $paymentId)->first();

            return [$intent?->gateway_order_id, $paymentId];
        }

        return [null, null];
    }
}
