<?php

declare(strict_types=1);

/**
 * Choosing a purchase order on the GRN create form fills the line table with
 * what that order still has outstanding.
 *
 * The prefill logic already existed but was only reachable by opening the page
 * with a `?purchase_order_id` in the query string, so a receiver who picked the
 * order from the dropdown — the obvious way — got an empty table and retyped
 * every line. These tests cover the endpoint the dropdown now calls, and in
 * particular the part worth getting wrong: it must offer what is still OWED,
 * not what was originally ordered.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function gpolStaff(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function gpolVariant(int $gstRateBp = 1800): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "GPOL-{$n}", 'slug' => "gpol-{$n}", 'name' => "GPOL {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "GPOL-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => $gstRateBp, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

/** @param  list<array{product_variant_id: int, qty_ordered: int, unit_cost_paise: int}>  $lines */
function gpolSentPo(array $lines, int $actor): PurchaseOrder
{
    $supplier = Supplier::create([
        'name' => 'GPOL Supplier '.random_int(10000, 99999),
        'status' => Supplier::STATUS_ACTIVE,
    ]);

    $po = app(PurchaseOrderService::class)->create(
        ['supplier_id' => $supplier->id, 'warehouse_code' => Warehouse::DEFAULT_CODE],
        $lines,
        $actor,
    );

    return app(PurchaseOrderService::class)->send($po, $actor);
}

it('GPOL-01: returns the order\'s outstanding lines, its supplier and its warehouse', function (): void {
    $staff = gpolStaff('admin-operations');
    $variant = gpolVariant();

    $po = gpolSentPo(
        [['product_variant_id' => $variant->id, 'qty_ordered' => 20, 'unit_cost_paise' => 10000]],
        $staff->id,
    );

    $this->actingAs($staff)
        ->getJson(route('admin.inventory.grns.po-lines', $po))
        ->assertOk()
        ->assertJsonPath('supplier_id', $po->supplier_id)
        ->assertJsonPath('warehouse_code', Warehouse::DEFAULT_CODE)
        ->assertJsonPath('lines.0.product_variant_id', $variant->id)
        ->assertJsonPath('lines.0.qty', 20)
        ->assertJsonPath('lines.0.unit_cost', '100.00')
        ->assertJsonPath('lines.0.gst_rate', '18.00')
        // The consignment has not arrived yet, so nothing can know these.
        ->assertJsonPath('lines.0.batch_no', '')
        ->assertJsonPath('lines.0.mfg_date', null)
        ->assertJsonPath('lines.0.expiry_date', null);
});

it('GPOL-02: offers only the remainder once part of the order has been received', function (): void {
    $staff = gpolStaff('admin-operations');
    $variant = gpolVariant();

    $po = gpolSentPo(
        [['product_variant_id' => $variant->id, 'qty_ordered' => 20, 'unit_cost_paise' => 10000]],
        $staff->id,
    );

    // Receive 8 of the 20 and post it, which rolls the order's received qty.
    $grn = app(PurchaseInvoiceService::class)->createDraft(
        [
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'warehouse_code' => Warehouse::DEFAULT_CODE,
            'supplier_invoice_no' => 'GPOL-INV-1',
            'supplier_invoice_date' => now()->toDateString(),
        ],
        [['product_variant_id' => $variant->id, 'batch_no' => 'B1', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 8, 'unit_cost_paise' => 10000, 'gst_rate_bp' => 1800]],
        $staff->id,
    );
    app(PurchaseInvoiceService::class)->post($grn, $staff->id);

    // 12 still owed — not the 20 originally ordered. Prefilling 20 here would
    // have the receiver book twelve units of stock that never turned up.
    $this->actingAs($staff)
        ->getJson(route('admin.inventory.grns.po-lines', $po->fresh()))
        ->assertOk()
        ->assertJsonPath('lines.0.qty', 12);
});

