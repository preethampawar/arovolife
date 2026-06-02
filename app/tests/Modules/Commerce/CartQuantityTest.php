<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
