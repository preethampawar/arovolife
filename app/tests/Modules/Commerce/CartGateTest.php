<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\SharedCart;
use App\Modules\Commerce\Support\CartGate;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Pre-launch cart gate (plan docs/plans/prelaunch-cart-gate-2026-10-09.md):
 * `commerce.cart.enabled` OFF hides Add to Cart and refuses adds, shares and
 * checkout for distributors and guests; every back-office role bypasses it.
 */
function cgtSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

function cgtUser(?string $role = null): User
{
    $user = User::create([
        'full_name' => 'Gate '.random_int(1000, 9999),
        'email' => 'cgt-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    // A Customer row makes the cart resolve by customer_id across requests;
    // without it the cart rides the anonymous cookie, which the test client
    // does not carry from one request to the next.
    Customer::create(['display_name' => $user->full_name, 'user_id' => $user->id]);

    if ($role !== null) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
    }

    return $user;
}

/** A distributor user (raw row, FK checks off — the same shape SharedCartTest uses). */
function cgtDistributorUser(): User
{
    $user = cgtUser();
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => 'AV'.random_int(100000, 999999),
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

/** An active product in an active category (the listing groups by category, so one is required to render a card). */
function cgtVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $category = ProductCategory::query()->firstOrCreate(
        ['slug' => 'cgt-care'],
        ['name' => 'Gate Care', 'sort' => 1, 'status' => 'active'],
    );
    $product = Product::create(['sku' => "CGT-{$n}", 'slug' => "cgt-{$n}", 'name' => "Gate {$n}", 'hsn_code' => '3004', 'status' => 'active', 'category_id' => $category->id]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CGT-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
}

beforeEach(function (): void {
    cgtSetting('commerce.storefront.enabled', 'true');
    cgtSetting('commerce.checkout.enabled', 'true');
});

it('CGT-01: the gate is closed for a guest and a distributor when the setting is false', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');

    $gate = app(CartGate::class);

    expect($gate->isOpenFor(null))->toBeFalse()
        ->and($gate->isOpenFor(cgtUser()))->toBeFalse();
});

it('CGT-02: a missing settings row means OPEN', function (): void {
    DB::table('settings')->where('key', CartGate::SETTING_KEY)->delete();

    expect(app(CartGate::class)->isOpenFor(null))->toBeTrue();
});

it('CGT-03: every back-office role bypasses a closed gate', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');

    foreach (User::STAFF_ROLES as $role) {
        expect(app(CartGate::class)->isOpenFor(cgtUser($role)))->toBeTrue($role.' should bypass');
    }
});

it('CGT-07: closed → the product page shows "Available at launch" instead of Add to Cart', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    $variant = cgtVariant();

    $this->get(route('shop.product', ['slug' => $variant->product->slug]))
        ->assertOk()
        ->assertSee('Available at launch')
        ->assertSee(CartGate::CLOSED_MESSAGE)
        ->assertDontSee('Add to Cart');
});

it('CGT-08: closed → the listing card has no add-to-cart form; staff still get it', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    cgtVariant();

    // `data-add-to-cart>` is the form tag's end; the page's JS selector string
    // `form[data-add-to-cart]` is always present, so match the tag, not the name.
    $this->get(route('shop.index'))->assertOk()->assertDontSee('data-add-to-cart>', false);

    $this->actingAs(cgtUser('admin-operations'))
        ->get(route('shop.index'))->assertOk()->assertSee('data-add-to-cart>', false);
});

it('CGT-09: closed → the cart page hides Proceed to Checkout and the share form, and explains why', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    $variant = cgtVariant();
    $distributor = cgtUser();

    // Build the cart while open, then close the gate.
    cgtSetting(CartGate::SETTING_KEY, 'true');
    $this->actingAs($distributor)->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1]);
    cgtSetting(CartGate::SETTING_KEY, 'false');
    app()->forgetInstance(CartGate::class);

    $this->actingAs($distributor)->get(route('shop.cart'))
        ->assertOk()
        ->assertSee(CartGate::CLOSED_MESSAGE)
        ->assertDontSee('Proceed to Checkout')
        ->assertDontSee(route('shop.cart.share'), false);
});

it('CGT-04: closed → a guest add is refused: redirect with an error, 403 JSON for the AJAX card', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    $variant = cgtVariant();

    $this->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1])
        ->assertRedirect(route('shop.index'))
        ->assertSessionHasErrors(['cart' => CartGate::CLOSED_MESSAGE]);

    $this->postJson(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1])
        ->assertStatus(403)
        ->assertJson(['ok' => false, 'message' => CartGate::CLOSED_MESSAGE]);

    expect(DB::table('cart_items')->count())->toBe(0);
});

it('CGT-05: closed → a staff add still lands in the cart', function (): void {
    cgtSetting(CartGate::SETTING_KEY, 'false');
    $variant = cgtVariant();

    $this->actingAs(cgtUser('admin'))
        ->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1])
        ->assertRedirect(route('shop.cart'))
        ->assertSessionHas('added_variant_id', $variant->id);
});

it('CGT-06: closed → an Easy Purchase link does not load the shared cart; the sharer cannot create one', function (): void {
    $variant = cgtVariant();
    $sharer = cgtDistributorUser();

    // A link shared while open (seeded directly: the router keeps the
    // controller — and the gate it was built with — for the rest of the
    // test, so the share route must first be hit while closed) …
    cgtSetting(CartGate::SETTING_KEY, 'true');
    $this->actingAs($sharer)->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1]);
    $code = 'CGTSHARE01';
    SharedCart::create([
        'code' => $code, 'distributor_id' => $sharer->distributor->id, 'ref_adn' => $sharer->distributor->adn,
        'created_by_user_id' => $sharer->id,
        'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        'expires_at' => now()->addDays(30),
    ]);

    // … then close the gate.
    cgtSetting(CartGate::SETTING_KEY, 'false');
    app()->forgetInstance(CartGate::class);

    $this->get(route('shop.easy-cart', ['code' => $code]))
        ->assertRedirect(route('shop.index'))
        ->assertSessionHasErrors(['share' => CartGate::CLOSED_MESSAGE]);
    expect(DB::table('cart_items')->count())->toBe(1); // only the sharer's own line

    $this->actingAs($sharer)->post(route('shop.cart.share'))
        ->assertRedirect(route('shop.cart'))
        ->assertSessionHasErrors(['share' => CartGate::CLOSED_MESSAGE]);
});

it('CGT-12: closed → quantity cannot go up, but a line can still be reduced, removed and the cart cleared', function (): void {
    $variant = cgtVariant();
    $user = cgtUser();
    cgtSetting(CartGate::SETTING_KEY, 'true');
    $this->actingAs($user)->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 3]);
    $item = CartItem::query()->firstOrFail();
    cgtSetting(CartGate::SETTING_KEY, 'false');
    app()->forgetInstance(CartGate::class);

    $this->actingAs($user)->patch(route('shop.cart.update', $item), ['qty' => 4])
        ->assertRedirect(route('shop.index'))
        ->assertSessionHasErrors(['cart' => CartGate::CLOSED_MESSAGE]);
    expect($item->fresh()->qty)->toBe(3);

    $this->actingAs($user)->patch(route('shop.cart.update', $item), ['qty' => 2])->assertRedirect(route('shop.cart'));
    expect($item->fresh()->qty)->toBe(2);

    $this->actingAs($user)->delete(route('shop.cart.remove', $item))->assertRedirect(route('shop.cart'));
    expect(DB::table('cart_items')->count())->toBe(0);
});
