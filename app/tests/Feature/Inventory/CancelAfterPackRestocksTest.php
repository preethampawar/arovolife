<?php

declare(strict_types=1);

/**
 * H4: once an order is packed its reservation is gone and real units have
 * left their batches, so cancelling puts those units back where they came
 * from. Before pack, cancel is exactly what it always was.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Inventory\Services\StockLedger;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function capVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "CAP-{$n}", 'slug' => "cap-{$n}", 'name' => "CAP {$n}", 'hsn_code' => '3004', 'status' => 'active']);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "CAP-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function capBatch(ProductVariant $variant, string $batchNo, int $qty, string $expiry): StockBatch
{
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => $batchNo,
        'expiry_date' => $expiry, 'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id, 'qty' => $qty, 'unit_cost_paise' => 60000,
        'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);

    return $batch->fresh();
}

function capOrder(ProductVariant $variant, int $qty, bool $paid = true): Order
{
    $user = User::create([
        'full_name' => 'CAP Buyer', 'email' => 'cap-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'cap'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'CAP', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'CAP', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
    );
    if ($paid) {
        app(OrderStateMachine::class)->markPaid($order->fresh());
    }

    return $order->fresh();
}

function capLevel(ProductVariant $variant): InventoryLevel
{
    return InventoryLevel::where('product_variant_id', $variant->id)->where('warehouse_code', Warehouse::DEFAULT_CODE)->firstOrFail();
}

it('cancel after pack writes sale_reversal into the same batches and marks the shipment returned_to_origin', function (): void {
    $variant = capVariant();
    $a = capBatch($variant, 'A', 2, now()->addDays(10)->toDateString());
    $b = capBatch($variant, 'B', 10, now()->addDays(40)->toDateString());
    $order = capOrder($variant, 5);

    app(OrderFulfilmentService::class)->pack($order, null, null);
    expect($a->fresh()->qty_on_hand)->toBe(0)->and($b->fresh()->qty_on_hand)->toBe(7);

    app(OrderStateMachine::class)->cancel($order->fresh(), 'changed mind');

    $reversals = StockMovement::where('type', StockMovement::TYPE_SALE_REVERSAL)->orderBy('stock_batch_id')->get();
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and($reversals)->toHaveCount(2)
        ->and($reversals->pluck('qty', 'stock_batch_id')->all())->toBe([$a->id => 2, $b->id => 3])
        ->and($a->fresh()->qty_on_hand)->toBe(2)
        ->and($b->fresh()->qty_on_hand)->toBe(10)
        ->and(capLevel($variant)->on_hand)->toBe(12)
        ->and(capLevel($variant)->reserved)->toBe(0)
        ->and(Shipment::where('order_id', $order->id)->sole()->status)->toBe(Shipment::STATUS_RETURNED);

    // A retried unpack finds nothing left to put back.
    app(OrderFulfilmentService::class)->unpackForCancel($order->fresh(), null);
    expect(StockMovement::where('type', StockMovement::TYPE_SALE_REVERSAL)->count())->toBe(2)
        ->and(capLevel($variant)->on_hand)->toBe(12);
});

it('cancel before pack still only releases reserved (unchanged behaviour)', function (): void {
    $variant = capVariant();
    capBatch($variant, 'A', 10, now()->addDays(10)->toDateString());
    $order = capOrder($variant, 4, paid: false);
    expect(capLevel($variant)->reserved)->toBe(4);

    app(OrderStateMachine::class)->cancel($order, 'test');

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and(capLevel($variant)->reserved)->toBe(0)
        ->and(capLevel($variant)->on_hand)->toBe(10)
        ->and(StockMovement::whereIn('type', [StockMovement::TYPE_SALE_OUT, StockMovement::TYPE_SALE_REVERSAL])->count())->toBe(0)
        ->and(Shipment::where('order_id', $order->id)->exists())->toBeFalse();
});
