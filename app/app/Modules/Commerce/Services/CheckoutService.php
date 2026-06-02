<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\CustomerAddress;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
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
    ) {}

    /**
     * @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>  $shipping
     * @param  array<string, mixed>  $billing
     */
    public function place(Cart $cart, array $buyer, array $shipping, array $billing, ?int $attributedDistributorId, string $attributionSource, string $paymentMethod = Order::PAYMENT_ONLINE, ?int $consentId = null, ?int $authUserId = null, ?int $buyerDistributorId = null): Order
    {
        if ($cart->items->isEmpty()) {
            throw new \RuntimeException('Cart is empty.');
        }

        return $this->db->transaction(function () use ($cart, $buyer, $shipping, $billing, $attributedDistributorId, $attributionSource, $paymentMethod, $consentId, $authUserId, $buyerDistributorId) {
            // 1. Find or create the customer
            $emailHash = isset($buyer['email']) && $buyer['email'] !== ''
                ? hash('sha256', strtolower(trim($buyer['email'])))
                : null;

            $customer = null;
            if ($emailHash !== null) {
                $customer = Customer::where('email_hash', $emailHash)->first();
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
                ]);
            }

            // 1a. Link the customer to the authenticated buyer. The customer may
            // have been matched only by email_hash; stamping user_id (and the
            // buyer's own distributor_id) is what lets "My Orders" find these
            // orders and makes self-consumption BV resolve correctly. Only fills
            // blanks — a customer already claimed by someone is never reassigned.
            if ($authUserId !== null && $customer->user_id === null) {
                $customer->update([
                    'user_id' => $authUserId,
                    'distributor_id' => $buyerDistributorId,
                    'claimed_at' => Carbon::now(),
                ]);
            }

            // 1b. Persist shipping + billing addresses on file for the customer
            // (the default of each kind). Billing falls back to shipping when
            // "same as shipping" was chosen.
            $this->saveAddress($customer->id, 'shipping', $shipping);
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
                'self_consumption' => $attributedDistributorId !== null
                    && $customer->distributor_id === $attributedDistributorId,
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
