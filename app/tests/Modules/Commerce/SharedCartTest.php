<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\SharedCart;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
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

/**
 * Members-only buying + Easy Purchase: a shared cart link is the guest's pass
 * through the members-only checkout gate. Without it (members-only ON), a guest
 * is sent to login. The order is still attributed to the sharing distributor.
 */
function sctSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
}

/** A guest cart holding one shared item, resolvable via the anon cookie. */
function sctGuestCart(ProductVariant $v): Cart
{
    $cart = Cart::create(['anonymous_key' => 'k'.random_int(10000, 99999), 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $v->id, 'qty' => 1,
        'unit_price_paise' => $v->sale_price_paise, 'bv_paise' => $v->bv_paise, 'gst_rate_bp' => $v->gst_rate_bp,
    ]);

    return $cart;
}

it('SCT-06: a shared-cart guest passes the members-only gate and sees the sharing distributor (ADN + name only)', function (): void {
    sctSetting('commerce.checkout.enabled', 'true');
    sctSetting('commerce.guest_checkout.enabled', 'false'); // members-only globally

    $adn = 'ADN'.random_int(10000, 99999);
    $sharer = sctDistributorUser($adn); // name "Sharer Distributor"
    $cart = sctGuestCart(sctVariant());

    // The session pass is what openShared() sets when a valid link is opened.
    // withCookie() (encrypted, as the app expects) so currentCart() resolves it.
    $res = $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withSession([SharedCart::SESSION_DISTRIBUTOR_KEY => $sharer->distributor->id])
        ->get(route('shop.checkout'));

    // NOT redirected to login; the page renders with the sharing distributor
    // shown read-only.
    $res->assertOk();
    $res->assertSee($adn);
    $res->assertSee('Sharer Distributor');
    $res->assertSee('Purchasing through');
    $res->assertDontSee($sharer->email);        // distributor contact PII NOT exposed
    $res->assertDontSee('Same as distributor'); // no self-consumption autofill for a guest
});

it('SCT-07: the bypass is scoped — a guest with a cart but no shared-cart pass is still sent to login', function (): void {
    sctSetting('commerce.checkout.enabled', 'true');
    sctSetting('commerce.guest_checkout.enabled', 'false');

    $cart = sctGuestCart(sctVariant());

    $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->get(route('shop.checkout'))
        ->assertRedirect(route('login'));
});

it('SCT-08: an order placed by a shared-cart guest is attributed to the sharing distributor', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    sctSetting('commerce.checkout.enabled', 'true');
    sctSetting('commerce.guest_checkout.enabled', 'false');

    $adn = 'ADN'.random_int(10000, 99999);
    $sharer = sctDistributorUser($adn);
    // resolveForCheckout() only credits an active/pending distributor by ADN.
    DB::table('distributors')->where('id', $sharer->distributor->id)->update(['status' => 'active']);
    $cart = sctGuestCart(sctVariant());

    // Guest fills their OWN details and places a COD order — no login required.
    // The session pass + the av_ref cookie are what openShared() establishes.
    $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withCookie(AttributionService::COOKIE_NAME, $adn)
        ->withSession([SharedCart::SESSION_DISTRIBUTOR_KEY => $sharer->distributor->id])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), [
            'buyer_name' => 'Guest Customer',
            'buyer_email' => 'guest-'.uniqid().'@test.com',
            'buyer_phone' => '9800000000',
            'ship_line1' => '1 Test St',
            'ship_city' => 'Pune',
            'ship_state' => 'MH',
            'ship_pincode' => '411001',
            'payment_method' => 'online',
            'billing_same' => '1',
            'accept_terms' => '1',
        ])->assertRedirect();

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull();
    expect($order->attributed_distributor_id)->toBe($sharer->distributor->id); // credited to the sharer
    expect($order->self_consumption)->toBeFalse();                             // a customer bought, not the distributor
});

it('SCT-09: placing the shared-cart order clears the guest pass (gate re-closes for later self-built carts)', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    sctSetting('commerce.checkout.enabled', 'true');
    sctSetting('commerce.guest_checkout.enabled', 'false');

    $adn = 'ADN'.random_int(10000, 99999);
    $sharer = sctDistributorUser($adn);
    DB::table('distributors')->where('id', $sharer->distributor->id)->update(['status' => 'active']);
    $cart = sctGuestCart(sctVariant());

    $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withCookie(AttributionService::COOKIE_NAME, $adn)
        ->withSession([SharedCart::SESSION_DISTRIBUTOR_KEY => $sharer->distributor->id])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), [
            'buyer_name' => 'Guest Customer',
            'buyer_email' => 'guest-'.uniqid().'@test.com',
            'buyer_phone' => '9800000000',
            'ship_line1' => '1 Test St',
            'ship_city' => 'Pune',
            'ship_state' => 'MH',
            'ship_pincode' => '411001',
            'payment_method' => 'online',
            'billing_same' => '1',
            'accept_terms' => '1',
        ])
        ->assertRedirect()
        ->assertSessionMissing(SharedCart::SESSION_DISTRIBUTOR_KEY); // pass consumed
});
