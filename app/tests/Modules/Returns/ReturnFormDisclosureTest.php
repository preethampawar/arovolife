<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The buyer's return form must state the buy-back deduction for every reason,
 * not only the window: GST is refunded on some reasons and withheld on others,
 * and shipping comes back only on a cooling-off cancellation (QA F100).
 *
 * Fixture order: taxable ₹1,000 + GST ₹180 + shipping ₹50 = ₹1,230.
 */
function rfdOrder(): array
{
    $user = User::create([
        'full_name' => 'RFD User',
        'email' => 'rfd-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'display_name' => 'RFD Customer',
        'user_id' => $user->id,
        'email_hash' => hash('sha256', $user->email),
        'email_enc' => $user->email,
        'claimed_at' => now(),
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-RFD-'.rand(10000, 99999),
        'customer_id' => $customer->id,
        'payment_method' => 'online',
        'status' => Order::STATUS_DELIVERED,
        'self_consumption' => false,
        'subtotal_paise' => 118000,
        'gst_paise' => 18000,
        'discount_paise' => 0,
        'shipping_paise' => 5000,
        'total_paise' => 123000,
        'ship_name' => 'RFD', 'ship_phone_e164' => '+919000000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDays(10),
        'delivered_at' => now()->subDays(5),
        'idempotency_key' => 'test-rfd-'.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    OrderCoolingOff::create([
        'order_id' => $orderId,
        'opened_at' => now()->subDays(5),
        'ends_at' => now()->addDays(25),
        'status' => OrderCoolingOff::STATUS_OPEN,
    ]);

    return [$user, Order::findOrFail($orderId)];
}

it('F100-01: shows the full refund, including GST and shipping, for a cooling-off cancellation', function (): void {
    [$user, $order] = rfdOrder();

    $this->actingAs($user)->get(route('orders.return.create', $order->order_no))
        ->assertOk()
        ->assertSee('What each reason refunds on this order')
        ->assertSee('₹1,230.00');
});

it('F100-02: states that GST is withheld and issued as a voucher where the matrix says so', function (): void {
    [$user, $order] = rfdOrder();

    $this->actingAs($user)->get(route('orders.return.create', $order->order_no))
        ->assertOk()
        // General / termination buyback: DS Price less GST, no shipping → ₹1,000.
        ->assertSee('₹1,000.00')
        ->assertSee('buyback voucher, not a credit note')
        // Damage / dissatisfaction, saleable: price incl. GST, no shipping → ₹1,180.
        ->assertSee('₹1,180.00');
});

it('F100-03: says plainly that shipping is refunded on cooling-off only', function (): void {
    [$user, $order] = rfdOrder();

    $html = $this->actingAs($user)->get(route('orders.return.create', $order->order_no))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'Shipping of ₹50.00 is refunded.'))->toBe(1)
        ->and(substr_count($html, 'Shipping of ₹50.00 is <strong>not</strong> refunded.'))->toBe(4);
});

it('F102-01: says plainly that a return covers the whole order', function (): void {
    [$user, $order] = rfdOrder();

    $this->actingAs($user)->get(route('orders.return.create', $order->order_no))
        ->assertOk()
        ->assertSee('A return covers the whole order', escape: false)
        ->assertSee('partial returns are handled by customer care', escape: false);
});
