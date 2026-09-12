<?php

declare(strict_types=1);

/**
 * Pack is where batches are chosen (plan §4.8, §10): earliest expiry first,
 * never an expired batch, split across batches when one is not enough, and
 * nothing written at all when the order cannot be covered.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Inventory\Services\StockLedger;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function ofpVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "OFP-{$n}", 'slug' => "ofp-{$n}", 'name' => "OFP {$n}", 'hsn_code' => '3004', 'status' => 'active']);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "OFP-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function ofpBatch(ProductVariant $variant, string $batchNo, int $qty, ?string $expiry, int $unitCost = 60000): StockBatch
{
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => $batchNo,
        'expiry_date' => $expiry, 'unit_cost_paise' => $unitCost, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);

    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => $qty,
        'unit_cost_paise' => $unitCost, 'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);

    return $batch->fresh();
}

/** A placed and paid order for $qty units of $variant. */
function ofpPaidOrder(ProductVariant $variant, int $qty): Order
{
    $user = User::create([
        'full_name' => 'OFP Buyer', 'email' => 'ofp-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'ofp'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800]);

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'OFP', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        ['name' => 'OFP', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MH', 'pincode' => '411001'],
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
    );
    app(OrderStateMachine::class)->markPaid($order->fresh());

    return $order->fresh();
}

function ofpLevel(ProductVariant $variant): InventoryLevel
{
    return InventoryLevel::where('product_variant_id', $variant->id)->where('warehouse_code', Warehouse::DEFAULT_CODE)->firstOrFail();
}

it('allocates the earliest-expiring non-expired batch first and splits across batches', function (): void {
    $variant = ofpVariant();
    $expired = ofpBatch($variant, 'EXPIRED', 50, now()->subDay()->toDateString());
    $later = ofpBatch($variant, 'LATER', 5, now()->addDays(200)->toDateString());
    $soon = ofpBatch($variant, 'SOON', 2, now()->addDays(30)->toDateString());
    $noExpiry = ofpBatch($variant, 'NOEXP', 10, null);

    $order = ofpPaidOrder($variant, 4);
    app(OrderFulfilmentService::class)->pack($order, null, null);

    $taken = StockMovement::where('type', StockMovement::TYPE_SALE_OUT)->orderBy('id')->get()
        ->map(fn (StockMovement $m): array => [$m->stock_batch_id, $m->qty])->all();

    expect($taken)->toBe([[$soon->id, -2], [$later->id, -2]])
        ->and($expired->fresh()->qty_on_hand)->toBe(50)
        ->and($noExpiry->fresh()->qty_on_hand)->toBe(10)
        ->and($soon->fresh()->qty_on_hand)->toBe(0)
        ->and($later->fresh()->qty_on_hand)->toBe(3)
        ->and(ofpLevel($variant)->on_hand)->toBe(63);
});

it('never allocates an expired batch even when it is the only stock (throws InsufficientStock)', function (): void {
    $variant = ofpVariant();
    $expired = ofpBatch($variant, 'EXPIRED', 20, now()->subDay()->toDateString());
    $order = ofpPaidOrder($variant, 1);

    expect(fn () => app(OrderFulfilmentService::class)->pack($order, null, null))
        ->toThrow(InsufficientStockException::class, 'need 1, have 0 in DEFAULT');

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PAID)
        ->and($order->packed_at)->toBeNull()
        ->and(StockMovement::where('type', StockMovement::TYPE_SALE_OUT)->count())->toBe(0)
        ->and($expired->fresh()->qty_on_hand)->toBe(20)
        ->and(ofpLevel($variant)->reserved)->toBe(1)
        ->and(Shipment::where('order_id', $order->id)->exists())->toBeFalse();
});

it('writes nothing when a later line is short, even if earlier lines could be picked', function (): void {
    $plenty = ofpVariant();
    ofpBatch($plenty, 'P1', 10, null);
    $short = ofpVariant();
    ofpBatch($short, 'S1', 1, null);

    $order = ofpPaidOrder($plenty, 2);
    // Add a second, short line to the same order.
    $order->items()->create([
        'product_variant_id' => $short->id, 'product_name_snapshot' => 'Short', 'variant_sku_snapshot' => $short->variant_sku,
        'hsn_code_snapshot' => '3004', 'qty' => 3, 'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
        'taxable_value_paise' => 0, 'gst_paise' => 0, 'line_total_paise' => 0,
    ]);

    expect(fn () => app(OrderFulfilmentService::class)->pack($order, null, null))
        ->toThrow(InsufficientStockException::class);

    expect(StockMovement::where('type', StockMovement::TYPE_SALE_OUT)->count())->toBe(0);
});

it('writes one sale_out per (item, batch), releases reserved, creates the shipment row, sets ready_to_ship + packed_at', function (): void {
    $variant = ofpVariant();
    $a = ofpBatch($variant, 'A', 3, now()->addDays(10)->toDateString(), 50000);
    $b = ofpBatch($variant, 'B', 10, now()->addDays(20)->toDateString(), 55000);
    $actor = User::factory()->create()->id;

    $order = ofpPaidOrder($variant, 5);
    expect(ofpLevel($variant)->reserved)->toBe(5);

    app(OrderFulfilmentService::class)->pack($order, null, $actor);

    $item = $order->items()->firstOrFail();
    $moves = StockMovement::where('reference_type', 'order_item')->where('reference_id', $item->id)->orderBy('id')->get();
    expect($moves)->toHaveCount(2)
        ->and($moves[0]->stock_batch_id)->toBe($a->id)
        ->and($moves[0]->qty)->toBe(-3)
        ->and($moves[0]->unit_cost_paise)->toBe(50000)
        ->and($moves[1]->stock_batch_id)->toBe($b->id)
        ->and($moves[1]->qty)->toBe(-2)
        ->and($moves[1]->unit_cost_paise)->toBe(55000);

    $order->refresh();
    $shipment = Shipment::where('order_id', $order->id)->sole();
    expect(ofpLevel($variant)->reserved)->toBe(0)
        ->and(ofpLevel($variant)->on_hand)->toBe(8)
        ->and($order->status)->toBe(Order::STATUS_READY_TO_SHIP)
        ->and($order->packed_at)->not->toBeNull()
        ->and($order->warehouse_code)->toBe(Warehouse::DEFAULT_CODE)
        ->and($order->packed_by_user_id)->toBe($actor)
        ->and($shipment->status)->toBe(Shipment::STATUS_PICKED)
        ->and($shipment->warehouse_code)->toBe(Warehouse::DEFAULT_CODE)
        ->and(AuditLog::where('action', 'order.packed')->where('subject_id', $order->id)->exists())->toBeTrue();

    $pick = app(OrderFulfilmentService::class)->pickList($order);
    expect(array_column($pick, 'batch_no'))->toBe(['A', 'B'])
        ->and(array_column($pick, 'qty'))->toBe([3, 2]);
});

it('pack is idempotent (second call is a no-op)', function (): void {
    $variant = ofpVariant();
    ofpBatch($variant, 'A', 10, null);
    $order = ofpPaidOrder($variant, 2);

    app(OrderFulfilmentService::class)->pack($order, null, null);
    app(OrderFulfilmentService::class)->pack($order->fresh(), null, null);

    expect(StockMovement::where('type', StockMovement::TYPE_SALE_OUT)->count())->toBe(1)
        ->and(Shipment::where('order_id', $order->id)->count())->toBe(1)
        ->and(ofpLevel($variant)->on_hand)->toBe(8);
});

it('refuses a warehouse that does not fulfil orders', function (): void {
    $variant = ofpVariant();
    ofpBatch($variant, 'A', 10, null);
    Warehouse::create(['code' => 'STORE-1', 'name' => 'Storage', 'type' => Warehouse::TYPE_WAREHOUSE, 'fulfils_orders' => false, 'status' => Warehouse::STATUS_ACTIVE]);
    $order = ofpPaidOrder($variant, 1);

    expect(fn () => app(OrderFulfilmentService::class)->pack($order, 'STORE-1', null))
        ->toThrow(RuntimeException::class, 'fulfils orders');
});
