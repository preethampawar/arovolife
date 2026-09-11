<?php

declare(strict_types=1);

/**
 * The invariants the whole module rests on (plan §3.3):
 *
 *  1. on_hand == Σ movements for that (variant, warehouse), always;
 *  2. neither a level nor a batch may go negative;
 *  3. available == on_hand − reserved, over pickable warehouses only.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function slVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "SL-{$n}", 'slug' => "sl-{$n}", 'name' => "SL {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "SL-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function slBatch(ProductVariant $variant, string $warehouseCode = Warehouse::DEFAULT_CODE, string $batchNo = 'B1'): StockBatch
{
    return StockBatch::create([
        'product_variant_id' => $variant->id,
        'warehouse_code' => $warehouseCode,
        'batch_no' => $batchNo,
        'unit_cost_paise' => 60000,
        'qty_on_hand' => 0,
        'received_at' => now(),
    ]);
}

it('posts a movement and rolls both projections forward in one transaction', function (): void {
    $variant = slVariant();
    $batch = slBatch($variant);

    $movement = app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id,
        'qty' => 10,
        'unit_cost_paise' => 60000,
        'reference_type' => 'purchase_invoice_item',
        'reference_id' => 4242,
    ]);

    expect($movement->qty)->toBe(10)
        ->and($movement->type)->toBe(StockMovement::TYPE_PURCHASE_IN);

    // Invariant 1: the projection equals the sum of the ledger.
    $level = InventoryLevel::where('product_variant_id', $variant->id)
        ->where('warehouse_code', Warehouse::DEFAULT_CODE)->firstOrFail();

    expect($level->on_hand)->toBe(10)
        ->and((int) StockMovement::where('product_variant_id', $variant->id)->sum('qty'))->toBe(10)
        ->and($batch->fresh()->qty_on_hand)->toBe(10);

    // Every movement is auditable: who moved what, and the level either side.
    $audit = AuditLog::where('action', 'inventory.stock.moved')
        ->where('subject_id', $movement->id)->firstOrFail();

    expect($audit->details['on_hand_before'])->toBe(0)
        ->and($audit->details['on_hand_after'])->toBe(10)
        ->and($audit->details['reference_id'])->toBe(4242);
});

it('refuses a zero quantity and a quantity whose sign contradicts the type', function (): void {
    $variant = slVariant();
    $ledger = app(StockLedger::class);

    expect(fn () => $ledger->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'qty' => 0,
    ]))->toThrow(InvalidArgumentException::class, 'quantity of zero');

    // A sale posted as +5 would read as a receipt in every report.
    expect(fn () => $ledger->post([
        'type' => StockMovement::TYPE_SALE_OUT,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'qty' => 5,
    ]))->toThrow(InvalidArgumentException::class, 'negative quantity');

    expect(StockMovement::count())->toBe(0);
});

it('refuses a movement that would take the batch or the level negative, and writes nothing', function (): void {
    $variant = slVariant();
    $batch = slBatch($variant);
    $ledger = app(StockLedger::class);

    $ledger->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id,
        'qty' => 3,
    ]);

    expect(fn () => $ledger->post([
        'type' => StockMovement::TYPE_SALE_OUT,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id,
        'qty' => -4,
    ]))->toThrow(InsufficientStockException::class, $variant->variant_sku);

    // The whole post rolls back: no movement, no half-applied projection.
    expect(StockMovement::count())->toBe(1)
        ->and($batch->fresh()->qty_on_hand)->toBe(3)
        ->and($ledger->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(3);
});

it('counts only pickable warehouses in available() and subtracts what orders reserved', function (): void {
    $variant = slVariant();

    Warehouse::create(['code' => 'STORE', 'name' => 'Storage only', 'type' => 'warehouse', 'fulfils_orders' => false]);
    Warehouse::create(['code' => 'OLD', 'name' => 'Closed site', 'type' => 'warehouse', 'status' => Warehouse::STATUS_ARCHIVED]);

    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'on_hand' => 10, 'reserved' => 3]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'STORE', 'on_hand' => 5, 'reserved' => 0]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'OLD', 'on_hand' => 8, 'reserved' => 0]);

    $ledger = app(StockLedger::class);

    // Storage-only and archived stock is real, but it cannot fill an order.
    expect($ledger->available($variant->id))->toBe(7)
        ->and($ledger->available($variant->id, 'STORE'))->toBe(5)
        ->and($ledger->available($variant->id, Warehouse::DEFAULT_CODE))->toBe(7);
});

it('refuses a batch that belongs to another warehouse and an unknown warehouse', function (): void {
    $variant = slVariant();
    Warehouse::create(['code' => 'HYD-01', 'name' => 'Hyderabad', 'type' => 'warehouse']);
    $batch = slBatch($variant, 'HYD-01');
    $ledger = app(StockLedger::class);

    expect(fn () => $ledger->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id,
        'qty' => 1,
    ]))->toThrow(InvalidArgumentException::class, 'does not belong');

    expect(fn () => $ledger->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => 'NOWHERE',
        'qty' => 1,
    ]))->toThrow(InvalidArgumentException::class, 'Unknown warehouse');
});
