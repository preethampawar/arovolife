<?php

declare(strict_types=1);

/**
 * `inventory:backfill-opening` is the one-off that gives pre-ledger stock a
 * history. The two things that matter: the number must not change, and a
 * second run must do nothing (the command is in a production runbook, where
 * "did I already run this?" is a question someone will get wrong).
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Console\Commands\BackfillOpeningStockCommand;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function bosVariantWithStock(int $onHand, int $reserved = 0): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "BOS-{$n}", 'slug' => "bos-{$n}", 'name' => "BOS {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "BOS-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 42000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
    InventoryLevel::create([
        'product_variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'on_hand' => $onHand,
        'reserved' => $reserved,
    ]);

    return $variant;
}

it('opens pre-ledger stock without changing the quantity', function (): void {
    $variant = bosVariantWithStock(500, 4);

    expect(Artisan::call('inventory:backfill-opening'))->toBe(0);

    $level = InventoryLevel::where('product_variant_id', $variant->id)->firstOrFail();
    $batch = StockBatch::where('product_variant_id', $variant->id)->firstOrFail();
    $movement = StockMovement::where('product_variant_id', $variant->id)->firstOrFail();

    expect($level->on_hand)->toBe(500)
        ->and($level->reserved)->toBe(4)
        ->and($batch->batch_no)->toBe(BackfillOpeningStockCommand::OPENING_BATCH_NO)
        ->and($batch->qty_on_hand)->toBe(500)
        ->and($batch->expiry_date)->toBeNull()
        // The cost comes from the variant — there is no supplier invoice behind
        // opening stock, so the catalogue cost is the only figure available.
        ->and($batch->unit_cost_paise)->toBe(42000)
        ->and($movement->type)->toBe(StockMovement::TYPE_OPENING)
        ->and($movement->qty)->toBe(500);
});

it('is idempotent — a second run opens nothing', function (): void {
    $variant = bosVariantWithStock(120);

    expect(Artisan::call('inventory:backfill-opening'))->toBe(0)
        ->and(Artisan::call('inventory:backfill-opening'))->toBe(0);

    expect(StockMovement::where('product_variant_id', $variant->id)->count())->toBe(1)
        ->and(InventoryLevel::where('product_variant_id', $variant->id)->value('on_hand'))->toBe(120);
});

it('leaves levels at zero and levels that already have movements alone', function (): void {
    bosVariantWithStock(0);

    expect(Artisan::call('inventory:backfill-opening'))->toBe(0);

    expect(StockMovement::count())->toBe(0)
        ->and(StockBatch::count())->toBe(0);
});

it('writes nothing on a dry run', function (): void {
    $variant = bosVariantWithStock(75);

    expect(Artisan::call('inventory:backfill-opening', ['--dry-run' => true]))->toBe(0);

    expect(StockMovement::count())->toBe(0)
        ->and(StockBatch::count())->toBe(0)
        ->and(InventoryLevel::where('product_variant_id', $variant->id)->value('on_hand'))->toBe(75);
});
