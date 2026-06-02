<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\ShippingService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function shipSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now(), 'created_at' => now()]);
}

/** Build a single-line cart whose merchandise subtotal is $subtotalPaise. */
function shipCart(int $subtotalPaise): Cart
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "SHP-{$n}", 'slug' => "shp-{$n}", 'name' => "Ship {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "SHP-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => $subtotalPaise, 'sale_price_paise' => $subtotalPaise, 'gst_rate_bp' => 1800,
        'inventory_policy' => 'track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 50, 'reserved' => 0]);
    $cart = Cart::create(['anonymous_key' => "k{$n}", 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => $subtotalPaise, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    return $cart->load('items.variant.product');
}

function shipBuyer(): array
{
    return ['name' => 'Ship Buyer', 'email' => 'ship-'.uniqid().'@test.com', 'phone' => '+919800000000', 'marketing_opt_in' => false];
}

function shipAddr(): array
{
    return ['name' => 'Ship Buyer', 'phone' => '+919800000000', 'line1' => '1 Test St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'];
}

it('charges the flat fee below the free-shipping threshold (defaults)', function (): void {
    // ₹3999 cart, default threshold ₹4000, default fee ₹60.
    expect(app(ShippingService::class)->feePaise(399900))->toBe(6000);
});

it('gives free shipping at or above the threshold (defaults)', function (): void {
    expect(app(ShippingService::class)->feePaise(400000))->toBe(0);
    expect(app(ShippingService::class)->feePaise(500000))->toBe(0);
});

it('honours admin-configured fee and threshold', function (): void {
    shipSetting('commerce.shipping.fee_rupees', '99');
    shipSetting('commerce.shipping.free_threshold_rupees', '2000');

    expect(app(ShippingService::class)->feePaise(199900))->toBe(9900); // below ₹2000 → ₹99
    expect(app(ShippingService::class)->feePaise(200000))->toBe(0);    // at ₹2000 → free
});

it('persists shipping_paise and adds it to the order total below the threshold', function (): void {
    // ₹1000 cart → ₹60 shipping → total ₹1060.
    $order = app(CheckoutService::class)->place(shipCart(100000), shipBuyer(), shipAddr(), shipAddr(), null, 'direct', Order::PAYMENT_ONLINE);

    expect($order->shipping_paise)->toBe(6000);
    expect($order->total_paise)->toBe(106000);
});

it('persists zero shipping and free total at or above the threshold', function (): void {
    $order = app(CheckoutService::class)->place(shipCart(450000), shipBuyer(), shipAddr(), shipAddr(), null, 'direct', Order::PAYMENT_ONLINE);

    expect($order->shipping_paise)->toBe(0);
    expect($order->total_paise)->toBe(450000);
});
