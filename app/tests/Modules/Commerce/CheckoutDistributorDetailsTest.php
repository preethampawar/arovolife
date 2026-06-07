<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Backlog #3: the checkout page shows the logged-in distributor's identity in a
 * read-only "Distributor details" block and offers a "Same as distributor"
 * shortcut for the (still editable) customer block. Guests / plain customers
 * see neither — only the editable customer block.
 */
function cddSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

/** Build a cart (with one item) linked to $user's customer so currentCart() resolves it. */
function cddCartFor(User $user, ?int $distributorId): void
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CDD-{$n}", 'slug' => "cdd-{$n}", 'name' => "CDD {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CDD-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);

    $customer = Customer::create(['display_name' => $user->full_name, 'user_id' => $user->id, 'distributor_id' => $distributorId]);
    $cart = Cart::create(['customer_id' => $customer->id, 'anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
    ]);
}

function cddDistributorUser(): User
{
    $user = User::create([
        'full_name' => 'Dolly Distributor', 'email' => 'cdd-dist-'.uniqid().'@test.com',
        'phone_e164' => '+919812345678', 'password_hash' => bcrypt('x'), 'status' => 'active',
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

    return $user->refresh();
}

beforeEach(function (): void {
    cddSetting('commerce.checkout.enabled', 'true');
    cddSetting('commerce.guest_checkout.enabled', 'true');
});

it('CDD-01: a logged-in distributor sees the read-only distributor block + ADN + same-as shortcut', function (): void {
    $user = cddDistributorUser();
    cddCartFor($user, $user->distributor->id);
    $adn = $user->distributor->adn;

    $response = $this->actingAs($user)->get(route('shop.checkout'));

    $response->assertOk();
    $response->assertSee('Distributor details');
    $response->assertSee($adn);
    $response->assertSee('Dolly Distributor');
    $response->assertSee('Same as distributor');
    // The JS shortcut reads the distributor's local mobile from the panel dataset.
    $response->assertSee('data-dist-phone="9812345678"', false);
});

it('CDD-02: a plain customer (no distributor) sees no distributor block or shortcut', function (): void {
    $user = User::create([
        'full_name' => 'Plain Customer', 'email' => 'cdd-plain-'.uniqid().'@test.com',
        'phone_e164' => '+919800000001', 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    cddCartFor($user, null);

    $response = $this->actingAs($user)->get(route('shop.checkout'));

    $response->assertOk();
    $response->assertDontSee('Distributor details');
    $response->assertDontSee('Same as distributor');
    // The editable customer block is still present.
    $response->assertSee('Customer Details');
});
