<?php

declare(strict_types=1);

/**
 * The end-to-end claim behind the whole profit report: what the business
 * actually paid to get goods into the warehouse ends up as the batch cost, as
 * the variant's landing price, and therefore as the cost of every sale made
 * from that stock — with the change recorded.
 *
 * Before this, freight lived only in an admin's head and a hand-typed field
 * nothing read.
 */

use App\Modules\Catalog\Models\LandingPriceHistory;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\LandingPriceService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lcVariant(int $costPaise = 18_000): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "LC-{$n}", 'slug' => "lc-{$n}", 'name' => "LC {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "LC-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 49900, 'cost_paise' => $costPaise,
        'landing_price_paise' => 0, 'weight_g' => 500,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

/** @param list<array{variant: ProductVariant, qty: int, unit_cost_paise: int}> $lines */
function lcPostGrn(array $lines, int $chargesPaise, int $actorId, string $basis = 'value'): PurchaseInvoice
{
    $supplier = Supplier::create(['name' => 'LC Supplier '.random_int(10000, 99999), 'status' => Supplier::STATUS_ACTIVE]);
    $service = app(PurchaseInvoiceService::class);

    $pi = $service->createDraft(
        [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => null,
            'warehouse_code' => 'DEFAULT',
            'supplier_invoice_no' => 'SI-'.random_int(100000, 999999),
            'supplier_invoice_date' => now()->toDateString(),
            'freight_paise' => $chargesPaise,
            'insurance_paise' => 0,
            'handling_paise' => 0,
            'other_charges_paise' => 0,
            'allocation_basis' => $basis,
        ],
        array_map(static fn (array $l): array => [
            'product_variant_id' => $l['variant']->id,
            'batch_no' => 'B-'.random_int(100000, 999999),
            'mfg_date' => null,
            'expiry_date' => null,
            'qty' => $l['qty'],
            'unit_cost_paise' => $l['unit_cost_paise'],
            'gst_rate_bp' => 1800,
        ], $lines),
        $actorId,
    );

    return $service->post($pi, $actorId);
}

it('turns supplier price plus freight into the batch cost, the landing price and the purchase_in cost', function (): void {
    $actor = User::factory()->create()->id;
    $variant = lcVariant();

    // 500 units at Rs 180, Rs 9,500 of freight -> Rs 199.00 landed.
    $pi = lcPostGrn([['variant' => $variant, 'qty' => 500, 'unit_cost_paise' => 18_000]], 9_50_000, $actor);

    expect($pi->fresh()->landed_total_paise)->toBe(90_00_000 + 9_50_000);

    $item = $pi->items()->first();
    expect($item->unit_cost_paise)->toBe(18_000)          // what the supplier charged
        ->and($item->landed_unit_cost_paise)->toBe(19_900); // what it actually cost us

    $batch = StockBatch::query()->where('product_variant_id', $variant->id)->firstOrFail();
    expect($batch->unit_cost_paise)->toBe(19_900);

    $movement = StockMovement::query()
        ->where('type', StockMovement::TYPE_PURCHASE_IN)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();
    expect($movement->unit_cost_paise)->toBe(19_900);

    expect($variant->fresh()->landing_price_paise)->toBe(19_900);
});

it('records the landing price change with both sides of it', function (): void {
    $actor = User::factory()->create()->id;
    $variant = lcVariant();

    lcPostGrn([['variant' => $variant, 'qty' => 500, 'unit_cost_paise' => 18_000]], 9_50_000, $actor);

    $row = LandingPriceHistory::query()->where('product_variant_id', $variant->id)->sole();

    expect($row->old_paise)->toBe(0)
        ->and($row->new_paise)->toBe(19_900)
        ->and($row->source)->toBe(LandingPriceHistory::SOURCE_GRN)
        ->and($row->purchase_invoice_id)->not->toBeNull()
        ->and($row->changed_by_user_id)->toBe($actor);
});

it('weights the landing price by the stock actually on hand, not by the last price paid', function (): void {
    $actor = User::factory()->create()->id;
    $variant = lcVariant();

    // 400 units landing at Rs 199, then 100 more landing at Rs 210.
    lcPostGrn([['variant' => $variant, 'qty' => 400, 'unit_cost_paise' => 19_900]], 0, $actor);
    lcPostGrn([['variant' => $variant, 'qty' => 100, 'unit_cost_paise' => 21_000]], 0, $actor);

    // Weighted: (400*19900 + 100*21000) / 500 = 20120, not the latest 21000.
    expect($variant->fresh()->landing_price_paise)->toBe(20_120);

    expect(LandingPriceHistory::query()->where('product_variant_id', $variant->id)->count())->toBe(2);
});

it('moves the landing price back when a receipt is cancelled', function (): void {
    $actor = User::factory()->create()->id;
    $variant = lcVariant();

    lcPostGrn([['variant' => $variant, 'qty' => 400, 'unit_cost_paise' => 19_900]], 0, $actor);
    $second = lcPostGrn([['variant' => $variant, 'qty' => 100, 'unit_cost_paise' => 21_000]], 0, $actor);

    expect($variant->fresh()->landing_price_paise)->toBe(20_120);

    app(PurchaseInvoiceService::class)->cancel($second, 'Wrong consignment', $actor);

    expect($variant->fresh()->landing_price_paise)->toBe(19_900);
});

it('spreads one consignment of freight across lines by value', function (): void {
    $actor = User::factory()->create()->id;
    $cheap = lcVariant();
    $dear = lcVariant();

    // Line values 10,000 and 30,000 paise -> freight splits 1:3.
    lcPostGrn([
        ['variant' => $cheap, 'qty' => 10, 'unit_cost_paise' => 1_000],
        ['variant' => $dear, 'qty' => 10, 'unit_cost_paise' => 3_000],
    ], 8_000, $actor);

    // cheap: (10000 + 2000)/10 = 1200 ; dear: (30000 + 6000)/10 = 3600
    expect($cheap->fresh()->landing_price_paise)->toBe(1_200)
        ->and($dear->fresh()->landing_price_paise)->toBe(3_600);
});

it('leaves the landing price editable until the first receipt, then takes it over', function (): void {
    $actor = User::factory()->create()->id;
    $variant = lcVariant();
    $service = app(LandingPriceService::class);

    expect($service->isDerivable($variant->id))->toBeFalse();

    $service->recordManual($variant, 15_000, $actor);
    expect($variant->fresh()->landing_price_paise)->toBe(15_000);

    lcPostGrn([['variant' => $variant, 'qty' => 10, 'unit_cost_paise' => 18_000]], 0, $actor);

    expect($service->isDerivable($variant->id))->toBeTrue()
        ->and($variant->fresh()->landing_price_paise)->toBe(18_000);

    // Still derivable once the stock has all gone: the system knows the cost.
    StockBatch::query()->where('product_variant_id', $variant->id)->update(['qty_on_hand' => 0]);
    expect($service->isDerivable($variant->id))->toBeTrue();
});

it('does not write a history row when a receipt does not move the price', function (): void {
    $actor = User::factory()->create()->id;
    $variant = lcVariant();

    lcPostGrn([['variant' => $variant, 'qty' => 100, 'unit_cost_paise' => 19_900]], 0, $actor);
    lcPostGrn([['variant' => $variant, 'qty' => 100, 'unit_cost_paise' => 19_900]], 0, $actor);

    expect(LandingPriceHistory::query()->where('product_variant_id', $variant->id)->count())->toBe(1);
});
