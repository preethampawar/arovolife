<?php

declare(strict_types=1);

/**
 * The stock screen is `inventory.view` — open to both admin-operations and
 * admin-finance, unlike the writing screens which stay behind
 * `inventory.manage` (plan §7.1).
 */

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

function sisVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "SIS-{$n}", 'slug' => "sis-{$n}", 'name' => "SIS Widget {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "SIS-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets an operations admin and a finance admin view the stock screen with batches expanded', function (): void {
    $variant = sisVariant();
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'SIS-BATCH',
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id,
        'qty' => 25,
        'reference_type' => 'purchase_invoice_item',
        'reference_id' => 1,
    ]);

    $operations = User::factory()->create(['status' => 'active']);
    $operations->assignRole('admin-operations');

    $this->actingAs($operations)->get(route('admin.inventory.stock.index'))
        ->assertOk()
        ->assertSee($variant->variant_sku)
        ->assertSee('SIS-BATCH');

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    $this->actingAs($finance)->get(route('admin.inventory.stock.index'))->assertOk();

    $this->actingAs($operations)->get(route('admin.inventory.stock.index', ['q' => $variant->variant_sku]))
        ->assertOk()->assertSee($variant->variant_sku);
});

it('refuses a distributor who lacks inventory.view', function (): void {
    $distributor = User::factory()->create(['status' => 'active']);

    $this->actingAs($distributor)->get(route('admin.inventory.stock.index'))->assertForbidden();
});
