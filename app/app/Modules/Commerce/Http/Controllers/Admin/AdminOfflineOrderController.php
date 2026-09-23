<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Http\Requests\StoreOfflineOrderRequest;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CustomerAddressService;
use App\Modules\Commerce\Services\OfflineOrderService;
use App\Modules\Commerce\Services\OfflinePaymentProofVault;
use App\Modules\Commerce\Support\Bv;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\Exceptions\InsufficientStockException;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Orders → New offline order, and finance's confirm / reject on the
 * order page. Creating is `commerce.order.manage`; confirming or rejecting
 * the money is `finance.record`, because confirmation creates BV (R-17). The
 * routes carry the permission; the services re-assert it; this controller
 * 404s every action while the feature flag is off.
 */
final class AdminOfflineOrderController extends Controller
{
    public function __construct(
        private readonly OfflineOrderService $offline,
        private readonly PaymentConfirmationService $confirmation,
    ) {}

    /**
     * "Calculate total": the quote for the quantities and delivery on screen,
     * fetched in the background so nothing the operator typed or attached is
     * lost, and nothing but the ADN, quantities and delivery type ever goes
     * into a URL.
     */
    public function quote(Request $request): JsonResponse
    {
        $this->guardFeature();

        $distributor = $this->offline->eligibleDistributor(trim((string) $request->query('adn', '')));
        abort_if($distributor === null, 404);

        $lines = collect((array) $request->query('qty', []))
            ->map(fn ($qty): int => max(0, (int) $qty))
            ->filter(fn (int $qty): bool => $qty > 0)
            ->mapWithKeys(fn (int $qty, $id): array => [(int) $id => $qty])
            ->all();

        try {
            $quote = $this->offline->quote($distributor, $lines, $request->query('delivery_type') === 'collect');
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'subtotal' => IndianNumber::rupees($quote->subtotalPaise),
            'gst' => IndianNumber::rupees($quote->gstPaise),
            'fulfilment' => IndianNumber::rupees($quote->shippingPaise + $quote->collectionFeePaise),
            'bv' => Bv::format($quote->bvPaise),
            'total' => IndianNumber::rupees($quote->totalPaise),
            'amount' => number_format($quote->totalPaise / 100, 2, '.', ''),
        ]);
    }

    public function create(Request $request, CartService $carts, CustomerAddressService $addresses): View
    {
        $this->guardFeature();

        $adn = trim((string) $request->query('adn', ''));
        $distributor = $adn !== '' ? $this->offline->eligibleDistributor($adn) : null;

        $catalogue = collect();
        $centres = collect();
        $prefill = [];

        if ($distributor !== null) {
            $catalogue = ProductVariant::query()
                ->with('product')
                ->where('status', 'active')
                ->whereHas('product', fn ($q) => $q->where('status', 'active'))
                ->orderBy('variant_sku')
                ->get()
                ->map(fn (ProductVariant $v): array => [
                    'id' => $v->id,
                    'sku' => (string) $v->variant_sku,
                    'name' => (string) $v->product->name,
                    'unit_price_paise' => $carts->unitPricePaise($v, $distributor->user),
                    'bv_paise' => (int) $v->bv_paise,
                ]);

            $centres = AreteCenter::collectionChoicesFor($distributor->id);

            $customer = Customer::query()->where('user_id', $distributor->user_id)->first();
            $saved = $customer !== null ? $addresses->forCustomer($customer->id)->first() : null;
            $prefill = [
                'buyer_name' => $saved->name ?? $distributor->user->full_name,
                'buyer_phone' => substr(preg_replace('/\D/', '', (string) ($saved->phone_e164 ?? $distributor->user->phone_e164)) ?? '', -10),
                'ship_line1' => $saved->line1 ?? null,
                'ship_line2' => $saved->line2 ?? null,
                'ship_city' => $saved->city ?? null,
                'ship_state' => $saved->state ?? null,
                'ship_pincode' => $saved->pincode ?? null,
            ];

        }

        return view('admin.commerce.offline-orders.create', [
            'adn' => $adn,
            'distributor' => $distributor,
            'catalogue' => $catalogue,
            'centres' => $centres,
            'prefill' => $prefill,
            'channels' => OfflinePayment::CHANNELS,
            'formToken' => $this->formToken($request),
        ]);
    }

    public function store(StoreOfflineOrderRequest $request): RedirectResponse
    {
        $this->guardFeature();

        $distributor = $this->offline->eligibleDistributor((string) $request->validated('adn'));
        if ($distributor === null) {
            return back()->withErrors(['adn' => 'No active distributor with that ADN.'])->withInput();
        }

        $delivery = $request->delivery();
        if ($delivery['delivery_type'] === 'collect'
            && ! AreteCenter::collectionChoicesFor($distributor->id)->pluck('id')->contains($delivery['arete_center_id'])) {
            return back()->withErrors(['arete_center_id' => 'The selected centre is not available.'])->withInput();
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $order = $this->offline->create(
                $distributor,
                $request->lines(),
                $delivery,
                $request->paymentDetails(),
                $request->file('proof'),
                (string) $request->validated('form_token'),
                $actor,
            );
        } catch (RuntimeException|InsufficientStockException $e) {
            return back()->withErrors(['offline' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.commerce.orders.show', $order)
            ->with('status', "Offline order {$order->order_no} created. It stays Placed until finance confirms the payment.");
    }

    public function confirm(Request $request, Order $order): RedirectResponse
    {
        $this->guardFeature();

        $validated = $request->validate([
            'verified' => ['accepted'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'verified.accepted' => 'Tick to confirm you have checked the money has been received.',
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $result = $this->confirmation->confirmOffline($order, $actor, $validated['note'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['offline' => $e->getMessage()]);
        }

        $message = $result->status === ConfirmationResult::CONFIRMED
            ? 'Payment confirmed. The order is now paid — BV has been recorded and the invoice issued.'
            : 'Already confirmed — nothing changed.';

        return redirect()->route('admin.commerce.orders.show', $order)->with('status', $message);
    }

    public function reject(Request $request, Order $order): RedirectResponse
    {
        $this->guardFeature();

        $validated = $request->validate([
            'money_not_received' => ['accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'money_not_received.accepted' => 'Reject only when no money was received. If it was, confirm the payment and then cancel the order so the refund is owed on the books.',
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->offline->reject($order, $actor, $validated['reason']);
        } catch (RuntimeException $e) {
            return redirect()->route('admin.commerce.orders.show', $order)->withErrors(['offline' => $e->getMessage()]);
        }

        return redirect()->route('admin.commerce.orders.show', $order)
            ->with('status', 'Payment rejected and the order cancelled. Reserved stock released.');
    }

    /** The proof file, decrypted and streamed. Every view is audited. */
    public function proof(Request $request, Order $order, OfflinePaymentProofVault $vault): Response
    {
        $this->guardFeature();

        /** @var User $viewer */
        $viewer = $request->user();
        abort_unless($viewer->canAny(['finance.record', 'commerce.order.manage']), 403);

        $payment = OfflinePayment::query()->where('order_id', $order->id)->first();
        $bytes = $payment !== null ? $vault->read($payment) : null;
        abort_if($payment === null || $bytes === null, 404);

        AuditLog::create([
            'actor_id' => $viewer->id,
            'action' => 'order.offline_proof_viewed',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'before_hash' => AuditLog::digest((string) $payment->proof_sha256),
            'after_hash' => AuditLog::digest((string) $payment->proof_sha256),
            'details' => ['offline_payment_id' => $payment->id],
            'ip' => $request->ip(),
        ]);

        return response($bytes, 200, [
            'Content-Type' => $vault->mimeType($bytes),
            'Content-Disposition' => 'inline; filename="proof-'.$order->order_no.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The idempotency token of this form: carried through a failed POST,
     * minted fresh otherwise.
     */
    private function formToken(Request $request): string
    {
        $carried = old('form_token');

        return is_string($carried) && Str::isUuid($carried) ? $carried : (string) Str::uuid();
    }

    private function guardFeature(): void
    {
        abort_unless($this->offline->enabled(), 404);
    }
}
