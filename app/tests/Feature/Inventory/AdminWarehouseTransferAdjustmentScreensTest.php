<?php

declare(strict_types=1);

/**
 * The S3 admin screens — warehouses, transfers, adjustments — behind
 * `inventory.manage`, exercised through the real HTTP routes and views the
 * same way AdminInventoryScreensTest covers S2's suppliers/PO/GRN screens.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function awtOperationsUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin-operations');

    return $user;
}

function awtVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "AWT-{$n}", 'slug' => "awt-{$n}", 'name' => "AWT {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "AWT-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets an operations admin create a warehouse, then archive and reactivate it', function (): void {
    $admin = awtOperationsUser();

    $this->actingAs($admin)->get(route('admin.inventory.warehouses.create'))->assertOk();

    $this->actingAs($admin)->post(route('admin.inventory.warehouses.store'), [
        'code' => 'HYD-01', 'name' => 'Hyderabad Warehouse', 'type' => Warehouse::TYPE_WAREHOUSE,
        'fulfils_orders' => '1', 'status' => Warehouse::STATUS_ACTIVE,
    ])->assertRedirect(route('admin.inventory.warehouses.index'));

    $warehouse = Warehouse::where('code', 'HYD-01')->firstOrFail();

    $this->actingAs($admin)->post(route('admin.inventory.warehouses.archive', $warehouse))
        ->assertRedirect(route('admin.inventory.warehouses.index'));
    expect($warehouse->fresh()->status)->toBe(Warehouse::STATUS_ARCHIVED);

    $this->actingAs($admin)->post(route('admin.inventory.warehouses.reactivate', $warehouse))
        ->assertRedirect(route('admin.inventory.warehouses.index'));
    expect($warehouse->fresh()->status)->toBe(Warehouse::STATUS_ACTIVE);
});

it('refuses to archive the default warehouse', function (): void {
    $admin = awtOperationsUser();
    $default = Warehouse::where('code', Warehouse::DEFAULT_CODE)->firstOrFail();

    $this->actingAs($admin)->post(route('admin.inventory.warehouses.archive', $default))
        ->assertRedirect(route('admin.inventory.warehouses.edit', $default));
    expect($default->fresh()->status)->toBe(Warehouse::STATUS_ACTIVE);
});

it('lets an operations admin walk a transfer through create → dispatch → receive via the real screens', function (): void {
    $admin = awtOperationsUser();
    $variant = awtVariant();
    $to = Warehouse::create(['code' => 'AWT-TO', 'name' => 'AWT Destination', 'type' => Warehouse::TYPE_WAREHOUSE, 'fulfils_orders' => true, 'status' => Warehouse::STATUS_ACTIVE]);

    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'AWT-BATCH',
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id, 'qty' => 10, 'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);

    $this->actingAs($admin)->get(route('admin.inventory.transfers.create'))->assertOk();

    $this->actingAs($admin)->post(route('admin.inventory.transfers.store'), [
        'from_warehouse_code' => Warehouse::DEFAULT_CODE,
        'to_warehouse_code' => $to->code,
        'lines' => [['product_variant_id' => $variant->id, 'stock_batch_id' => $batch->id, 'qty' => 6]],
    ])->assertRedirect();

    $transfer = StockTransfer::firstOrFail();

    $this->actingAs($admin)->post(route('admin.inventory.transfers.dispatch', $transfer))
        ->assertRedirect(route('admin.inventory.transfers.show', $transfer));
    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_DISPATCHED);

    $this->actingAs($admin)->post(route('admin.inventory.transfers.receive', $transfer))
        ->assertRedirect(route('admin.inventory.transfers.show', $transfer));
    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and(app(StockLedger::class)->onHand($variant->id, $to->code))->toBe(6);

    $this->actingAs($admin)->get(route('admin.inventory.transfers.show', $transfer))->assertOk();
});

it('lets an operations admin record a stock adjustment via the real screen', function (): void {
    $admin = awtOperationsUser();
    $variant = awtVariant();

    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_no' => 'AWT-ADJ-BATCH',
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id, 'qty' => 10, 'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);

    $this->actingAs($admin)->get(route('admin.inventory.adjustments.create'))->assertOk();

    $this->actingAs($admin)->post(route('admin.inventory.adjustments.store'), [
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'product_variant_id' => $variant->id,
        'stock_batch_id' => $batch->id,
        'qty_delta' => -2,
        'reason' => 'damaged',
        'notes' => 'Box crushed on the shelf.',
    ])->assertRedirect(route('admin.inventory.adjustments.index'));

    expect(app(StockLedger::class)->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(8);
});

it('refuses a distributor who lacks inventory.manage on the warehouse, transfer and adjustment screens', function (): void {
    $distributor = User::factory()->create(['status' => 'active']);

    $this->actingAs($distributor)->get(route('admin.inventory.warehouses.index'))->assertForbidden();
    $this->actingAs($distributor)->get(route('admin.inventory.transfers.index'))->assertForbidden();
    $this->actingAs($distributor)->get(route('admin.inventory.adjustments.index'))->assertForbidden();
});
