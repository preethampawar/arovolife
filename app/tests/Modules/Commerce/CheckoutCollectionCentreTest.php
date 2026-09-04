<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterMember;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The checkout collection picker offers the buyer's own Arete centre (chosen
 * at registration or changed from My Profile) and the company default centre
 * — never the full registry — and the server accepts only those two.
 */
beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.guest_checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
});

function cccCentre(string $name, bool $default = false, string $status = AreteCenter::STATUS_ACTIVE): AreteCenter
{
    return AreteCenter::create([
        'name' => $name,
        'city' => 'Hyderabad',
        'state' => 'Telangana',
        'status' => $status,
        'is_company_default' => $default,
    ]);
}

function cccDistributor(): User
{
    $user = User::create([
        'full_name' => 'Collect Distributor',
        'email' => 'dist-'.uniqid().'@ccc.test',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ADN'.random_int(10000, 99999),
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

function cccCart(): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CCC-{$n}", 'slug' => "ccc-{$n}", 'name' => "CCC {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CCC-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => 'ccck'.$n, 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
    ]);

    return $cart;
}

function cccCollectPayload(User $user, int $centreId): array
{
    return [
        'buyer_name' => $user->full_name,
        'buyer_email' => $user->email,
        'buyer_phone' => preg_replace('/^\+91/', '', (string) $user->phone_e164),
        'delivery_type' => 'collect',
        'arete_center_id' => $centreId,
        'payment_method' => 'online',
        'billing_same' => '1',
        'accept_terms' => '1',
    ];
}

it('offers a distributor only their own centre and the company default, preferred first', function (): void {
    $default = cccCentre('Company HQ Centre', default: true);
    $mine = cccCentre('My Preferred Centre');
    cccCentre('Some Other Centre');
    cccCentre('Inactive Centre', status: AreteCenter::STATUS_INACTIVE);

    $user = cccDistributor();
    AreteCenterMember::create([
        'center_id' => $mine->id,
        'distributor_id' => $user->distributor->id,
        'effective_from' => now()->toDateString(),
        'effective_to' => null,
    ]);
    $cart = cccCart();

    $this->actingAs($user)
        ->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->get(route('shop.checkout'))
        ->assertOk()
        ->assertSeeInOrder(['My Preferred Centre', 'Company HQ Centre'])
        ->assertSee('Your centre')
        ->assertDontSee('Some Other Centre')
        ->assertDontSee('Inactive Centre');

    expect(AreteCenter::collectionChoicesFor($user->distributor->id)->pluck('id')->all())
        ->toBe([$mine->id, $default->id]);
});

it('offers a distributor without a centre membership the company default only', function (): void {
    cccCentre('Company HQ Centre', default: true);
    cccCentre('Some Other Centre');

    $user = cccDistributor();
    $cart = cccCart();

    $this->actingAs($user)
        ->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->get(route('shop.checkout'))
        ->assertOk()
        ->assertSee('Company HQ Centre')
        ->assertDontSee('Some Other Centre');
});

it('lists the centre once when the preferred centre is the company default', function (): void {
    $default = cccCentre('Company HQ Centre', default: true);
    $user = cccDistributor();
    AreteCenterMember::create([
        'center_id' => $default->id,
        'distributor_id' => $user->distributor->id,
        'effective_from' => now()->toDateString(),
        'effective_to' => null,
    ]);

    expect(AreteCenter::collectionChoicesFor($user->distributor->id)->pluck('id')->all())
        ->toBe([$default->id]);
});

it('hides the collect option entirely when no centre is on offer', function (): void {
    cccCentre('Some Other Centre');
    $user = cccDistributor();
    $cart = cccCart();

    $this->actingAs($user)
        ->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->get(route('shop.checkout'))
        ->assertOk()
        ->assertDontSee('Collect from Arete Centre')
        ->assertDontSee('Some Other Centre');
});

it('rejects a collection order at a centre that was not offered', function (): void {
    cccCentre('Company HQ Centre', default: true);
    $other = cccCentre('Some Other Centre');
    $user = cccDistributor();
    $cart = cccCart();

    $this->actingAs($user)
        ->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), cccCollectPayload($user, $other->id))
        ->assertRedirect()
        ->assertSessionHasErrors(['arete_center_id' => 'The selected centre is not available.']);
});
