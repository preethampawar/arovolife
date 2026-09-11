<?php

declare(strict_types=1);

/**
 * `inventory:verify` is the safety net: it re-derives both projections from the
 * ledger and fails on any disagreement. The test that matters is the one where
 * somebody writes `on_hand` behind the ledger's back — which is exactly what
 * the admin product form used to do.
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function vcVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "VC-{$n}", 'slug' => "vc-{$n}", 'name' => "VC {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "VC-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 50000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function vcStockedVariant(int $qty): ProductVariant
{
    $variant = vcVariant();

    $batch = StockBatch::create([
        'product_variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_no' => 'VC-B1',
        'unit_cost_paise' => 50000,
        'qty_on_hand' => 0,
        'received_at' => now(),
    ]);

    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id,
        'qty' => $qty,
    ]);

    return $variant;
}

it('passes when every projection agrees with the ledger', function (): void {
    vcStockedVariant(25);

    expect(Artisan::call('inventory:verify'))->toBe(0)
        ->and(Artisan::output())->toContain('agree with the movement ledger');
});

it('passes on an empty database', function (): void {
    expect(Artisan::call('inventory:verify'))->toBe(0);
});

it('fails when a level was written behind the ledger', function (): void {
    $variant = vcStockedVariant(25);

    InventoryLevel::where('product_variant_id', $variant->id)->update(['on_hand' => 99]);

    expect(Artisan::call('inventory:verify'))->toBe(1);
});

it('fails when a batch projection was written behind the ledger', function (): void {
    $variant = vcStockedVariant(25);

    StockBatch::where('product_variant_id', $variant->id)->update(['qty_on_hand' => 1]);

    expect(Artisan::call('inventory:verify'))->toBe(1);
});

it('fails when movements exist with no level row to project onto', function (): void {
    $variant = vcStockedVariant(25);

    InventoryLevel::where('product_variant_id', $variant->id)->delete();

    expect(Artisan::call('inventory:verify'))->toBe(1);
});
