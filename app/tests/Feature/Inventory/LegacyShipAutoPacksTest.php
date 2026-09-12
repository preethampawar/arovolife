<?php

declare(strict_types=1);

/**
 * H3: the one-click "Mark as Shipped" still works on an order nobody packed —
 * it packs first. While InventoryFeature is OFF, stock is recorded but never
 * allowed to stop the shipment.
 */

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
use App\Modules\Inventory\Services\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Shared\Features\InventoryFeature;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function lsaVariant(int $stock): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "LSA-{$n}", 'slug' => "lsa-{$n}", 'name' => "LSA {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "LSA-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);

    if ($stock > 0) {
        $batch = StockBatch::create([
            'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'L1',
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

function lsaPaidOrder(ProductVariant $variant, int $qty): Order
{
    $user = User::create([
        'full_name' => 'LSA Buyer', 'email' => 'lsa-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'lsa'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'LSA', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'LSA', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
    );
    app(OrderStateMachine::class)->markPaid($order->fresh());

    return $order->fresh();
}

it('markShipped on an unpacked paid order packs first, then ships; shipment row gets carrier + awb', function (): void {
    $variant = lsaVariant(10);
    $order = lsaPaidOrder($variant, 3);

    app(OrderStateMachine::class)->markShipped($order, null, 'Delhivery', 'AWB-777');

    $order->refresh();
    $shipment = Shipment::where('order_id', $order->id)->sole();

    expect($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and($order->packed_at)->not->toBeNull()
        ->and($order->ship_carrier)->toBe('Delhivery')
        ->and($order->ship_tracking_no)->toBe('AWB-777')
        ->and((int) StockMovement::where('type', StockMovement::TYPE_SALE_OUT)->sum('qty'))->toBe(-3)
        ->and($shipment->status)->toBe(Shipment::STATUS_DISPATCHED)
        ->and($shipment->carrier_code)->toBe('Delhivery')
        ->and($shipment->awb_no)->toBe('AWB-777')
        ->and($shipment->dispatched_at)->not->toBeNull()
        // Revenue recognition is unchanged by the pack step.
        ->and(LedgerTx::where('idempotency_key', "order.shipped:{$order->id}")->exists())->toBeTrue();

    app(OrderStateMachine::class)->markDelivered($order->fresh());
    expect($shipment->fresh()->status)->toBe(Shipment::STATUS_DELIVERED)
        ->and($shipment->fresh()->delivered_at)->not->toBeNull();
});

it('ships an already-packed order without packing it again', function (): void {
    $variant = lsaVariant(10);
    $order = lsaPaidOrder($variant, 2);
    app(OrderFulfilmentService::class)->pack($order, null, null);

    app(OrderStateMachine::class)->markShipped($order->fresh(), null, null, null);

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED)
        ->and(StockMovement::where('type', StockMovement::TYPE_SALE_OUT)->count())->toBe(1)
        ->and(Shipment::where('order_id', $order->id)->sole()->carrier_code)->toBe('MANUAL');
});

it('with the flag off, an order with no recorded stock still ships exactly as before (unpacked)', function (): void {
    $variant = lsaVariant(0);
    $order = lsaPaidOrder($variant, 2);

    app(OrderStateMachine::class)->markShipped($order, null, 'BlueDart', 'X1');

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and($order->packed_at)->toBeNull()
        ->and(StockMovement::count())->toBe(0)
        ->and(Shipment::where('order_id', $order->id)->exists())->toBeFalse()
        ->and(LedgerTx::where('idempotency_key', "order.shipped:{$order->id}")->exists())->toBeTrue();
});

it('with the flag on, shipping an order that cannot be covered is refused and nothing moves', function (): void {
    Feature::for(null)->activate(InventoryFeature::class);
    $variant = lsaVariant(1);
    Feature::for(null)->deactivate(InventoryFeature::class);
    $order = lsaPaidOrder($variant, 2); // placed while the check was off
    Feature::for(null)->activate(InventoryFeature::class);

    expect(fn () => app(OrderStateMachine::class)->markShipped($order, null, null, null))
        ->toThrow(InsufficientStockException::class);

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and(StockMovement::where('type', StockMovement::TYPE_SALE_OUT)->count())->toBe(0)
        ->and(LedgerTx::where('idempotency_key', "order.shipped:{$order->id}")->exists())->toBeFalse();
});
