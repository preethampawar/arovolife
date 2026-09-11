<?php

declare(strict_types=1);

/**
 * A transfer moves stock in two steps: dispatch takes it out of the source
 * the instant it leaves, receive puts it into the destination once someone
 * there has counted it in. There is deliberately no in-transit warehouse
 * (plan §10), so nothing is added anywhere between the two calls.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sttActor(): int
{
    return User::factory()->create()->id;
}

function sttVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "STT-{$n}", 'slug' => "stt-{$n}", 'name' => "STT {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "STT-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function sttWarehouse(string $code, bool $active = true): Warehouse
{
    return Warehouse::create([
        'code' => $code, 'name' => "Warehouse {$code}", 'type' => Warehouse::TYPE_WAREHOUSE,
        'fulfils_orders' => true, 'status' => $active ? Warehouse::STATUS_ACTIVE : Warehouse::STATUS_ARCHIVED,
    ]);
}

/** Stocks a batch at $warehouseCode with $qty units via a purchase_in movement, and returns the batch. */
function sttStockedBatch(ProductVariant $variant, string $warehouseCode, int $qty, string $batchNo = 'B1'): StockBatch
{
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => $warehouseCode, 'batch_no' => $batchNo,
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);

    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => $warehouseCode,
        'batch_id' => $batch->id,
        'qty' => $qty,
        'unit_cost_paise' => 60000,
        'reference_type' => 'purchase_invoice_item',
        'reference_id' => 1,
    ]);

    return $batch->fresh();
}

it('dispatch writes transfer_out and reduces the source; nothing is added anywhere until receive', function (): void {
    $variant = sttVariant();
    $to = sttWarehouse('STT-TO');
    $batch = sttStockedBatch($variant, Warehouse::DEFAULT_CODE, 20);
    $actor = sttActor();

    $transfer = app(StockTransferService::class)->createDraft(
        Warehouse::DEFAULT_CODE,
        $to->code,
        [['product_variant_id' => $variant->id, 'stock_batch_id' => $batch->id, 'qty' => 8]],
        $actor,
    );

    app(StockTransferService::class)->dispatch($transfer, $actor);

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_DISPATCHED)
        ->and(app(StockLedger::class)->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(12)
        ->and(app(StockLedger::class)->onHand($variant->id, $to->code))->toBe(0);

    $outMovement = StockMovement::where('type', StockMovement::TYPE_TRANSFER_OUT)->where('product_variant_id', $variant->id)->first();
    expect($outMovement)->not->toBeNull()->and($outMovement->qty)->toBe(-8);

    expect(StockMovement::where('type', StockMovement::TYPE_TRANSFER_IN)->where('product_variant_id', $variant->id)->exists())->toBeFalse();
});

it('receive writes transfer_in at the destination with the same batch_no, expiry and cost; a short receipt writes write_off', function (): void {
    $variant = sttVariant();
    $to = sttWarehouse('STT-TO2');
    $batch = sttStockedBatch($variant, Warehouse::DEFAULT_CODE, 10, 'BATCH-X');
    $batch->update(['expiry_date' => now()->addYear()->toDateString()]);
    $actor = sttActor();

    $service = app(StockTransferService::class);
    $transfer = $service->createDraft(
        Warehouse::DEFAULT_CODE, $to->code,
        [['product_variant_id' => $variant->id, 'stock_batch_id' => $batch->id, 'qty' => 10]],
        $actor,
    );
    $service->dispatch($transfer, $actor);

    $item = $transfer->fresh()->items()->firstOrFail();
    $service->receive($transfer->fresh(), $actor, [$item->id => 7]);

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED);

    $destBatch = StockBatch::where('product_variant_id', $variant->id)->where('warehouse_code', $to->code)->where('batch_no', 'BATCH-X')->firstOrFail();
    expect($destBatch->unit_cost_paise)->toBe(60000)
        ->and($destBatch->expiry_date->toDateString())->toBe($batch->expiry_date->toDateString())
        ->and($destBatch->qty_on_hand)->toBe(7);

    $transferIn = StockMovement::where('type', StockMovement::TYPE_TRANSFER_IN)->where('product_variant_id', $variant->id)->first();
    expect($transferIn)->not->toBeNull()->and($transferIn->qty)->toBe(10);

    $writeOff = StockMovement::where('type', StockMovement::TYPE_WRITE_OFF)->where('product_variant_id', $variant->id)->first();
    expect($writeOff)->not->toBeNull()
        ->and($writeOff->qty)->toBe(-3)
        ->and($writeOff->reason)->toBe('transit_shortage');

    expect(app(StockLedger::class)->onHand($variant->id, $to->code))->toBe(7);
});

it('refuses a transfer from a warehouse to itself, from an archived warehouse, or for more than is available', function (): void {
    $variant = sttVariant();
    $to = sttWarehouse('STT-TO3');
    $archived = sttWarehouse('STT-ARCHIVED', active: false);
    $batch = sttStockedBatch($variant, Warehouse::DEFAULT_CODE, 5);
    $actor = sttActor();
    $service = app(StockTransferService::class);

    expect(fn () => $service->createDraft(
        Warehouse::DEFAULT_CODE, Warehouse::DEFAULT_CODE,
        [['product_variant_id' => $variant->id, 'stock_batch_id' => $batch->id, 'qty' => 1]],
        $actor,
    ))->toThrow(RuntimeException::class, 'two different warehouses');

    expect(fn () => $service->createDraft(
        $archived->code, $to->code,
        [['product_variant_id' => $variant->id, 'stock_batch_id' => $batch->id, 'qty' => 1]],
        $actor,
    ))->toThrow(RuntimeException::class, 'archived');

    expect(fn () => $service->createDraft(
        Warehouse::DEFAULT_CODE, $to->code,
        [['product_variant_id' => $variant->id, 'stock_batch_id' => $batch->id, 'qty' => 999]],
        $actor,
    ))->toThrow(RuntimeException::class, 'only 5 available');
});
