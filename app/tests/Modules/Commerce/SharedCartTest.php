<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\SharedCart;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Backlog #2: multi-product "Easy Purchase" — a distributor shares their whole
 * cart via a short code; the recipient opens /shop/easy-cart/{code}, which
 * credits the sharer (30-day attribution) and loads the snapshot into their
 * own cart, re-priced from the live variant.
 */
function sctVariant(int $price = 100000): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "SCT-{$n}", 'slug' => "sct-{$n}", 'name' => "SCT {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "SCT-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => $price, 'sale_price_paise' => $price, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);

    return $variant;
}

function sctDistributorUser(string $adn): User
{
    $user = User::create([
        'full_name' => 'Sharer Distributor', 'email' => 'sct-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
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

    return $user->refresh();
}

/** Build a cart with the given variants linked to the user's customer. */
function sctCartFor(User $user, array $variants): Cart
{
    $customer = Customer::create(['display_name' => $user->full_name, 'user_id' => $user->id]);
    $cart = Cart::create(['customer_id' => $customer->id, 'anonymous_key' => 'k'.random_int(10000, 99999), 'expires_at' => now()->addDay()]);
    foreach ($variants as $v) {
        CartItem::create([
            'cart_id' => $cart->id, 'product_variant_id' => $v->id, 'qty' => 2,
            'unit_price_paise' => $v->sale_price_paise, 'bv_paise' => $v->bv_paise, 'gst_rate_bp' => $v->gst_rate_bp,
        ]);
    }

    return $cart;
}

it('SCT-01: a distributor shares their cart → a SharedCart with all items + ADN', function (): void {
    $adn = 'ADN'.random_int(10000, 99999);
    $user = sctDistributorUser($adn);
    $v1 = sctVariant();
    $v2 = sctVariant();
    sctCartFor($user, [$v1, $v2]);

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.cart.share'))
        ->assertRedirect(route('shop.cart'))
        ->assertSessionHas('shared_cart_url');

    $shared = SharedCart::first();
    expect($shared)->not->toBeNull();
    expect($shared->ref_adn)->toBe($adn);
    expect($shared->items)->toHaveCount(2);
    expect(collect($shared->items)->pluck('variant_id')->all())->toContain($v1->id, $v2->id);
    expect($shared->items[0]['qty'])->toBe(2);
});

it('SCT-02: opening a shared link loads items into a fresh visitor cart + sets the ref cookie', function (): void {
    $adn = 'ADN'.random_int(10000, 99999);
    $sharer = sctDistributorUser($adn);
    $v1 = sctVariant();
    $v2 = sctVariant();

    $shared = SharedCart::create([
        'code' => 'SHARECODE1', 'distributor_id' => $sharer->distributor->id, 'ref_adn' => $adn,
        'created_by_user_id' => $sharer->id,
        'items' => [['variant_id' => $v1->id, 'qty' => 2], ['variant_id' => $v2->id, 'qty' => 1]],
        'expires_at' => now()->addDays(30),
    ]);

    // A fresh, anonymous visitor opens the link.
    $response = $this->get(route('shop.easy-cart', ['code' => 'SHARECODE1']));

    $response->assertRedirect(route('shop.cart'));
    $response->assertSessionHas('status');
    $response->assertCookie(AttributionService::COOKIE_NAME, $adn);

    // Items landed in a cart, re-priced from the live variants.
    $items = CartItem::all();
    expect($items)->toHaveCount(2);
    expect($items->firstWhere('product_variant_id', $v1->id)->qty)->toBe(2);
    expect($items->firstWhere('product_variant_id', $v1->id)->unit_price_paise)->toBe($v1->sale_price_paise);
});

it('SCT-03: an archived variant in the snapshot is skipped on open', function (): void {
    $adn = 'ADN'.random_int(10000, 99999);
    $sharer = sctDistributorUser($adn);
    $live = sctVariant();
    $dead = sctVariant();
    $dead->update(['status' => 'archived']);

    SharedCart::create([
        'code' => 'SHARECODE2', 'distributor_id' => $sharer->distributor->id, 'ref_adn' => $adn,
        'created_by_user_id' => $sharer->id,
        'items' => [['variant_id' => $live->id, 'qty' => 1], ['variant_id' => $dead->id, 'qty' => 1]],
        'expires_at' => now()->addDays(30),
    ]);

    $this->get(route('shop.easy-cart', ['code' => 'SHARECODE2']))->assertRedirect(route('shop.cart'));

    $items = CartItem::all();
    expect($items)->toHaveCount(1);
    expect($items->first()->product_variant_id)->toBe($live->id);
});

it('SCT-04: an expired share link is rejected', function (): void {
    $adn = 'ADN'.random_int(10000, 99999);
    $sharer = sctDistributorUser($adn);
    $v = sctVariant();

    SharedCart::create([
        'code' => 'EXPIRED123', 'distributor_id' => $sharer->distributor->id, 'ref_adn' => $adn,
        'created_by_user_id' => $sharer->id,
        'items' => [['variant_id' => $v->id, 'qty' => 1]],
        'expires_at' => now()->subDay(),
    ]);

    $this->get(route('shop.easy-cart', ['code' => 'EXPIRED123']))
        ->assertRedirect(route('shop.index'))
        ->assertSessionHasErrors('share');

    expect(CartItem::count())->toBe(0);
});

it('SCT-05: a non-distributor cannot create a share link', function (): void {
    $user = User::create([
        'full_name' => 'Plain Customer', 'email' => 'sct-plain-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    sctCartFor($user, [sctVariant()]);

    $this->actingAs($user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.cart.share'))
        ->assertRedirect(route('shop.cart'))
        ->assertSessionHasErrors('share');

    expect(SharedCart::count())->toBe(0);
});
