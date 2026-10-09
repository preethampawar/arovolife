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

/**
 * PATCH / DELETE /shop/cart/items/{item} act only on a line in the visitor's
 * own current cart; any other cart's line is a 404 and stays untouched.
 */
function cioUserWithCart(): array
{
    $user = User::create([
        'full_name' => 'Owner '.random_int(1000, 9999),
        'email' => 'cio-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $customer = Customer::create(['display_name' => $user->full_name, 'user_id' => $user->id]);
    $cart = Cart::create(['customer_id' => $customer->id, 'expires_at' => now()->addDays(7)]);

    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CIO-{$n}", 'slug' => "cio-{$n}", 'name' => "Line {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CIO-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    $item = CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 2,
        'unit_price_paise' => 50000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    return [$user, $item];
}

beforeEach(function (): void {
    DB::table('settings')->updateOrInsert(
        ['key' => 'commerce.storefront.enabled'],
        ['value' => 'true', 'version' => 1, 'updated_at' => now()],
    );
});

it('CIO-01: another visitor cannot change the quantity of a line in someone else\'s cart', function (): void {
    [, $victimItem] = cioUserWithCart();
    [$attacker] = cioUserWithCart();

    $this->actingAs($attacker)
        ->patch(route('shop.cart.update', $victimItem), ['qty' => 1])
        ->assertNotFound();

    expect($victimItem->fresh()->qty)->toBe(2);
});

it('CIO-02: another visitor cannot delete a line in someone else\'s cart', function (): void {
    [, $victimItem] = cioUserWithCart();
    [$attacker] = cioUserWithCart();

    $this->actingAs($attacker)
        ->delete(route('shop.cart.remove', $victimItem))
        ->assertNotFound();

    expect($victimItem->fresh())->not->toBeNull();
});

it('CIO-03: a guest with no cart cannot change or delete anyone\'s line', function (): void {
    [, $victimItem] = cioUserWithCart();

    $this->patch(route('shop.cart.update', $victimItem), ['qty' => 1])->assertNotFound();
    $this->delete(route('shop.cart.remove', $victimItem))->assertNotFound();

    expect($victimItem->fresh()?->qty)->toBe(2);
});

it('CIO-04: the owner can still change and delete their own line', function (): void {
    [$owner, $item] = cioUserWithCart();

    $this->actingAs($owner)
        ->patch(route('shop.cart.update', $item), ['qty' => 1])
        ->assertRedirect(route('shop.cart'));
    expect($item->fresh()->qty)->toBe(1);

    $this->actingAs($owner)
        ->delete(route('shop.cart.remove', $item))
        ->assertRedirect(route('shop.cart'));
    expect($item->fresh())->toBeNull();
});
