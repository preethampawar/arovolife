<?php

declare(strict_types=1);

/**
 * Plan §7.2 report 10 — closing must equal on_hand when the report covers
 * the variant's whole movement history (invariant 1 restated as a report).
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('reconciles: closing equals on_hand across a mix of movement types', function (): void {
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "SIO-{$n}", 'slug' => "sio-{$n}", 'name' => "SIO {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "SIO-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'SIO-BATCH',
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    $ledger = app(StockLedger::class);

    $ledger->post(['type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => 100, 'reference_type' => 'purchase_invoice_item', 'reference_id' => 1]);
    $ledger->post(['type' => StockMovement::TYPE_SALE_OUT, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => -30, 'reference_type' => 'order_item', 'reference_id' => 1]);
    $ledger->post(['type' => StockMovement::TYPE_RETURN_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => 5, 'reference_type' => 'return_request', 'reference_id' => 1]);
    $ledger->post(['type' => StockMovement::TYPE_ADJUSTMENT_OUT, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => -3, 'reason' => 'count_correction']);
    $ledger->post(['type' => StockMovement::TYPE_WRITE_OFF, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => -2, 'reason' => 'damaged']);

    $onHand = InventoryLevel::where('product_variant_id', $variant->id)->where('warehouse_code', Warehouse::DEFAULT_CODE)->value('on_hand');
    expect($onHand)->toBe(70);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin-operations');

    $response = $this->actingAs($admin)->get(route('admin.inventory.reports.stock-in-out', ['export' => 'csv']));
    $response->assertOk();

    $lines = array_filter(explode("\n", $response->streamedContent()));
    $row = null;
    foreach ($lines as $line) {
        if (str_contains($line, $variant->variant_sku)) {
            $row = str_getcsv($line);
        }
    }

    expect($row)->not->toBeNull();
    // Columns: SKU, Warehouse, Opening, +Purchases, +Returns, +Transfers in, -Sales, -Transfers out, ±Adjustments, Closing
    $closing = (int) end($row);
    expect($closing)->toBe($onHand);
});
