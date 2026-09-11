<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Services\AttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The cart line thumbnail must be the same picture the product page shows —
 * the first gallery image — not the legacy `image_url` column, which on
 * staging hot-linked a random-image service and never matched the product
 * (QA F34).
 */
function thumbProduct(?string $imageUrl, ?string $galleryUrl): Product
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "THM-{$n}", 'slug' => "thm-{$n}", 'name' => "Thumb {$n}",
        'hsn_code' => '3004', 'image_url' => $imageUrl, 'status' => 'active',
    ]);

    if ($galleryUrl !== null) {
        ProductImage::create([
            'product_id' => $product->id, 'external_url' => $galleryUrl,
            'alt' => 'Gallery', 'sort' => 0, 'kind' => ProductImage::KIND_GALLERY,
        ]);
    }

    return $product->fresh();
}

function thumbCartFor(Product $product): Cart
{
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => $product->sku.'-V1', 'name' => 'Default',
        'mrp_paise' => 50000, 'sale_price_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'thm'.random_int(10000, 99999), 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 50000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    return $cart;
}

it('F34-01: prefers the first gallery image over the legacy image_url column', function (): void {
    $product = thumbProduct('https://picsum.photos/seed/x/800/800', 'https://cdn.example.test/gallery/real.jpg');

    expect($product->primaryImageUrl())->toBe('https://cdn.example.test/gallery/real.jpg');
});

it('F34-02: falls back to image_url when no gallery image exists', function (): void {
    $product = thumbProduct('https://cdn.example.test/legacy.jpg', null);

    expect($product->primaryImageUrl())->toBe('https://cdn.example.test/legacy.jpg');
});

it('F34-03: returns null when the product has no picture at all', function (): void {
    expect(thumbProduct(null, null)->primaryImageUrl())->toBeNull();
});

it('F34-04: the cart page renders the gallery image, not the legacy URL', function (): void {
    $product = thumbProduct('https://picsum.photos/seed/x/800/800', 'https://cdn.example.test/gallery/real.jpg');
    $cart = thumbCartFor($product);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.storefront.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);

    $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->get(route('shop.cart'))
        ->assertOk()
        ->assertSee('https://cdn.example.test/gallery/real.jpg')
        ->assertDontSee('picsum.photos');
});
