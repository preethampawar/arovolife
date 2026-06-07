<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Events\OrderPlaced;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\CustomerAddress;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Ledger\Services\LedgerPoster;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Creates an Order from a Cart atomically.
 *
 * - Attribution freezes at this moment.
 * - Inventory is reserved (Phase 2: decremented directly; Phase 3 will split reserve/pick).
 * - Ledger entries: Dr razorpay cash, Cr customer_prepayment.
 * - Per-order cooling-off clock is NOT opened here — it's opened when delivery is marked.
 */
final class CheckoutService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AttributionService $attribution,
        private readonly LedgerPoster $ledger,
        private readonly CouponService $coupons,
        private readonly ShippingService $shipping,
        private readonly CustomerAddressService $addressBook,
    ) {}

    /**
     * @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>  $shipping
     * @param  array<string, mixed>  $billing
     */
    public function place(Cart $cart, array $buyer, array $shipping, array $billing, ?int $attributedDistributorId, string $attributionSource, string $paymentMethod = Order::PAYMENT_ONLINE, ?int $consentId = null, ?int $authUserId = null, ?int $buyerDistributorId = null, bool $saveShippingAddress = true, ?string $shippingLabel = null): Order
    {
        if ($cart->items->isEmpty()) {
            throw new \RuntimeException('Cart is empty.');
        }

        $order = $this->db->transaction(function () use ($cart, $buyer, $shipping, $billing, $attributedDistributorId, $attributionSource, $paymentMethod, $consentId, $authUserId, $buyerDistributorId, $saveShippingAddress, $shippingLabel) {
            // 1. Resolve the customer — IDENTITY FIRST for a logged-in buyer.
            //
            // A logged-in buyer is always resolved to THEIR OWN customer row,
            // keyed on the authenticated user_id. This is what stops an order
            // from attaching to a stranger's customer record just because the
            // buyer typed an email that happens to hash to it — the reported bug
            // where a logged-in distributor's own order showed another person's
            // name ("KP NAIK") in the admin. Email matching is a guest-only
            // convenience and never re-points a row already claimed by someone.
            $emailHash = isset($buyer['email']) && $buyer['email'] !== ''
                ? hash('sha256', strtolower(trim($buyer['email'])))
                : null;

            $customer = null;
            if ($authUserId !== null) {
                $customer = Customer::where('user_id', $authUserId)->first();
            }

            if ($customer === null && $emailHash !== null) {
                $match = Customer::where('email_hash', $emailHash)->first();
                if ($match !== null && ($match->user_id === null || $match->user_id === $authUserId)) {
                    // Unclaimed (a prior guest order) or already mine — safe to reuse.
                    $customer = $match;
                } elseif ($match !== null && $authUserId !== null) {
                    // The email belongs to a DIFFERENT user. Don't reuse their
                    // row, and drop the hash on the new row so it can't collide
                    // on the unique email_hash index (identity is the user_id).
                    $emailHash = null;
                }
            }

            if ($customer === null) {
                $customer = Customer::create([
                    'display_name' => $buyer['name'] ?? 'Guest',
                    'email_hash' => $emailHash,
                    'email_enc' => $buyer['email'] ?? null,
                    'phone_hash' => isset($buyer['phone']) ? hash('sha256', $buyer['phone']) : null,
                    'phone_enc' => $buyer['phone'] ?? null,
                    'phone_last4' => isset($buyer['phone']) ? substr(preg_replace('/\D/', '', $buyer['phone']), -4) : null,
                    'marketing_opt_in' => (bool) ($buyer['marketing_opt_in'] ?? false),
                    // Claim the row for a logged-in buyer up front so it's
                    // unambiguously theirs from creation.
                    'user_id' => $authUserId,
                    'distributor_id' => $authUserId !== null ? $buyerDistributorId : null,
                    'claimed_at' => $authUserId !== null ? Carbon::now() : null,
                ]);
            }

            // 1a. Keep the logged-in buyer's own customer row consistent with
            // this purchase: claim it if unclaimed, link their distributor, and
            // refresh the display name so the admin always sees who actually
            // placed the order (the row may have been a guest/email match with a
            // stale name). Only fills/refreshes the buyer's own row.
            if ($authUserId !== null) {
                $updates = [];
                if ($customer->user_id === null) {
                    $updates['user_id'] = $authUserId;
                    $updates['claimed_at'] = Carbon::now();
                }
                $linkingDistributor = $customer->distributor_id === null && $buyerDistributorId !== null;
                if ($linkingDistributor) {
                    $updates['distributor_id'] = $buyerDistributorId;
                }
                $buyerName = $buyer['name'] ?? null;
                if ($buyerName !== null && $buyerName !== '' && $customer->display_name !== $buyerName) {
                    $updates['display_name'] = $buyerName;
                }

                if ($updates !== []) {
                    $customer->update($updates);
                }

                if ($linkingDistributor) {
                    // Trace the PII-linkage change (a customer row now points at
                    // a distributor account).
                    AuditLog::create([
                        'actor_id' => $authUserId,
                        'action' => 'customer.distributor_backfilled',
                        'subject_type' => 'customer',
                        'subject_id' => $customer->id,
                        'details' => ['distributor_id' => $buyerDistributorId],
                    ]);
                }
            }

            // 1b. Persist the shipping address into the customer's saved-address
            // book (label-aware, de-duped, single-default) so it can be reused
            // next time — unless the buyer opted out at checkout. Billing keeps
            // its single "on file" default and falls back to shipping when
            // "same as shipping" was chosen.
            if ($saveShippingAddress) {
                $this->addressBook->save($customer->id, $shipping, $shippingLabel);
            }
            $this->saveAddress($customer->id, 'billing', ! empty($billing) ? $billing : $shipping);

            // 2. Build the order
            $orderNo = $this->generateOrderNo();
            $idempotencyKey = (string) Str::uuid();

            $subtotalPaise = (int) $cart->subtotalPaise();
            $gstPaise = (int) $cart->gstPaise();

            // Re-validate any attached coupon now that the customer is known
            // (this enforces per-customer limits / window at the moment of
            // sale). A now-invalid coupon is silently dropped. The discount
            // only reduces what the customer pays — BV on order lines is
            // unaffected (compliance: BV stays a function of the SKU sale).
            $discountPaise = 0;
            $appliedCoupon = null;
            $cart->loadMissing('coupon');
            if ($cart->coupon !== null) {
                // lockForUpdate: serialise concurrent checkouts on this coupon
                // so the usage-limit check + increment can't be raced.
                $couponResult = $this->coupons->validate($cart->coupon->code, $cart, $customer, lockForUpdate: true);
                if ($couponResult->ok && $couponResult->coupon !== null) {
                    $discountPaise = $couponResult->discountPaise;
                    $appliedCoupon = $couponResult->coupon;
                }
            }

            // Shipping is a function of the cart's merchandise value (before
            // the coupon), via the single-source ShippingService.
            $shippingPaise = $this->shipping->feePaise($subtotalPaise);

            $totalPaise = max(0, $subtotalPaise - $discountPaise) + $shippingPaise;

            $order = Order::create([
                'order_no' => $orderNo,
                'customer_id' => $customer->id,
                'attributed_distributor_id' => $attributedDistributorId,
                'attribution_source' => $attributionSource,
                'payment_method' => $paymentMethod,
                'status' => Order::STATUS_PLACED,
                // Self-consumption = the attributed distributor is the buyer
                // themselves. Keyed on the authenticated buyer's own distributor
                // id (not the customer row, which may not be fully linked yet), so
                // a logged-in distributor's own purchase always accrues their BV.
                'self_consumption' => $attributedDistributorId !== null
                    && $attributedDistributorId === $buyerDistributorId,
                'subtotal_paise' => $subtotalPaise,
                'gst_paise' => $gstPaise,
                'discount_paise' => $discountPaise,
                'shipping_paise' => $shippingPaise,
                'total_paise' => $totalPaise,
                'ship_name' => $shipping['name'],
                'ship_phone_e164' => $shipping['phone'],
                'ship_line1' => $shipping['line1'],
                'ship_line2' => $shipping['line2'] ?? null,
                'ship_city' => $shipping['city'],
                'ship_state' => $shipping['state'],
                'ship_pincode' => $shipping['pincode'],
                'placed_at' => Carbon::now(),
                'idempotency_key' => $idempotencyKey,
                'tnc_of_sale_consent_id' => $consentId,
            ]);

            // 3. Copy cart items → order items with snapshot values
            foreach ($cart->items as $ci) {
                $variant = $ci->variant;
                $lineTotal = $ci->qty * $ci->unit_price_paise;
                $gstPart = (int) round($lineTotal * $ci->gst_rate_bp / (10000 + $ci->gst_rate_bp));
                $taxable = $lineTotal - $gstPart;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $variant->id,
                    'product_name_snapshot' => $variant->product->name,
                    'variant_sku_snapshot' => $variant->variant_sku,
                    'hsn_code_snapshot' => $variant->product->hsn_code,
                    'qty' => $ci->qty,
                    'unit_price_paise' => $ci->unit_price_paise,
                    'bv_paise' => $ci->bv_paise,
                    'gst_rate_bp' => $ci->gst_rate_bp,
                    'taxable_value_paise' => $taxable,
                    'gst_paise' => $gstPart,
                    'line_total_paise' => $lineTotal,
                ]);

                // Reserve inventory (simple decrement for Phase 2)
                if ($variant->inventory_policy === 'track') {
                    $variant->inventory?->increment('reserved', $ci->qty);
                }
            }

            // 3b. Record the coupon redemption (usage tracking + per-customer caps)
            if ($appliedCoupon !== null) {
                $this->coupons->recordRedemption($appliedCoupon, $order->id, $customer->id, $discountPaise);
            }

            // 4. Ledger — ONLINE only: Dr razorpay cash (money held), Cr
            // customer_prepayment (we owe the customer the product). For COD no
            // cash has been received at placement, so no entry is posted now;
            // the cash-in is posted when the COD payment is marked collected
            // (OrderStateMachine::markPaid), keeping the ledger balanced and the
            // revenue-recognition-on-ship step correct for both methods.
            if ($paymentMethod === Order::PAYMENT_ONLINE && $totalPaise > 0) {
                $this->ledger->transfer(
                    sourceModule: 'Commerce',
                    sourceType: 'order.placed',
                    sourceId: $order->id,
                    idempotencyKey: "order.placed:{$order->id}",
                    debitAccount: 'asset.cash.gateway.razorpay',
                    creditAccount: 'liability.customer_prepayment',
                    amountPaise: $totalPaise,
                    memo: "Order {$orderNo}",
                );
            }

            // 5. Clear the cart
            $cart->items()->delete();
            $cart->delete();

            return $order->fresh(['items', 'customer']);
        });

        // Dispatch AFTER the transaction commits so the queued listener never
        // sees a rolled-back order (the order-received email + any SMS later).
        event(new OrderPlaced($order->id));

        return $order;
    }

    /**
     * Upsert the customer's default address of a given kind (shipping|billing).
     * Kept as the single "on file" default per kind; future saved-address
     * reuse reads these.
     *
     * @param  array<string, mixed>  $a
     */
    private function saveAddress(int $customerId, string $kind, array $a): void
    {
        if (empty($a['line1'] ?? null)) {
            return;
        }

        CustomerAddress::updateOrCreate(
            ['customer_id' => $customerId, 'kind' => $kind, 'is_default' => true],
            [
                'name' => $a['name'] ?? '',
                'phone_e164' => $a['phone'] ?? '',
                'line1' => $a['line1'],
                'line2' => $a['line2'] ?? null,
                'city' => $a['city'] ?? '',
                'state' => $a['state'] ?? '',
                'pincode' => $a['pincode'] ?? '',
                'country' => 'IN',
            ],
        );
    }

    private function generateOrderNo(): string
    {
        $date = Carbon::now()->format('ymd');
        $seq = (int) (Carbon::now()->timestamp % 100000);

        return sprintf('ORD-%s-%05d', $date, $seq);
    }
}
