<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Backlog #4: the printable order invoice is in the CUSTOMER's name (buyer) and
 * also shows the attributed DISTRIBUTOR's name + ADN (e.g. for an Easy Purchase
 * / shared-link sale). BV is never shown — it's a customer-facing tax document
 * (hard rule #3). Access is owner-only (IDOR-guarded like the order page).
 */
function oinvUser(string $name): User
{
    return User::create([
        'full_name' => $name, 'email' => 'oinv-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
}

function oinvDistributor(string $adn, string $fullName): int
{
    $user = oinvUser($fullName);
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => $adn,
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

    return $id;
}

/** An order owned by $buyer, attributed to $distId (the sharer). */
function oinvOrder(User $buyer, ?int $distId): Order
{
    $customer = Customer::create(['user_id' => $buyer->id, 'display_name' => 'Buyer Customer', 'distributor_id' => null]);
    $order = Order::create([
        'order_no' => 'ORD-INV-'.random_int(10000, 99999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $distId,
        'attribution_source' => $distId !== null ? 'cookie' : 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_DELIVERED,
        'self_consumption' => false,
        'subtotal_paise' => 100000, 'gst_paise' => 15254, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 100000,
        'ship_name' => 'Buyer Customer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now()->subDays(3), 'delivered_at' => now()->subDay(), 'idempotency_key' => 'idem-'.uniqid(),
    ]);
    disableTestForeignKeys();
    try {
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => 1,
            'product_name_snapshot' => 'Wellness Tonic', 'variant_sku_snapshot' => 'WT-1', 'hsn_code_snapshot' => '3004',
            'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
            'taxable_value_paise' => 84746, 'gst_paise' => 15254, 'line_total_paise' => 100000,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return $order;
}

it('INV-01: the owner sees their invoice — customer name, order no, item, total', function (): void {
    $buyer = oinvUser('Buyer Customer');
    $order = oinvOrder($buyer, null);

    $response = $this->actingAs($buyer)->get(route('orders.invoice', $order->order_no));

    $response->assertOk();
    $response->assertSee($order->order_no);
    $response->assertSee('Buyer Customer');
    $response->assertSee('Billed to');
    $response->assertSee('Wellness Tonic');
    $response->assertSee('₹1,000.00');
});

it('INV-02: an attributed-link invoice shows the distributor name + ADN', function (): void {
    $buyer = oinvUser('Buyer Customer');
    $distId = oinvDistributor('900900900', 'Sharer Distributor');
    $order = oinvOrder($buyer, $distId);

    $response = $this->actingAs($buyer)->get(route('orders.invoice', $order->order_no));

    $response->assertOk();
    $response->assertSee('Your arovolife distributor', false);
    $response->assertSee('Sharer Distributor');
    $response->assertSee('900900900');
});

it('INV-03: a direct order (no attribution) shows no distributor block', function (): void {
    $buyer = oinvUser('Buyer Customer');
    $order = oinvOrder($buyer, null);

    $this->actingAs($buyer)->get(route('orders.invoice', $order->order_no))
        ->assertOk()
        ->assertDontSee('Your arovolife distributor', false);
});

it('INV-04: the invoice never shows BV (customer-facing tax document, hard rule #3)', function (): void {
    $buyer = oinvUser('Buyer Customer');
    $distId = oinvDistributor('900900901', 'Sharer Distributor');
    $order = oinvOrder($buyer, $distId);

    $this->actingAs($buyer)->get(route('orders.invoice', $order->order_no))
        ->assertOk()
        ->assertDontSee('BV');
});

it('INV-05: a non-owner cannot view someone else\'s invoice (IDOR)', function (): void {
    $buyer = oinvUser('Buyer Customer');
    $order = oinvOrder($buyer, null);
    $stranger = oinvUser('Nosey Stranger');

    $this->actingAs($stranger)->get(route('orders.invoice', $order->order_no))->assertNotFound();
});
