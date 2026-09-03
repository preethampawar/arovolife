<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\AttributionService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Tax\Models\Invoice;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * EH-H3: a failure in placement, payment capture or invoice generation must
 * not reach the buyer as a raw 500. Placement and payment failures redirect
 * back with a `checkout` error; an invoice failure is logged and swallowed so
 * the paid order still confirms.
 */
beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    DB::table('settings')->updateOrInsert(['key' => 'commerce.guest_checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
});

function cfhCart(): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CFH-{$n}", 'slug' => "cfh-{$n}", 'name' => "CFH {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CFH-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => 'cfhk'.$n, 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 100000, 'bv_paise' => 50000, 'gst_rate_bp' => 1800,
    ]);

    return $cart;
}

function cfhPayload(): array
{
    return [
        'buyer_name' => 'Failure Buyer',
        'buyer_email' => 'cfh-'.uniqid().'@test.com',
        'buyer_phone' => '9800000000',
        'ship_line1' => '1 Test St',
        'ship_city' => 'Pune',
        'ship_state' => 'MH',
        'ship_pincode' => '411001',
        'delivery_type' => 'ship',
        'payment_method' => 'online',
        'billing_same' => '1',
        'accept_terms' => '1',
    ];
}

it('EH-H3a: an order-placement failure redirects back with a checkout error and commits no order', function (): void {
    $cart = cfhCart();

    Order::creating(function (): void {
        throw new RuntimeException('simulated placement failure');
    });

    $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), cfhPayload())
        ->assertRedirect()
        ->assertSessionHasErrors('checkout');

    expect(Order::count())->toBe(0);
});

it('EH-H3b: a payment failure cancels the placed order and redirects back with a checkout error', function (): void {
    $cart = cfhCart();

    // Fail inside the gateway call itself, exactly where a real outage lands:
    // after the order is placed, before it is marked paid.
    PaymentIntent::creating(function (): void {
        throw new RuntimeException('simulated gateway failure');
    });

    $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), cfhPayload())
        ->assertRedirect()
        ->assertSessionHasErrors('checkout');

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe(Order::STATUS_CANCELLED);
});

it('EH-H3c: an invoice failure does not fail the paid order — the buyer still reaches the confirmation', function (): void {
    $cart = cfhCart();

    Invoice::creating(function (): void {
        throw new RuntimeException('simulated invoice failure');
    });

    $this->withCookie(AttributionService::ANON_COOKIE, $cart->anonymous_key)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), cfhPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe(Order::STATUS_PAID);
    expect(Invoice::where('order_id', $order->id)->exists())->toBeFalse();
});
