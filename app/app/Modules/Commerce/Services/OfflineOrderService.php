<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\DTOs\OfflineOrderQuote;
use App\Modules\Commerce\Services\DTOs\OfflinePaymentDetails;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\OfflineOrdersFeature;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use RuntimeException;
use Throwable;

/**
 * Orders staff create for a distributor who paid outside the gateway.
 *
 * Creation goes through `CheckoutService::place()` — the same order builder the
 * shop uses — so pricing, BV and GST snapshots, stock reservation, attribution
 * and self-consumption are the shop's own. What differs is only that nothing
 * is on the books yet: the order stays `placed` until finance confirms the
 * money through `PaymentConfirmationService::confirmOffline()`, which is where
 * the sale (BV, Genos BV, invoice) happens.
 */
final class OfflineOrderService
{
    public const REJECT_REASON = 'offline_payment_rejected';

    /** Account statuses the shop lets buy; frozen, terminated and rejected accounts cannot be ordered for. */
    private const BUYER_STATUSES = ['active', 'pending'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
        private readonly ShippingService $shipping,
        private readonly OrderStateMachine $orders,
        private readonly OfflinePaymentProofVault $proofs,
    ) {}

    public function enabled(): bool
    {
        return Feature::for(null)->active(OfflineOrdersFeature::class);
    }

    /** The distributor with this ADN, when they may be ordered for; null otherwise. */
    public function eligibleDistributor(string $adn): ?Distributor
    {
        $distributor = Distributor::query()->with('user')->where('adn', trim($adn))->first();

        if ($distributor === null || $distributor->user === null) {
            return null;
        }

        if ($distributor->status !== 'active' || ! in_array($distributor->user->status, self::BUYER_STATUSES, true)) {
            return null;
        }

        return $distributor;
    }

    /**
     * @param  array<int, int>  $lines  variant id => quantity (≥ 1)
     */
    public function quote(Distributor $distributor, array $lines, bool $isCollection): OfflineOrderQuote
    {
        if ($lines === []) {
            throw new RuntimeException('Add at least one product.');
        }

        $variants = $this->purchasableVariants(array_keys($lines));

        $quoteLines = [];
        $subtotal = 0;
        $gst = 0;
        $bv = 0;

        foreach ($lines as $variantId => $qty) {
            $variant = $variants[$variantId];
            $unit = $this->carts->unitPricePaise($variant, $distributor->user);
            $lineTotal = $qty * $unit;

            $subtotal += $lineTotal;
            // Same split as CheckoutService::place(): prices are GST-inclusive.
            $gst += (int) round($lineTotal * $variant->gst_rate_bp / (10000 + $variant->gst_rate_bp));
            $bv += $qty * (int) $variant->bv_paise;

            $quoteLines[] = [
                'variant_id' => $variant->id,
                'name' => (string) $variant->product->name,
                'sku' => (string) $variant->variant_sku,
                'qty' => $qty,
                'unit_price_paise' => $unit,
                'bv_paise' => (int) $variant->bv_paise,
                'line_total_paise' => $lineTotal,
            ];
        }

        $fees = $this->shipping->feeForOrderPaise($subtotal, $isCollection);

        return new OfflineOrderQuote(
            lines: $quoteLines,
            subtotalPaise: $subtotal,
            gstPaise: $gst,
            shippingPaise: $fees['shipping'],
            collectionFeePaise: $fees['collection'],
            totalPaise: $subtotal + $fees['shipping'] + $fees['collection'],
            bvPaise: $bv,
        );
    }

    /**
     * @param  array<int, int>  $lines  variant id => quantity (≥ 1)
     * @param  array{delivery_type: string, arete_center_id: int|null, name: string, phone: string, line1: string|null, line2: string|null, city: string|null, state: string|null, pincode: string|null}  $delivery
     */
    public function create(
        Distributor $distributor,
        array $lines,
        array $delivery,
        OfflinePaymentDetails $payment,
        ?UploadedFile $proof,
        string $idempotencyKey,
        User $actor,
    ): Order {
        $this->assertEnabled();
        Gate::forUser($actor)->authorize('commerce.order.manage');

        // A resubmitted form (double click, back-and-resubmit) is the same order.
        $existing = OfflinePayment::query()->with('order')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing->order;
        }

        $buyer = $this->eligibleDistributor($distributor->adn)
            ?? throw new RuntimeException('This distributor cannot be ordered for: the account is not active.');

        if ($actor->id === $buyer->user_id) {
            throw new RuntimeException('You cannot record or confirm an offline order for your own distributor account.');
        }

        $reference = OfflinePayment::normaliseReference($payment->referenceNo);
        $this->assertReferenceUnused($payment->channel, $reference);

