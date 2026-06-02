<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function moUserWithDistributor(): array
{
    $user = User::create([
        'full_name' => 'MO User', 'email' => 'mo-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    disableTestForeignKeys();
    try {
        $distId = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0, 'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $distId)->update(['sponsor_id' => $distId, 'placement_parent_id' => $distId]);
    } finally {
        enableTestForeignKeys();
    }

    return [$user->fresh(), $distId];
}

/** An order owned by $userId (customer claimed by them), optionally self-consumption with a BV line. */
function moOrder(int $userId, ?int $distId = null, bool $self = false): Order
{
    $customer = Customer::firstOrCreate(['user_id' => $userId], ['display_name' => 'MO', 'distributor_id' => $distId]);
    $order = Order::create([
        'order_no' => 'ORD-MO-'.random_int(10000, 99999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $self ? $distId : null,
        'attribution_source' => $self ? 'logged_in' : 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_DELIVERED,
        'self_consumption' => $self,
        'subtotal_paise' => 100000, 'gst_paise' => 15254, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 100000,
        'ship_name' => 'MO', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now()->subDays(5), 'delivered_at' => now()->subDays(2), 'idempotency_key' => 'idem-'.uniqid(),
    ]);
    disableTestForeignKeys();
    try {
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => 1,
            'product_name_snapshot' => 'Widget', 'variant_sku_snapshot' => 'W-1', 'hsn_code_snapshot' => '3004',
            'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
            'taxable_value_paise' => 84746, 'gst_paise' => 15254, 'line_total_paise' => 100000,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    return $order->load('items', 'coolingOff', 'bvLedgerEntries');
}

it('checkout links the customer to the logged-in buyer (user_id + distributor + claimed)', function (): void {
    [$user, $distId] = moUserWithDistributor();

    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "MO-{$n}", 'slug' => "mo-{$n}", 'name' => "MO {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create(['product_id' => $product->id, 'variant_sku' => "MO-{$n}-V1", 'name' => 'Default', 'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active']);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800]);

    $buyer = ['name' => 'MO User', 'email' => 'mo-buyer-'.uniqid().'@test.com', 'phone' => '+919800000000', 'marketing_opt_in' => false];
    $addr = ['name' => 'MO User', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'];

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'), $buyer, $addr, $addr,
        attributedDistributorId: $distId, attributionSource: 'logged_in',
        paymentMethod: Order::PAYMENT_ONLINE, consentId: null,
        authUserId: $user->id, buyerDistributorId: $distId,
    );

    $customer = $order->customer;
    expect($customer->user_id)->toBe($user->id)
        ->and($customer->distributor_id)->toBe($distId)
        ->and($customer->claimed_at)->not->toBeNull()
        ->and($order->self_consumption)->toBeTrue();
});

it('lists only the authenticated distributor\'s own orders', function (): void {
    [$userA] = moUserWithDistributor();
    [$userB] = moUserWithDistributor();
    $mine = moOrder($userA->id);
    $theirs = moOrder($userB->id);

    $this->actingAs($userA)->get(route('orders.index'))
        ->assertOk()
        ->assertSee($mine->order_no)
        ->assertDontSee($theirs->order_no);
});

it('404s when viewing an order that is not yours', function (): void {
    [$userA] = moUserWithDistributor();
    [$userB] = moUserWithDistributor();
    $theirs = moOrder($userB->id);

    $this->actingAs($userA)->get(route('orders.show', $theirs->order_no))->assertNotFound();
});

it('shows BV total and the in-cooling-off status on the order detail', function (): void {
    [$user, $distId] = moUserWithDistributor();
    $order = moOrder($user->id, $distId, self: true);
    OrderCoolingOff::create(['order_id' => $order->id, 'opened_at' => now()->subDays(2), 'ends_at' => now()->addDays(28), 'status' => OrderCoolingOff::STATUS_OPEN]);

    $this->actingAs($user)->get(route('orders.show', $order->order_no))
        ->assertOk()
        ->assertSee('500 BV')
        ->assertSee('In cooling-off');
});

it('personalBvStatus transitions none → pending → accumulated → reversed', function (): void {
    [$user, $distId] = moUserWithDistributor();

    // none: not self-consumption
    expect(moOrder($user->id, $distId, self: false)->personalBvStatus()['state'])->toBe('none');

    // pending: self-consumption, cooling-off open, no ledger entry
    $order = moOrder($user->id, $distId, self: true);
    OrderCoolingOff::create(['order_id' => $order->id, 'opened_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'status' => OrderCoolingOff::STATUS_OPEN]);
    expect($order->load('coolingOff', 'bvLedgerEntries')->personalBvStatus()['state'])->toBe('pending');

    // accumulated: an accrual entry exists
    BvLedgerEntry::create(['distributor_id' => $distId, 'order_id' => $order->id, 'bv_paise' => 50000, 'type' => 'accrual', 'effective_at' => now()]);
    expect($order->load('bvLedgerEntries')->personalBvStatus()['state'])->toBe('accumulated');

    // reversed: a reversal entry exists
    BvLedgerEntry::create(['distributor_id' => $distId, 'order_id' => $order->id, 'bv_paise' => -50000, 'type' => 'reversal', 'effective_at' => now()]);
    expect($order->load('bvLedgerEntries')->personalBvStatus()['state'])->toBe('reversed');
});
