<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function cqtItem(int $qty = 2): CartItem
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CQT-{$n}", 'slug' => "cqt-{$n}", 'name' => "Qty {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CQT-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);

    return CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty,
        'unit_price_paise' => 50000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);
}

it('itemCount sums the quantities of the anonymous cart (for the nav badge)', function (): void {
    $item = cqtItem(2); // line A, qty 2
    // line B (a different product/variant), qty 1 → total 3 units
    $n = random_int(10000, 99999);
    $p2 = Product::create(['sku' => "CQT2-{$n}", 'slug' => "cqt2-{$n}", 'name' => "Qty2 {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $v2 = ProductVariant::create(['product_id' => $p2->id, 'variant_sku' => "CQT2-{$n}-V1", 'name' => 'Default', 'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'bv_paise' => 0, 'gst_rate_bp' => 1800, 'inventory_policy' => 'no_track', 'status' => 'active']);
    CartItem::create(['cart_id' => $item->cart_id, 'product_variant_id' => $v2->id, 'qty' => 1, 'unit_price_paise' => 50000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    $request = Request::create('/');
    $request->cookies->set(AttributionService::ANON_COOKIE, $item->cart->anonymous_key);

    expect(app(CartService::class)->itemCount($request))->toBe(3); // 2 + 1
});

it('itemCount is 0 when there is no cart and never creates one', function (): void {
    expect(app(CartService::class)->itemCount(Request::create('/')))->toBe(0);
    expect(Cart::count())->toBe(0);
});

it('itemCount finds the anonymous cart of a logged-in visitor with no Customer row yet', function (): void {
    // Regression: a logged-in distributor browsing the shop has an anonymous
    // cart (customer_id null) because the Customer row is only created at
    // checkout. The badge must still count it via the anon cookie.
    $item = cqtItem(4); // anon cart (customer_id null), qty 4
    $user = User::create([
        'full_name' => 'Shopper '.random_int(1000, 9999),
        'email' => 'shopper-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.random_int(10000, 99999),
        'password_hash' => bcrypt('Sh0pper!Pass#2026'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $request = Request::create('/');
    $request->cookies->set(AttributionService::ANON_COOKIE, $item->cart->anonymous_key);
    $request->setUserResolver(fn () => $user);

    expect(app(CartService::class)->itemCount($request))->toBe(4);
});

it('flashes the just-added variant id so the cart can highlight it', function (): void {
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "ADD-{$n}", 'slug' => "add-{$n}", 'name' => "Add {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "ADD-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);

    $this->post(route('shop.cart.add'), ['product_variant_id' => $variant->id, 'qty' => 1])
        ->assertRedirect(route('shop.cart'))
        ->assertSessionHas('added_variant_id', $variant->id);
});

it('highlights the just-added line on the cart page (and not other lines)', function (): void {
    $item = cqtItem(2); // line A (the "just added" one)
    // A second, different line that must NOT be highlighted.
    $n = random_int(10000, 99999);
    $p2 = Product::create(['sku' => "CQO-{$n}", 'slug' => "cqo-{$n}", 'name' => "Other {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $v2 = ProductVariant::create(['product_id' => $p2->id, 'variant_sku' => "CQO-{$n}-V1", 'name' => 'Default', 'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'bv_paise' => 0, 'gst_rate_bp' => 1800, 'inventory_policy' => 'no_track', 'status' => 'active']);
    CartItem::create(['cart_id' => $item->cart_id, 'product_variant_id' => $v2->id, 'qty' => 1, 'unit_price_paise' => 50000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    DB::table('settings')->updateOrInsert(['key' => 'commerce.storefront.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);

    $html = $this->withUnencryptedCookie(AttributionService::ANON_COOKIE, $item->cart->anonymous_key)
        ->withSession(['added_variant_id' => $item->product_variant_id])
        ->get(route('shop.cart'))
        ->assertOk()
        ->assertSee('cart-line-added', false)   // highlight class is rendered
        ->assertSee('data-cart-added', false)
        ->getContent();

    // Exactly one line is highlighted (the added one, not the other product).
    expect(substr_count($html, 'cart-line-added'))->toBe(1);
});

it('increases the line quantity (the + button sends qty+1)', function (): void {
    $item = cqtItem(2);

    $this->patch(route('shop.cart.update', $item), ['qty' => 3])
        ->assertRedirect(route('shop.cart'));

    expect($item->fresh()->qty)->toBe(3);
});

it('decreases the line quantity (the − button sends qty-1)', function (): void {
    $item = cqtItem(3);

    $this->patch(route('shop.cart.update', $item), ['qty' => 2]);

    expect($item->fresh()->qty)->toBe(2);
});

it('removes the line when the quantity reaches 0', function (): void {
    $item = cqtItem(1);
    $id = $item->id;

    $this->patch(route('shop.cart.update', $item), ['qty' => 0]);

    expect(CartItem::find($id))->toBeNull();
});

it('rejects a quantity above the max of 10', function (): void {
    $item = cqtItem(10);

    $this->patch(route('shop.cart.update', $item), ['qty' => 11])
        ->assertSessionHasErrors('qty');

    expect($item->fresh()->qty)->toBe(10);
});
