<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Build a placed order with two BV-bearing lines, optionally owned by a user. */
function obvOrder(?int $ownerUserId = null): Order
{
    $customer = Customer::create(['display_name' => 'BV Buyer', 'user_id' => $ownerUserId]);
    $order = Order::create([
        'order_no' => 'ORD-BV-'.random_int(1000, 9999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PLACED,
        'subtotal_paise' => 300000, 'gst_paise' => 45762, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 300000,
        'ship_name' => 'BV Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now(), 'idempotency_key' => 'idem-'.uniqid(),
    ]);
    disableTestForeignKeys();
    try {
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => 1,
            'product_name_snapshot' => 'Alpha', 'variant_sku_snapshot' => 'A-1', 'hsn_code_snapshot' => '3004',
            'qty' => 2, 'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
            'taxable_value_paise' => 169492, 'gst_paise' => 30508, 'line_total_paise' => 200000,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => 2,
            'product_name_snapshot' => 'Beta', 'variant_sku_snapshot' => 'B-1', 'hsn_code_snapshot' => '3004',
            'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 40000, 'gst_rate_bp' => 1800,
            'taxable_value_paise' => 84746, 'gst_paise' => 15254, 'line_total_paise' => 100000,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return $order->load('items');
}

function obvDistributor(): User
{
    $user = User::create([
        'full_name' => 'Obv Dist',
        'email' => 'obv-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $user->fresh();
}

it('OrderItem::lineBvPaise multiplies unit BV by quantity', function (): void {
    $order = obvOrder();
    expect($order->items[0]->lineBvPaise())->toBe(100000) // 2 × 500 BV
        ->and($order->items[1]->lineBvPaise())->toBe(40000); // 1 × 400 BV
});

it('Order::bvTotalPaise sums all line BV (single source of truth)', function (): void {
    expect(obvOrder()->bvTotalPaise())->toBe(140000); // 1000 + 400 = 1400 BV
});

it('Cart::bvTotalPaise sums line BV', function (): void {
    $cart = Cart::create(['anonymous_key' => 'bvk'.random_int(1, 99999), 'expires_at' => now()->addDay()]);
    disableTestForeignKeys();
    try {
        CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => 1, 'qty' => 3, 'unit_price_paise' => 100000, 'bv_paise' => 20000, 'gst_rate_bp' => 1800]);
    } finally {
        enableTestForeignKeys();
    }

    expect($cart->load('items')->bvTotalPaise())->toBe(60000); // 3 × 200 BV
});

it('404s the confirmation page for a non-owner distributor (IDOR + hard rule #3)', function (): void {
    $order = obvOrder(); // not owned by anyone in particular

    // A logged-in distributor who is not the buyer must not be able to read
    // another buyer's order (PII) or its BV by guessing the order number.
    $this->actingAs(obvDistributor())
        ->get(route('shop.confirmation', $order->order_no))
        ->assertNotFound();
});

it('shows BV on the confirmation to the owning distributor only', function (): void {
    $owner = obvDistributor();
    $order = obvOrder($owner->id); // owned by this distributor's user

    $this->actingAs($owner)
        ->get(route('shop.confirmation', $order->order_no))
        ->assertOk()
        ->assertSee('Total BV')
        ->assertSee('1,400 BV');
});