        $isCollection = $delivery['delivery_type'] === Order::DELIVERY_COLLECT;

        // Checked before anything is written: the amount staff collected must
        // be exactly what the order will charge (user decision D4).
        $quote = $this->quote($buyer, $lines, $isCollection);
        $this->assertAmountMatches($payment->amountPaise, $quote->totalPaise);

        $stored = $proof !== null ? $this->proofs->store($proof, $idempotencyKey) : null;

        try {
            return $this->db->transaction(function () use ($buyer, $lines, $delivery, $payment, $reference, $isCollection, $stored, $idempotencyKey, $actor): Order {
                // Serialises the s.269ST day total per buyer against a
                // concurrent create for the same person.
                Distributor::query()->lockForUpdate()->find($buyer->id);

                if ($payment->channel === OfflinePayment::CHANNEL_CASH) {
                    $this->assertCashWithinDailyLimit($buyer->id, $payment);
                }

                $cart = Cart::create([
                    'anonymous_key' => 'offline-'.$idempotencyKey,
                    'expires_at' => Carbon::now()->addHour(),
                ]);

                // Lines snapshotted exactly as CartService::addItem() writes
                // them, without its silent clamp to available stock: an
                // offline order is refused by place()'s stock check rather
                // than shrunk behind the operator's back.
                $variants = $this->purchasableVariants(array_keys($lines));
                foreach ($lines as $variantId => $qty) {
                    $variant = $variants[$variantId];
                    CartItem::create([
                        'cart_id' => $cart->id,
                        'product_variant_id' => $variant->id,
                        'qty' => $qty,
                        'unit_price_paise' => $this->carts->unitPricePaise($variant, $buyer->user),
                        'bv_paise' => $variant->bv_paise,
                        'gst_rate_bp' => $variant->gst_rate_bp,
                    ]);
                }
                $cart->load('items.variant.product');

                $shipping = [
                    'name' => $delivery['name'],
                    'phone' => $delivery['phone'],
                    'line1' => $isCollection ? null : $delivery['line1'],
                    'line2' => $isCollection ? null : $delivery['line2'],
                    'city' => $isCollection ? null : $delivery['city'],
                    'state' => $isCollection ? null : $delivery['state'],
                    'pincode' => $isCollection ? null : $delivery['pincode'],
                ];

                $order = $this->checkout->place(
                    cart: $cart,
                    buyer: [
                        'name' => $buyer->user->full_name,
                        'email' => $buyer->user->email,
                        'phone' => $buyer->user->phone_e164,
                        'marketing_opt_in' => false,
                    ],
                    shipping: $shipping,
                    billing: $shipping,
                    attributedDistributorId: $buyer->id,
                    attributionSource: 'admin',
                    paymentMethod: Order::PAYMENT_OFFLINE,
                    authUserId: $buyer->user_id,
                    buyerDistributorId: $buyer->id,
                    saveShippingAddress: false,
                    areteCenterId: $isCollection ? $delivery['arete_center_id'] : null,
                    applyRepurchaseCredit: false,
                    actorUserId: $actor->id,
                );

                // Cannot normally differ — quote() and place() share the price
                // tier and the fee — but the order is what the money must match.
                $this->assertAmountMatches($payment->amountPaise, $order->total_paise);

                $offline = OfflinePayment::create(array_merge([
                    'order_id' => $order->id,
                    'distributor_id' => $buyer->id,
                    'channel' => $payment->channel,
                    'channel_other' => $payment->channel === OfflinePayment::CHANNEL_OTHER ? $payment->channelOther : null,
                    'amount_paise' => $payment->amountPaise,
                    'received_on' => $payment->receivedOn->toDateString(),
                    'reference_no' => $reference,
                    'payer_name' => $payment->payerName,
                    'notes' => $payment->notes,
                    'status' => OfflinePayment::STATUS_PENDING,
                    'terms_acknowledged_at' => Carbon::now(),
                    'recorded_by_user_id' => $actor->id,
                    'idempotency_key' => $idempotencyKey,
                ], $stored ?? []));

                AuditLog::create([
                    'actor_id' => $actor->id,
                    'action' => 'order.offline_created',
                    'subject_type' => 'order',
                    'subject_id' => $order->id,
                    'after_hash' => AuditLog::digest(OfflinePayment::STATUS_PENDING),
                    'details' => [
                        'order_no' => $order->order_no,
                        'offline_payment_id' => $offline->id,
                        'distributor_id' => $buyer->id,
                        'channel' => $offline->channel,
                        'amount_paise' => $offline->amount_paise,
                        'received_on' => $offline->received_on->toDateString(),
                        'reference_no' => $offline->reference_no,
                        'has_proof' => $stored !== null,
                        // Recording and confirming by one person is permitted
                        // (D1-B) and reviewed monthly (R-107); flagged at both ends.
                        'same_actor' => false,
                        // A purchase is never a condition of joining (hard rule 1).
                        'within_30_days_of_joining' => $buyer->effective_date->greaterThan(Carbon::now()->subDays(30)),
                    ],
                ]);

                return $order;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->proofs->delete($stored['proof_storage_key']);
            }

            throw $e;
        }
    }