it('GPOL-03: drops a line that has been received in full rather than offering a zero', function (): void {
    $staff = gpolStaff('admin-operations');
    $done = gpolVariant();
    $partial = gpolVariant();

    $po = gpolSentPo(
        [
            ['product_variant_id' => $done->id, 'qty_ordered' => 5, 'unit_cost_paise' => 10000],
            ['product_variant_id' => $partial->id, 'qty_ordered' => 5, 'unit_cost_paise' => 10000],
        ],
        $staff->id,
    );

    $grn = app(PurchaseInvoiceService::class)->createDraft(
        [
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'warehouse_code' => Warehouse::DEFAULT_CODE,
            'supplier_invoice_no' => 'GPOL-INV-2',
            'supplier_invoice_date' => now()->toDateString(),
        ],
        [['product_variant_id' => $done->id, 'batch_no' => 'B2', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 5, 'unit_cost_paise' => 10000, 'gst_rate_bp' => 1800]],
        $staff->id,
    );
    app(PurchaseInvoiceService::class)->post($grn, $staff->id);

    $response = $this->actingAs($staff)
        ->getJson(route('admin.inventory.grns.po-lines', $po->fresh()))
        ->assertOk();

    $response->assertJsonCount(1, 'lines');
    $response->assertJsonPath('lines.0.product_variant_id', $partial->id);
});

it('GPOL-04: refuses an order that was never sent', function (): void {
    $staff = gpolStaff('admin-operations');
    $variant = gpolVariant();

    $supplier = Supplier::create([
        'name' => 'GPOL Draft Supplier',
        'status' => Supplier::STATUS_ACTIVE,
    ]);

    // Created but never sent: the dropdown does not offer a draft, so neither
    // does the endpoint behind it.
    $draft = app(PurchaseOrderService::class)->create(
        ['supplier_id' => $supplier->id, 'warehouse_code' => Warehouse::DEFAULT_CODE],
        [['product_variant_id' => $variant->id, 'qty_ordered' => 3, 'unit_cost_paise' => 10000]],
        $staff->id,
    );

    expect($draft->status)->toBe(PurchaseOrder::STATUS_DRAFT);

    $this->actingAs($staff)
        ->getJson(route('admin.inventory.grns.po-lines', $draft))
        ->assertNotFound();
});

it('GPOL-06: the create form renders, with its action bar and its purchase-order picker', function (): void {
    $staff = gpolStaff('admin-operations');

    // Nothing rendered this page in a test, which is how it shipped with its
    // closing tags mangled — a `<div class="flex items-center gap-3">` whose
    // opening tag had been overwritten by `</x-ui.card>`, leaving the
    // attributes loose in the markup and the submit button collapsed into a
    // narrow vertical strip down the side of the page.
    $response = $this->actingAs($staff)->get(route('admin.inventory.grns.create'));

    $response->assertOk()
        ->assertSee('Save as draft')
        ->assertSee('name="purchase_order_id"', false)
        ->assertSee('id="grnLinesBody"', false)
        // The picker's failure notice must exist (hidden) for the script to reveal.
        ->assertSee('id="grnPoError"', false);
});

it('GPOL-07: opening the form against a purchase order still prefills it server-side', function (): void {
    $staff = gpolStaff('admin-operations');
    $variant = gpolVariant();

    $po = gpolSentPo(
        [['product_variant_id' => $variant->id, 'qty_ordered' => 7, 'unit_cost_paise' => 10000]],
        $staff->id,
    );

    // The deep link from the purchase-order screen predates the dropdown and
    // must keep working: both paths now share linesFromPurchaseOrder().
    //
    // Matched raw, with escaping off: the prefill is emitted by Blade's `@json`
    // inside a <script>, which encodes with no flags — so the quotes in the
    // markup are literal `"`, not `&quot;` and not `\u0022`.
    $this->actingAs($staff)
        ->get(route('admin.inventory.grns.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('"qty":7', false);
});

it('GPOL-05: is gated on inventory.manage like the rest of the GRN screens', function (): void {
    $staff = gpolStaff('admin-operations');
    $variant = gpolVariant();

    $po = gpolSentPo(
        [['product_variant_id' => $variant->id, 'qty_ordered' => 4, 'unit_cost_paise' => 10000]],
        $staff->id,
    );

    // admin-finance holds inventory.view but never inventory.manage, which is
    // what every write-side inventory screen is gated on.
    $this->actingAs(gpolStaff('admin-finance'))
        ->getJson(route('admin.inventory.grns.po-lines', $po))
        ->assertForbidden();
});
