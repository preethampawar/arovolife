<?php

declare(strict_types=1);

/**
 * Adjustments are the catch-all for stock changes that are neither a
 * purchase, sale, transfer nor return. Every one needs a non-empty reason a
 * human wrote, and the reasons that mean the goods are gone (damaged,
 * expired, theft) must post a `write_off` rather than a plain `adjustment_out`
 * (plan §3.1, §4.7).
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stadjActor(): int
{
    return User::factory()->create()->id;
}

function stadjVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "ADJ-{$n}", 'slug' => "adj-{$n}", 'name' => "ADJ {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "ADJ-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function stadjStockedBatch(ProductVariant $variant, int $qty): StockBatch
{
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'ADJ-BATCH',
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);

    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id,
        'qty' => $qty,
        'reference_type' => 'purchase_invoice_item',
        'reference_id' => 1,
    ]);

    return $batch->fresh();
}

it('a positive delta posts adjustment_in and increases on hand', function (): void {
    $variant = stadjVariant();
    $batch = stadjStockedBatch($variant, 10);
    $actor = stadjActor();

    $adjustment = app(StockAdjustmentService::class)->adjust(
        Warehouse::DEFAULT_CODE, $variant->id, $batch->id, 5, StockAdjustment::REASON_COUNT_CORRECTION, 'Recount found extra stock.', $actor,
    );

    expect($adjustment->adjustment_no)->toStartWith('ADJ-')
        ->and(app(StockLedger::class)->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(15);

    $movement = StockMovement::where('reference_type', 'stock_adjustment')->where('reference_id', $adjustment->id)->firstOrFail();
    expect($movement->type)->toBe(StockMovement::TYPE_ADJUSTMENT_IN)->and($movement->qty)->toBe(5);
});

it('a negative delta with a plain reason posts adjustment_out; a loss reason posts write_off', function (): void {
    $variant = stadjVariant();
    $batch = stadjStockedBatch($variant, 20);
    $actor = stadjActor();
    $service = app(StockAdjustmentService::class);

    $countCorrection = $service->adjust(Warehouse::DEFAULT_CODE, $variant->id, $batch->id, -3, StockAdjustment::REASON_COUNT_CORRECTION, 'Recount found less.', $actor);
    $movement1 = StockMovement::where('reference_id', $countCorrection->id)->where('reference_type', 'stock_adjustment')->firstOrFail();
    expect($movement1->type)->toBe(StockMovement::TYPE_ADJUSTMENT_OUT);

    $damaged = $service->adjust(Warehouse::DEFAULT_CODE, $variant->id, $batch->id, -4, StockAdjustment::REASON_DAMAGED, 'Water damage in transit.', $actor);
    $movement2 = StockMovement::where('reference_id', $damaged->id)->where('reference_type', 'stock_adjustment')->firstOrFail();
    expect($movement2->type)->toBe(StockMovement::TYPE_WRITE_OFF)->and($movement2->qty)->toBe(-4);

    expect(app(StockLedger::class)->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(13);
});

it('refuses a zero quantity, an unknown reason, and empty notes', function (): void {
    $variant = stadjVariant();
    $batch = stadjStockedBatch($variant, 10);
    $actor = stadjActor();
    $service = app(StockAdjustmentService::class);

    expect(fn () => $service->adjust(Warehouse::DEFAULT_CODE, $variant->id, $batch->id, 0, StockAdjustment::REASON_OTHER, 'Notes here.', $actor))
        ->toThrow(InvalidArgumentException::class, 'non-zero');

    expect(fn () => $service->adjust(Warehouse::DEFAULT_CODE, $variant->id, $batch->id, 1, 'not_a_real_reason', 'Notes here.', $actor))
        ->toThrow(InvalidArgumentException::class, 'Unknown adjustment reason');

    expect(fn () => $service->adjust(Warehouse::DEFAULT_CODE, $variant->id, $batch->id, 1, StockAdjustment::REASON_OTHER, '   ', $actor))
        ->toThrow(InvalidArgumentException::class, 'notes');
});
