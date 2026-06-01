<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Coupon;
use App\Modules\Commerce\Models\CouponRedemption;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Epic-3 coupon engine tests: validation rules + discount computation across
 * type, cap, min-purchase, window, usage/per-customer limits and scope.
 */
function cpnService(): CouponService
{
    return app(CouponService::class);
}

/** Build a cart with a single line item; returns the cart with items loaded. */
function cpnCart(int $unitPaise, int $qty = 1, ?int $categoryId = null, ?int &$productId = null): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "CPN-{$n}", 'slug' => "cpn-{$n}", 'name' => "Coupon Test {$n}",
        'category_id' => $categoryId, 'hsn_code' => '3004', 'status' => 'active',
    ]);
    $productId = $product->id;
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CPN-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => $unitPaise, 'sale_price_paise' => $unitPaise, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'no_track', 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty,
        'unit_price_paise' => $unitPaise, 'bv_paise' => 0, 'pv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    return $cart->load('items.variant.product');
}

/** @param  array<string, mixed>  $attrs */
function cpnCoupon(array $attrs): Coupon
{
    return Coupon::create(array_merge([
        'code' => 'C'.random_int(100000, 999999),
        'type' => Coupon::TYPE_PERCENT,
        'value' => 10,
        'min_purchase_paise' => 0,
        'scope' => Coupon::SCOPE_ALL,
        'used_count' => 0,
        'status' => Coupon::STATUS_ACTIVE,
    ], $attrs));
}

it('COUP-01: percent coupon discounts a percentage of the subtotal', function (): void {
    $cart = cpnCart(100000); // ₹1000
    $coupon = cpnCoupon(['type' => 'percent', 'value' => 10]);

    $result = cpnService()->validate($coupon->code, $cart);
    expect($result->ok)->toBeTrue();
    expect($result->discountPaise)->toBe(10000); // 10% of ₹1000 = ₹100
});

it('COUP-02: percent coupon is capped by max_discount_paise', function (): void {
    $cart = cpnCart(100000); // ₹1000
    $coupon = cpnCoupon(['type' => 'percent', 'value' => 50, 'max_discount_paise' => 20000]);

    expect(cpnService()->validate($coupon->code, $cart)->discountPaise)->toBe(20000); // capped at ₹200
});

it('COUP-03: fixed coupon discounts a flat paise amount', function (): void {
    $cart = cpnCart(100000);
    $coupon = cpnCoupon(['type' => 'fixed', 'value' => 15000]); // ₹150 off

    expect(cpnService()->validate($coupon->code, $cart)->discountPaise)->toBe(15000);
});

it('COUP-04: coupon below the minimum purchase is rejected', function (): void {
    $cart = cpnCart(100000); // ₹1000
    $coupon = cpnCoupon(['min_purchase_paise' => 200000]); // requires ₹2000

    $result = cpnService()->validate($coupon->code, $cart);
    expect($result->ok)->toBeFalse();
    expect($result->error)->toContain('more to use this code');
});

it('COUP-05: an expired coupon is rejected', function (): void {
    $cart = cpnCart(100000);
    $coupon = cpnCoupon(['ends_at' => now()->subDay()]);

    expect(cpnService()->validate($coupon->code, $cart)->ok)->toBeFalse();
});

it('COUP-06: a coupon at its usage limit is rejected', function (): void {
    $cart = cpnCart(100000);
    $coupon = cpnCoupon(['usage_limit' => 1, 'used_count' => 1]);

    expect(cpnService()->validate($coupon->code, $cart)->ok)->toBeFalse();
});

it('COUP-07: per-customer limit blocks a repeat redemption', function (): void {
    $cart = cpnCart(100000);
    $coupon = cpnCoupon(['per_customer_limit' => 1]);
    $customer = Customer::create(['display_name' => 'Repeat Buyer']);
    CouponRedemption::create(['coupon_id' => $coupon->id, 'customer_id' => $customer->id, 'discount_paise' => 5000]);

    $result = cpnService()->validate($coupon->code, $cart, $customer);
    expect($result->ok)->toBeFalse();
    expect($result->error)->toContain('already used');
});

it('COUP-08: a category-scoped coupon only applies to matching items', function (): void {
    $catA = ProductCategory::create(['slug' => 'cat-a', 'name' => 'Cat A', 'sort' => 1, 'status' => 'active']);
    $catB = ProductCategory::create(['slug' => 'cat-b', 'name' => 'Cat B', 'sort' => 2, 'status' => 'active']);

    $cartB = cpnCart(100000, 1, $catB->id);
    $coupon = cpnCoupon(['type' => 'percent', 'value' => 10, 'scope' => 'category', 'scope_id' => $catA->id]);

    // Item is in B, coupon scoped to A → no discount → rejected.
    expect(cpnService()->validate($coupon->code, $cartB)->ok)->toBeFalse();

    // Same coupon against a cart in category A → applies.
    $cartA = cpnCart(100000, 1, $catA->id);
    $result = cpnService()->validate($coupon->code, $cartA);
    expect($result->ok)->toBeTrue();
    expect($result->discountPaise)->toBe(10000);
});

it('COUP-09: recordRedemption logs the redemption and increments used_count', function (): void {
    $coupon = cpnCoupon(['used_count' => 0]);
    cpnService()->recordRedemption($coupon, null, null, 12345);

    expect($coupon->fresh()->used_count)->toBe(1);
    expect(CouponRedemption::where('coupon_id', $coupon->id)->where('discount_paise', 12345)->count())->toBe(1);
});
