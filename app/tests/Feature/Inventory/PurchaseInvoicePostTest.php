<?php

declare(strict_types=1);

/**
 * The GRN is the only place stock enters a warehouse from outside the ledger
 * (plan §4.5). Posting must create/merge batches and write `purchase_in`
 * movements; cancelling must reverse them, but only while nothing has sold.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pitActor(): int
{
    return User::factory()->create()->id;
}

function pitVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "PIT-{$n}", 'slug' => "pit-{$n}", 'name' => "PIT {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "PIT-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function pitSupplier(): Supplier
{
    return Supplier::create(['name' => 'PIT Supplier '.random_int(10000, 99999), 'status' => Supplier::STATUS_ACTIVE]);
}

it('posts a GRN: one batch per line (merging a repeated batch_no) and one purchase_in movement per line', function (): void {
    $variant = pitVariant();
    $supplier = pitSupplier();
    $actor = pitActor();

    $pi = app(PurchaseInvoiceService::class)->createDraft(
        [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => null,
            'warehouse_code' => Warehouse::DEFAULT_CODE,
            'supplier_invoice_no' => 'INV-1',
            'supplier_invoice_date' => now()->toDateString(),
        ],
        [
            ['product_variant_id' => $variant->id, 'batch_no' => 'B1', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 10, 'unit_cost_paise' => 50000, 'gst_rate_bp' => 1800],
            // Second receipt of the same batch on the same GRN — merges into one batch row.
            ['product_variant_id' => $variant->id, 'batch_no' => 'B1', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 5, 'unit_cost_paise' => 50000, 'gst_rate_bp' => 1800],
        ],
        $actor,
    );

    app(PurchaseInvoiceService::class)->post($pi, $actor);

    expect($pi->fresh()->status)->toBe(PurchaseInvoice::STATUS_POSTED);

    $batches = StockBatch::where('product_variant_id', $variant->id)->where('batch_no', 'B1')->get();
    expect($batches)->toHaveCount(1)
        ->and($batches->first()->qty_on_hand)->toBe(15);

    $movements = StockMovement::where('product_variant_id', $variant->id)->where('type', StockMovement::TYPE_PURCHASE_IN)->get();
    expect($movements)->toHaveCount(2)
        ->and((int) $movements->sum('qty'))->toBe(15)
        ->and($movements->first()->unit_cost_paise)->toBe(50000);

    expect(app(StockLedger::class)->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(15);
});

it('rolls the purchase order to partially_received then received as GRNs are posted against it', function (): void {
    $variantA = pitVariant();
    $variantB = pitVariant();
    $supplier = pitSupplier();
    $actor = pitActor();

    $po = app(PurchaseOrderService::class)->create(
        ['supplier_id' => $supplier->id, 'warehouse_code' => Warehouse::DEFAULT_CODE],
        [
            ['product_variant_id' => $variantA->id, 'qty_ordered' => 10, 'unit_cost_paise' => 50000],
            ['product_variant_id' => $variantB->id, 'qty_ordered' => 4, 'unit_cost_paise' => 30000],
        ],
        $actor,
    );
    app(PurchaseOrderService::class)->send($po, $actor);

    $piService = app(PurchaseInvoiceService::class);

    $firstGrn = $piService->createDraft(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'supplier_invoice_no' => 'INV-A', 'supplier_invoice_date' => now()->toDateString()],
        [['product_variant_id' => $variantA->id, 'batch_no' => 'A1', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 10, 'unit_cost_paise' => 50000, 'gst_rate_bp' => 1800]],
        $actor,
    );
    $piService->post($firstGrn, $actor);

    expect($po->fresh()->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED);

    $secondGrn = $piService->createDraft(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'supplier_invoice_no' => 'INV-B', 'supplier_invoice_date' => now()->toDateString()],
        [['product_variant_id' => $variantB->id, 'batch_no' => 'B1', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 4, 'unit_cost_paise' => 30000, 'gst_rate_bp' => 1800]],
        $actor,
    );
    $piService->post($secondGrn, $actor);

    expect($po->fresh()->status)->toBe(PurchaseOrder::STATUS_RECEIVED);
});

it('refuses to post an already-posted GRN, and cancelling before any sale writes a purchase_reversal', function (): void {
    $variant = pitVariant();
    $supplier = pitSupplier();
    $actor = pitActor();
    $piService = app(PurchaseInvoiceService::class);

    $pi = $piService->createDraft(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => null, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'supplier_invoice_no' => 'INV-2', 'supplier_invoice_date' => now()->toDateString()],
        [['product_variant_id' => $variant->id, 'batch_no' => 'C1', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 10, 'unit_cost_paise' => 50000, 'gst_rate_bp' => 1800]],
        $actor,
    );
    $piService->post($pi, $actor);

    expect(fn () => $piService->post($pi->fresh(), $actor))->toThrow(RuntimeException::class, 'already been posted');

    $piService->cancel($pi->fresh(), 'Wrong quantity entered', $actor);

    expect($pi->fresh()->status)->toBe(PurchaseInvoice::STATUS_CANCELLED)
        ->and(app(StockLedger::class)->onHand($variant->id, Warehouse::DEFAULT_CODE))->toBe(0);

    $reversal = StockMovement::where('product_variant_id', $variant->id)->where('type', StockMovement::TYPE_PURCHASE_REVERSAL)->first();
    expect($reversal)->not->toBeNull()
        ->and($reversal->qty)->toBe(-10)
        ->and($reversal->reason)->toBe('Wrong quantity entered');
});

it('refuses to cancel a GRN once stock from its batch has already sold', function (): void {
    $variant = pitVariant();
    $supplier = pitSupplier();
    $actor = pitActor();
    $piService = app(PurchaseInvoiceService::class);

    $pi = $piService->createDraft(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => null, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'supplier_invoice_no' => 'INV-3', 'supplier_invoice_date' => now()->toDateString()],
        [['product_variant_id' => $variant->id, 'batch_no' => 'D1', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 10, 'unit_cost_paise' => 50000, 'gst_rate_bp' => 1800]],
        $actor,
    );
    $piService->post($pi, $actor);

    $batch = StockBatch::where('product_variant_id', $variant->id)->where('batch_no', 'D1')->firstOrFail();
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_SALE_OUT,
        'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_id' => $batch->id,
        'qty' => -3,
        'reference_type' => 'order_item',
        'reference_id' => 999,
    ]);

    expect(fn () => $piService->cancel($pi->fresh(), 'Trying anyway', $actor))
        ->toThrow(RuntimeException::class, 'only 7 of the 10 received');

    expect($pi->fresh()->status)->toBe(PurchaseInvoice::STATUS_POSTED);
});

it('refuses the same supplier invoice number twice for one supplier', function (): void {
    $variant = pitVariant();
    $supplier = pitSupplier();
    $actor = pitActor();
    $piService = app(PurchaseInvoiceService::class);

    $piService->createDraft(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => null, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'supplier_invoice_no' => 'DUP-1', 'supplier_invoice_date' => now()->toDateString()],
        [['product_variant_id' => $variant->id, 'batch_no' => 'E1', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 1, 'unit_cost_paise' => 50000, 'gst_rate_bp' => 1800]],
        $actor,
    );

    expect(fn () => $piService->createDraft(
        ['supplier_id' => $supplier->id, 'purchase_order_id' => null, 'warehouse_code' => Warehouse::DEFAULT_CODE, 'supplier_invoice_no' => 'DUP-1', 'supplier_invoice_date' => now()->toDateString()],
        [['product_variant_id' => $variant->id, 'batch_no' => 'E2', 'mfg_date' => null, 'expiry_date' => null, 'qty' => 1, 'unit_cost_paise' => 50000, 'gst_rate_bp' => 1800]],
        $actor,
    ))->toThrow(QueryException::class);
});
