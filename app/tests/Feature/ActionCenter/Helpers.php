<?php

declare(strict_types=1);

namespace Tests\Feature\ActionCenter;

use App\Modules\Commerce\Models\Order;
use Carbon\CarbonInterface;

/**
 * Shared fixtures for the Action Center feature tests. The orders here carry
 * only the columns the providers read; nothing in this module writes them.
 */
final class Helpers
{
    public static function paidOrder(CarbonInterface $paidAt, ?CarbonInterface $packedAt = null, string $status = Order::STATUS_PAID): Order
    {
        $n = random_int(100000, 999999);

        return Order::create([
            'order_no' => "ORD-AC-{$n}",
            'customer_id' => 1,
            'attribution_source' => 'direct',
            'payment_method' => Order::PAYMENT_ONLINE,
            'status' => $status,
            'subtotal_paise' => 100000, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
            'total_paise' => 100000,
            'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
            'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
            'placed_at' => $paidAt, 'paid_at' => $paidAt, 'packed_at' => $packedAt,
            'idempotency_key' => "ac-{$n}-".uniqid(),
        ]);
    }
}
