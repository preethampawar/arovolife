<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariant;
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
