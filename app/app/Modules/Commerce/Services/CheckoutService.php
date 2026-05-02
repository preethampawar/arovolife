<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Customer;
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
    ) {}

    /**
     * @param  array<string, mixed>  $shipping
     * @param  array<string, mixed>  $buyer
     */
    public function place(Cart $cart, array $buyer, array $shipping, ?int $attributedDistributorId, string $attributionSource, ?int $consentId = null): Order
    {
        if ($cart->items->isEmpty()) {
            throw new \RuntimeException('Cart is empty.');
        }

        return $this->db->transaction(function () use ($cart, $buyer, $shipping, $attributedDistributorId, $attributionSource, $consentId) {
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

            // 2. Build the order
            $orderNo = $this->generateOrderNo();
            $idempotencyKey = (string) Str::uuid();

            $subtotalPaise = (int) $cart->subtotalPaise();
            $gstPaise = (int) $cart->gstPaise();
            $totalPaise = $subtotalPaise;

            $order = Order::create([
                'order_no' => $orderNo,
                'customer_id' => $customer->id,
                'attributed_distributor_id' => $attributedDistributorId,
                'attribution_source' => $attributionSource,
                'status' => Order::STATUS_PLACED,
                'self_consumption' => $attributedDistributorId !== null
                    && $customer->distributor_id === $attributedDistributorId,
                'subtotal_paise' => $subtotalPaise,
                'gst_paise' => $gstPaise,
                'discount_paise' => 0,
                'shipping_paise' => 0,
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
                    'pv_paise' => $ci->pv_paise,
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

            // 4. Ledger: Dr razorpay cash (money held), Cr customer_prepayment (we owe customer the product)
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

            // 5. Clear the cart
            $cart->items()->delete();
            $cart->delete();

            return $order->fresh(['items', 'customer']);
        });
    }

    private function generateOrderNo(): string
    {
        $date = Carbon::now()->format('ymd');
        $seq = (int) (Carbon::now()->timestamp % 100000);

        return sprintf('ORD-%s-%05d', $date, $seq);
    }
}
