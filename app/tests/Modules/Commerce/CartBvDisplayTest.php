<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('settings')->updateOrInsert(['key' => 'commerce.storefront.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
});

/** A user (optionally a distributor) with a cart holding one BV-bearing line. */
function cbvUserWithCart(bool $distributor): User
{
    $user = User::create([
        'full_name' => 'Cart BV', 'email' => 'cbv-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);

    $distId = null;
    if ($distributor) {
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
    }

    $customer = Customer::create(['display_name' => 'Cart BV', 'user_id' => $user->id, 'distributor_id' => $distId]);

    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CBV-{$n}", 'slug' => "cbv-{$n}", 'name' => "Cbv {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create(['product_id' => $product->id, 'variant_sku' => "CBV-{$n}-V1", 'name' => 'Default', 'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800, 'inventory_policy' => 'no_track', 'status' => 'active']);
    $cart = Cart::create(['customer_id' => $customer->id, 'anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1, 'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800]);

    return $user->fresh();
}

it('shows per-product BV under the price in the cart for a distributor', function (): void {
    $user = cbvUserWithCart(distributor: true);

    $this->actingAs($user)->get(route('shop.cart'))
        ->assertOk()
        ->assertSee('500 BV')   // per-line BV under the price
        ->assertSee('Total BV'); // and the summary total
});

it('hides BV in the cart from a non-distributor customer', function (): void {
    $user = cbvUserWithCart(distributor: false);

    $this->actingAs($user)->get(route('shop.cart'))
        ->assertOk()
        ->assertDontSee('500 BV')
        ->assertDontSee('Total BV');
});
