<?php

declare(strict_types=1);

/**
 * The admin screens for suppliers, purchase orders and GRNs (plan §7.1) are
 * gated by `inventory.manage` and wired through their own routes/views. This
 * proves the HTTP surface actually renders and the permission gate holds —
 * the service-level tests in PurchaseInvoicePostTest cover the business logic.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function aisOperationsUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin-operations');

    return $user;
}

function aisVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "AIS-{$n}", 'slug' => "ais-{$n}", 'name' => "AIS {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "AIS-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets an operations admin walk supplier → purchase order → GRN through the real screens', function (): void {
    $admin = aisOperationsUser();
    $variant = aisVariant();

    $this->actingAs($admin)->get(route('admin.inventory.suppliers.create'))->assertOk();

    $this->actingAs($admin)->post(route('admin.inventory.suppliers.store'), [
        'name' => 'Acme Nutraceuticals', 'status' => Supplier::STATUS_ACTIVE,
    ])->assertRedirect(route('admin.inventory.suppliers.index'));

    $supplier = Supplier::firstOrFail();

    $this->actingAs($admin)->get(route('admin.inventory.purchase-orders.create'))->assertOk();

    $this->actingAs($admin)->post(route('admin.inventory.purchase-orders.store'), [
        'supplier_id' => $supplier->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'lines' => [['product_variant_id' => $variant->id, 'qty_ordered' => 10, 'unit_cost' => 500]],
    ])->assertRedirect();

    $po = PurchaseOrder::firstOrFail();
    expect($po->items)->toHaveCount(1);

    $this->actingAs($admin)->post(route('admin.inventory.purchase-orders.send', $po))
        ->assertRedirect(route('admin.inventory.purchase-orders.show', $po));
    expect($po->fresh()->status)->toBe(PurchaseOrder::STATUS_SENT);

    $this->actingAs($admin)->get(route('admin.inventory.grns.create', ['purchase_order_id' => $po->id]))->assertOk();

    $this->actingAs($admin)->post(route('admin.inventory.grns.store'), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $po->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'supplier_invoice_no' => 'INV-100',
        'supplier_invoice_date' => now()->toDateString(),
        'lines' => [[
            'product_variant_id' => $variant->id, 'batch_no' => 'BATCH-1',
            'mfg_date' => null, 'expiry_date' => null,
            'qty' => 10, 'unit_cost' => 500, 'gst_rate' => 18,
        ]],
    ])->assertRedirect();

    $grn = $po->purchaseInvoices()->firstOrFail();

    $this->actingAs($admin)->post(route('admin.inventory.grns.post', $grn))
        ->assertRedirect(route('admin.inventory.grns.show', $grn));

    expect($grn->fresh()->status)->toBe(PurchaseInvoice::STATUS_POSTED)
        ->and($po->fresh()->status)->toBe(PurchaseOrder::STATUS_RECEIVED);

    $this->actingAs($admin)->get(route('admin.inventory.grns.show', $grn))->assertOk()->assertSee('INV-100');
});

it('refuses a distributor who lacks inventory.manage', function (): void {
    $distributor = User::factory()->create(['status' => 'active']);

    $this->actingAs($distributor)->get(route('admin.inventory.suppliers.index'))->assertForbidden();
});
