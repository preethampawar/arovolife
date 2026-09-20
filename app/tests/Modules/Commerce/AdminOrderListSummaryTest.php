<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** One placed order with a single BV-bearing line, at the given moment. */
function aolOrder(string $status, int $totalPaise, int $bvPaise, Carbon $placedAt): Order
{
    $customer = Customer::create(['display_name' => 'Summary Buyer']);
    $order = Order::create([
        'order_no' => 'ORD-SUM-'.random_int(10000, 99999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => $status,
        'subtotal_paise' => $totalPaise, 'gst_paise' => 0, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => $totalPaise,
        'ship_name' => 'Summary Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => $placedAt, 'idempotency_key' => 'idem-'.uniqid(),
    ]);

    disableTestForeignKeys();
    try {
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => 1,
            'product_name_snapshot' => 'Alpha', 'variant_sku_snapshot' => 'A-1', 'hsn_code_snapshot' => '3004',
            'qty' => 1, 'unit_price_paise' => $totalPaise, 'bv_paise' => $bvPaise, 'gst_rate_bp' => 0,
            'taxable_value_paise' => $totalPaise, 'gst_paise' => 0, 'line_total_paise' => $totalPaise,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return $order;
}

function aolAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $admin = User::create([
        'full_name' => 'Orders Admin',
        'email' => 'aol-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('opens on today, leaving an older order out of both the list and the tiles', function (): void {
    $today = aolOrder(Order::STATUS_PLACED, 300000, 140000, now());
    $older = aolOrder(Order::STATUS_PLACED, 500000, 100000, now()->subDays(3));

    $this->actingAs(aolAdmin())
        ->get(route('admin.commerce.orders.index'))
        ->assertOk()
        ->assertSee($today->order_no)
        ->assertDontSee($older->order_no)
        ->assertSee('Showing orders placed today.')
        // The tiles count the window, not the table: 1 order, 1,400 BV.
        ->assertSee('1,400 BV')
        ->assertDontSee('2,400 BV');
});

it('widens to every date when the range is explicitly cleared, and the tiles follow', function (): void {
    aolOrder(Order::STATUS_PLACED, 300000, 140000, now());
    $older = aolOrder(Order::STATUS_PLACED, 500000, 100000, now()->subDays(3));

    // An emptied date pair is what pressing Filter with both inputs cleared
    // submits, and it is how a viewer asks for all time.
    $this->actingAs(aolAdmin())
        ->get(route('admin.commerce.orders.index', ['placed_from' => '', 'placed_to' => '']))
        ->assertOk()
        ->assertSee($older->order_no)
        ->assertSee('₹8,000.00')   // 3,000 + 5,000, a figure no single row shows
        ->assertSee('2,400 BV');
});

it('moves the tiles with the status chip, and does not clip a status link to today', function (): void {
    aolOrder(Order::STATUS_PLACED, 300000, 140000, now());
    // Older than the default window: a link that names a status — the
    // dashboard's pipeline tiles, which count all time — must show it.
    $cancelled = aolOrder(Order::STATUS_CANCELLED, 700000, 220000, now()->subDays(3));

    $this->actingAs(aolAdmin())
        ->get(route('admin.commerce.orders.index', ['status' => Order::STATUS_CANCELLED]))
        ->assertOk()
        ->assertSee($cancelled->order_no)
        ->assertDontSee('Showing orders placed today.')
        ->assertSee('2,200 BV')
        ->assertDontSee('1,400 BV');
});
