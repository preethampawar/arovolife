<?php

declare(strict_types=1);

/**
 * H1 / H2: checkout refuses more than is available only while InventoryFeature
 * is on. With the flag off, checkout and the cart behave exactly as before.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Shared\Features\InventoryFeature;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function cavVariant(int $stock): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CAV-{$n}", 'slug' => "cav-{$n}", 'name' => "Tonic {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CAV-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
    if ($stock > 0) {
        $batch = StockBatch::create([
            'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'C1',
            'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
        ]);
        app(StockLedger::class)->post([
            'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
            'batch_id' => $batch->id, 'qty' => $stock, 'unit_cost_paise' => 60000,
            'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
        ]);
    }

    return $variant;
}

function cavPlace(ProductVariant $variant, int $qty): Order
{
    $user = User::create([
        'full_name' => 'CAV Buyer', 'email' => 'cav-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'cav'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    return app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'CAV', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'CAV', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
    );
}

it('with InventoryFeature on, placing more than available fails with a readable message and reserves nothing', function (): void {
    Feature::for(null)->activate(InventoryFeature::class);
    $variant = cavVariant(3);

    expect(fn () => cavPlace($variant, 5))
        ->toThrow(InsufficientStockException::class, "Only 3 left of {$variant->product->name}.");

    $level = InventoryLevel::where('product_variant_id', $variant->id)->sole();
    expect(Order::count())->toBe(0)
        ->and($level->reserved)->toBe(0)
        ->and($level->on_hand)->toBe(3);

    // Within what is available it goes through and reserves.
    cavPlace($variant, 3);
    expect($level->fresh()->reserved)->toBe(3);
});

it('with the flag off, checkout behaves exactly as before (regression guard)', function (): void {
    $variant = cavVariant(0);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 1, 'reserved' => 0]);

    $order = cavPlace($variant, 5);

    expect($order->status)->toBe(Order::STATUS_PLACED)
        ->and(InventoryLevel::where('product_variant_id', $variant->id)->sole()->reserved)->toBe(5)
        ->and(StockMovement::count())->toBe(0);
});

it('the cart clamps to available only while the flag is on', function (): void {
    $variant = cavVariant(2);
    $cart = Cart::create(['anonymous_key' => 'cavc'.uniqid(), 'expires_at' => now()->addDay()]);
    $carts = app(CartService::class);

    expect($carts->addItem($cart, $variant->id, 5)->qty)->toBe(5);

    Feature::for(null)->activate(InventoryFeature::class);
    $item = $carts->addItem($cart, $variant->id, 1);
    expect($item->qty)->toBe(2)
        ->and(session('stock_notice'))->toBe("Only 2 available of {$variant->product->name}.");
});
