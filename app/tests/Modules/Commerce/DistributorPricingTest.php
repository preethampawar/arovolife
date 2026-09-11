<?php

declare(strict_types=1);

/**
 * F55: the product page showed a logged-in Direct Seller a "distributor price"
 * that no cart, order or invoice ever charged — the member was billed the sale
 * price. The client's decision (2026-09-11) is that members pay the distributor
 * price where the catalogue sets one. Guests and MRP are unchanged.
 *
 * F56: COD stays off. `payments.cod.enabled` exists as a staging settings row
 * but nothing in the code has ever read it, and checkout offers online only.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.storefront.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
});

function dpUser(bool $distributor): User
{
    $user = User::create([
        'full_name' => 'DP User',
        'email' => 'dp-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
    ]);

    if (! $distributor) {
        return $user;
    }

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => bin2hex(random_bytes(16)),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return $user->fresh();
}

/** A variant at ₹549 sale / ₹450 distributor, unless $distributorPaise says otherwise. */
function dpVariant(int $distributorPaise = 45000): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "DP-{$n}", 'slug' => "dp-{$n}", 'name' => "DP {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "DP-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 65000, 'sale_price_paise' => 54900, 'distributor_price_paise' => $distributorPaise,
        'bv_paise' => 60000, 'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);

    return $variant;
}

function dpCart(): Cart
{
    return Cart::create(['anonymous_key' => 'dp'.random_int(10000, 99999), 'expires_at' => now()->addDay()]);
}

it('F55-01: a guest pays the sale price', function (): void {
    $variant = dpVariant();
    $item = app(CartService::class)->addItem(dpCart(), $variant->id, 1, null);

    expect($item->unit_price_paise)->toBe(54900);
});

it('F55-02: a logged-in Direct Seller pays the distributor price', function (): void {
    $variant = dpVariant();
    $item = app(CartService::class)->addItem(dpCart(), $variant->id, 1, dpUser(true));

    expect($item->unit_price_paise)->toBe(45000);
});

it('F55-03: a signed-in customer who is not a Direct Seller still pays the sale price', function (): void {
    $variant = dpVariant();
    $item = app(CartService::class)->addItem(dpCart(), $variant->id, 1, dpUser(false));

    expect($item->unit_price_paise)->toBe(54900);
});

it('F55-04: a variant with no distributor price is unaffected', function (): void {
    $variant = dpVariant(distributorPaise: 0);
    $item = app(CartService::class)->addItem(dpCart(), $variant->id, 1, dpUser(true));

    expect($item->unit_price_paise)->toBe(54900);
});

it('F55-05: a cart built as a guest is repriced when the Direct Seller signs in', function (): void {
    $variant = dpVariant();
    $cart = dpCart();
    $item = app(CartService::class)->addItem($cart, $variant->id, 2, null);
    expect($item->unit_price_paise)->toBe(54900);

    // The member signs in and opens the cart — the same path checkout takes.
    $this->actingAs(dpUser(true))
        ->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->get(route('shop.cart'))
        ->assertOk();

    expect((int) CartItem::findOrFail($item->id)->unit_price_paise)->toBe(45000);
});

it('F55-06: the order, its line and its BV follow the price actually charged', function (): void {
    $variant = dpVariant();
    $user = dpUser(true);
    $cart = dpCart();
    app(CartService::class)->addItem($cart, $variant->id, 2, $user);

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'DP', 'email' => 'dp-order-'.uniqid().'@test.com', 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'DP', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, null, null,
    );

    // 2 × ₹450 = ₹900 inclusive of 18% GST → ₹137.29 tax, and BV is untouched.
    expect($order->items->first()->unit_price_paise)->toBe(45000)
        ->and($order->subtotal_paise)->toBe(90000)
        ->and($order->gst_paise)->toBe(13729)
        ->and($order->items->first()->bv_paise)->toBe(60000);
});

it('F56-01: checkout offers online payment only, and no code reads a COD setting', function (): void {
    expect(Order::PAYMENT_ONLINE)->toBe('online')
        ->and(defined(Order::class.'::PAYMENT_COD'))->toBeFalse();

    $grep = shell_exec('grep -rl "payments.cod.enabled" '.escapeshellarg(base_path('app')).' '.escapeshellarg(base_path('database')).' 2>/dev/null');
    expect(trim((string) $grep))->toBe('');
});
