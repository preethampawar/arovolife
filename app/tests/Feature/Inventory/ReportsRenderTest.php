<?php

declare(strict_types=1);

/**
 * Plan §7.2 — every report renders (permission `inventory.view`) and its CSV
 * export starts with a header row, whatever data exists.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function rrtVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "RRT-{$n}", 'slug' => "rrt-{$n}", 'name' => "RRT {$n}", 'hsn_code' => '3004', 'status' => 'active']);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "RRT-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

/** @return array<int, string> */
function rrtReportRoutes(): array
{
    return [
        'admin.inventory.reports.stock-on-hand',
        'admin.inventory.reports.movements',
        'admin.inventory.reports.batch-expiry',
        'admin.inventory.reports.low-stock',
        'admin.inventory.reports.valuation',
        'admin.inventory.reports.purchase-register',
        'admin.inventory.reports.transfer-register',
        'admin.inventory.reports.order-fulfilment',
        'admin.inventory.reports.returns-restock',
        'admin.inventory.reports.stock-in-out',
    ];
}

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(LedgerAccountSeeder::class);
});

it('renders the reports index and every report for an operations admin', function (): void {
    $variant = rrtVariant();
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'RRT-BATCH',
        'expiry_date' => now()->addDays(10)->toDateString(), 'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => 25,
        'unit_cost_paise' => 60000, 'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin-operations');

    $this->actingAs($admin)->get(route('admin.inventory.reports.index'))->assertOk();

    foreach (rrtReportRoutes() as $routeName) {
        $this->actingAs($admin)->get(route($routeName))->assertOk();
    }
});

it('exports every report as CSV with a header row', function (): void {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin-operations');

    foreach (rrtReportRoutes() as $routeName) {
        $response = $this->actingAs($admin)->get(route($routeName, ['export' => 'csv']));

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $firstLine = strtok($response->streamedContent(), "\n");
        expect($firstLine)->not->toBeEmpty()->and(str_contains((string) $firstLine, ','))->toBeTrue();
    }
});

it('refuses a distributor who lacks inventory.view', function (): void {
    $distributor = User::factory()->create(['status' => 'active']);

    $this->actingAs($distributor)->get(route('admin.inventory.reports.index'))->assertForbidden();
});