    /**
     * Finance could not find the money: mark the payment rejected and cancel
     * the order. Nothing was ever posted, so nothing is reversed.
     *
     * Only for money that never arrived — the caller asserts it. Money that did
     * arrive is confirmed and then cancelled, so the refund is owed on the
     * books (R-68 worklist) rather than handed back off the record.
     */
    public function reject(Order $order, User $actor, string $reason): void
    {
        $this->assertEnabled();
        Gate::forUser($actor)->authorize('finance.record');

        $this->db->transaction(function () use ($order, $actor, $reason): void {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            /** @var OfflinePayment|null $payment */
            $payment = OfflinePayment::query()->where('order_id', $locked->id)->lockForUpdate()->first();

            if (! $locked->isOffline() || $payment === null) {
                throw new RuntimeException("Order {$locked->order_no} is not an offline order.");
            }
            if ($payment->status !== OfflinePayment::STATUS_PENDING || ! $locked->isAwaitingOfflineConfirmation()) {
                throw new RuntimeException("The payment on {$locked->order_no} is no longer awaiting confirmation.");
            }

            $payment->update([
                'status' => OfflinePayment::STATUS_REJECTED,
                'rejected_by_user_id' => $actor->id,
                'rejected_at' => Carbon::now(),
                'rejection_reason' => $reason,
            ]);

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'order.offline_rejected',
                'subject_type' => 'order',
                'subject_id' => $locked->id,
                'before_hash' => AuditLog::digest(OfflinePayment::STATUS_PENDING),
                'after_hash' => AuditLog::digest(OfflinePayment::STATUS_REJECTED),
                'details' => [
                    'order_no' => $locked->order_no,
                    'offline_payment_id' => $payment->id,
                    'reason' => $reason,
                    'money_received' => false,
                ],
            ]);

            $this->orders->cancel($locked, self::REJECT_REASON, $actor->id);
        });

        $order->refresh();
    }

    /**
     * One deposit cannot fund two orders. Checked at creation and again, under
     * lock, at confirmation.
     */
    public function assertReferenceUnused(string $channel, ?string $reference, ?int $exceptPaymentId = null): void
    {
        if ($reference === null) {
            return;
        }

        $clash = OfflinePayment::query()
            ->with('order:id,order_no')
            ->where('channel', $channel)
            ->where('reference_no', $reference)
            ->where('status', '!=', OfflinePayment::STATUS_REJECTED)
            ->when($exceptPaymentId !== null, fn ($q) => $q->where('id', '!=', $exceptPaymentId))
            ->first();

        if ($clash !== null) {
            throw new RuntimeException("Reference {$reference} is already recorded against order {$clash->order->order_no}.");
        }
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Offline orders are not enabled.');
        }
    }

    private function assertAmountMatches(int $amountPaise, int $totalPaise): void
    {
        if ($amountPaise !== $totalPaise) {
            throw new RuntimeException(sprintf(
                'The order total is %s but the amount received is %s. They must match exactly.',
                IndianNumber::rupees($totalPaise),
                IndianNumber::rupees($amountPaise),
            ));
        }
    }

    /** Income Tax Act s.269ST: under ₹2 lakh in cash from one person in a day. */
    private function assertCashWithinDailyLimit(int $distributorId, OfflinePaymentDetails $payment): void
    {
        $alreadyThatDay = (int) OfflinePayment::query()
            ->where('distributor_id', $distributorId)
            ->where('channel', OfflinePayment::CHANNEL_CASH)
            ->where('status', '!=', OfflinePayment::STATUS_REJECTED)
            ->whereDate('received_on', $payment->receivedOn->toDateString())
            ->sum('amount_paise');

        if ($alreadyThatDay + $payment->amountPaise >= OfflinePayment::CASH_DAILY_LIMIT_PAISE) {
            throw new RuntimeException("Cash of ₹2 lakh or more from one person in a day can't be accepted (Income Tax Act s.269ST). Ask for a bank transfer or UPI instead.");
        }
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, ProductVariant>
     */
    private function purchasableVariants(array $variantIds): array
    {
        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('id', $variantIds)
            ->where('status', 'active')
            ->whereHas('product', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($variantIds as $id) {
            if (! isset($variants[$id])) {
                throw new RuntimeException('One of the selected products is no longer available.');
            }
        }

        return $variants;
    }
}
