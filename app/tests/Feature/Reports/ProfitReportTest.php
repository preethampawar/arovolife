<?php

declare(strict_types=1);

/**
 * The claims this report makes, tested against hand-computed figures.
 *
 * The dangerous failures here are all quiet ones: a join that multiplies
 * revenue by line count, a margin inflated by GST, a cost that vanishes
 * because nothing was packed. None of them throw. So each gets its own test
 * with numbers worked out by hand in the comment.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Services\ProfitReportService;
use App\Modules\Commerce\Services\SalesReportService;
use App\Modules\Commerce\Support\SalesScope;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\CogsResolver;
use App\Modules\Inventory\Services\DTOs\LineCost;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\Support\XlsxReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function prVariant(int $bvPaise = 0, string $policy = 'track'): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create([
        'sku' => "PR-{$n}", 'slug' => "pr-{$n}", 'name' => "PR {$n}",
        'hsn_code' => '3004', 'status' => 'active',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "PR-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 49900, 'cost_paise' => 0,
        'landing_price_paise' => 0, 'bv_paise' => $bvPaise,
        'gst_rate_bp' => 1800, 'inventory_policy' => $policy, 'status' => 'active',
    ]);
}

function prCustomer(): Customer
{
    return Customer::create(['display_name' => 'Buyer '.random_int(1000, 9999)]);
}

/**
 * A shipped order. Money is GST-INCLUSIVE, mirroring CheckoutService: the
 * caller passes the inclusive line total and the GST extracted out of it.
 *
 * @param  list<array{variant: ProductVariant, qty: int, inclusive_paise: int}>  $lines
 */
function prShippedOrder(array $lines, ?string $shippedAt = null): Order
{
    $subtotal = array_sum(array_column($lines, 'inclusive_paise'));
    $gst = (int) round($subtotal * 1800 / 11800);

    $order = Order::create([
        'order_no' => 'ORD-'.random_int(100000, 999999),
        'idempotency_key' => 'idem-'.random_int(1000000, 9999999),
        'customer_id' => prCustomer()->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_SHIPPED,
        'self_consumption' => false,
        'subtotal_paise' => $subtotal,
        'gst_paise' => $gst,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => $subtotal,
        'placed_at' => $shippedAt ?? now(),
        'paid_at' => $shippedAt ?? now(),
        'shipped_at' => $shippedAt ?? now(),
    ]);

    foreach ($lines as $line) {
        $lineGst = (int) round($line['inclusive_paise'] * 1800 / 11800);

        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $line['variant']->id,
            'product_name_snapshot' => $line['variant']->name,
            'variant_sku_snapshot' => $line['variant']->variant_sku,
            'hsn_code_snapshot' => '3004',
            'qty' => $line['qty'],
            'unit_price_paise' => intdiv($line['inclusive_paise'], $line['qty']),
            'bv_paise' => $line['variant']->bv_paise,
            'gst_rate_bp' => 1800,
            'taxable_value_paise' => $line['inclusive_paise'] - $lineGst,
            'gst_paise' => $lineGst,
            'line_total_paise' => $line['inclusive_paise'],
        ]);
    }

    return $order;
}

/** Stock the variant and pack the order's lines against it, the way pack() does. */
function prPackAtCost(Order $order, int $unitCostPaise): void
{
    $ledger = app(StockLedger::class);

    /** @var OrderItem $item */
    foreach ($order->items as $item) {
        $batch = StockBatch::create([
            'product_variant_id' => $item->product_variant_id,
            'warehouse_code' => 'DEFAULT',
            'batch_no' => 'B-'.random_int(100000, 999999),
            'unit_cost_paise' => $unitCostPaise,
            'qty_on_hand' => 0,
            'received_at' => now(),
        ]);

        $ledger->post([
            'type' => StockMovement::TYPE_PURCHASE_IN,
            'variant_id' => $item->product_variant_id,
            'warehouse_code' => 'DEFAULT',
            'batch_id' => $batch->id,
            'qty' => $item->qty,
            'unit_cost_paise' => $unitCostPaise,
        ]);

        $ledger->post([
            'type' => StockMovement::TYPE_SALE_OUT,
            'variant_id' => $item->product_variant_id,
            'warehouse_code' => 'DEFAULT',
            'batch_id' => $batch->id,
            'qty' => -$item->qty,
            'unit_cost_paise' => $unitCostPaise,
            'reference_type' => 'order_item',
            'reference_id' => $item->id,
        ]);
    }
}

function prReport(): ProfitReportService
{
    return app(ProfitReportService::class);
}

it('computes gross profit on ex-GST value, not on what the customer paid', function (): void {
    $variant = prVariant();

    // 10 units at Rs 499 inclusive = Rs 4,990 paid.
    // Ex-GST: 499000 - round(499000*1800/11800) = 499000 - 76119 = 422881.
    // COGS at Rs 199 landed x 10 = 199000. Gross profit = 223881.
    $order = prShippedOrder([['variant' => $variant, 'qty' => 10, 'inclusive_paise' => 499_000]]);
    prPackAtCost($order, 19_900);

    $data = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    expect($data['net_sales_paise'])->toBe(422_881)
        ->and($data['cogs_paise'])->toBe(199_000)
        ->and($data['gross_profit_paise'])->toBe(223_881)
        // Had GST been left in, the margin would read 60.1% instead.
        ->and($data['margin_pct'])->toBe(52.9);
});

it('counts a multi-line order once, not once per line', function (): void {
    $a = prVariant();
    $b = prVariant();
    $c = prVariant();

    $order = prShippedOrder([
        ['variant' => $a, 'qty' => 1, 'inclusive_paise' => 118_000],
        ['variant' => $b, 'qty' => 1, 'inclusive_paise' => 118_000],
        ['variant' => $c, 'qty' => 1, 'inclusive_paise' => 118_000],
    ]);
    prPackAtCost($order, 50_000);

    $data = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    // 354000 inclusive -> 300000 ex-GST. A fan-out bug would report 900000.
    expect($data['orders'])->toBe(1)
        ->and($data['net_sales_paise'])->toBe(300_000)
        ->and($data['cogs_paise'])->toBe(150_000);
});

it('labels cost by where it actually came from', function (): void {
    $packed = prVariant();
    $order = prShippedOrder([['variant' => $packed, 'qty' => 2, 'inclusive_paise' => 118_000]]);
    prPackAtCost($order, 30_000);

    // Never packed, but the variant has a derived landing price.
    $derivedVariant = prVariant();
    $derivedVariant->update(['landing_price_paise' => 25_000]);
    $derivedOrder = prShippedOrder([['variant' => $derivedVariant, 'qty' => 2, 'inclusive_paise' => 118_000]]);

    // Never packed, no landing price, only a typed cost price.
    $estimatedVariant = prVariant();
    $estimatedVariant->update(['cost_paise' => 20_000]);
    $estimatedOrder = prShippedOrder([['variant' => $estimatedVariant, 'qty' => 2, 'inclusive_paise' => 118_000]]);

    // Nothing at all to go on.
    $unknownVariant = prVariant();
    $unknownOrder = prShippedOrder([['variant' => $unknownVariant, 'qty' => 2, 'inclusive_paise' => 118_000]]);

    $costs = app(CogsResolver::class)->forOrders([
        $order->id, $derivedOrder->id, $estimatedOrder->id, $unknownOrder->id,
    ]);

    $byVariant = collect($costs)->keyBy('productVariantId');

    // Packed from a batch that came from no GRN at all -> actual, but supplier-basis.
    expect($byVariant[$packed->id]->basis)->toBe(LineCost::BASIS_SUPPLIER)
        ->and($byVariant[$packed->id]->cogsPaise)->toBe(60_000)
        ->and($byVariant[$derivedVariant->id]->basis)->toBe(LineCost::BASIS_DERIVED)
        ->and($byVariant[$derivedVariant->id]->cogsPaise)->toBe(50_000)
        ->and($byVariant[$estimatedVariant->id]->basis)->toBe(LineCost::BASIS_ESTIMATED)
        ->and($byVariant[$estimatedVariant->id]->cogsPaise)->toBe(40_000)
        ->and($byVariant[$unknownVariant->id]->basis)->toBe(LineCost::BASIS_UNKNOWN)
        ->and($byVariant[$unknownVariant->id]->cogsPaise)->toBe(0);
});

it('counts how many lines are running on a guessed cost', function (): void {
    $packed = prVariant();
    $order = prShippedOrder([['variant' => $packed, 'qty' => 1, 'inclusive_paise' => 118_000]]);
    prPackAtCost($order, 30_000);

    $guessed = prVariant();
    $guessed->update(['landing_price_paise' => 25_000]);
    prShippedOrder([['variant' => $guessed, 'qty' => 1, 'inclusive_paise' => 118_000]]);

    $data = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    expect($data['total_lines'])->toBe(2)
        ->and($data['estimated_lines'])->toBe(1);
});

it('nets a return out of both the revenue and the cost', function (): void {
    $variant = prVariant();
    $order = prShippedOrder([['variant' => $variant, 'qty' => 10, 'inclusive_paise' => 499_000]]);
    prPackAtCost($order, 19_900);

    $before = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);
    expect($before['gross_profit_paise'])->toBe(223_881);

    // Refund approved: revenue reversed, and the goods come back into stock.
    $ledger = app(StockLedger::class);
    /** @var OrderItem $item */
    $item = $order->items()->firstOrFail();
    $batch = StockBatch::query()->where('product_variant_id', $variant->id)->firstOrFail();

    $ledger->post([
        'type' => StockMovement::TYPE_SALE_REVERSAL,
        'variant_id' => $variant->id,
        'warehouse_code' => 'DEFAULT',
        'batch_id' => $batch->id,
        'qty' => 10,
        'unit_cost_paise' => 19_900,
        'reference_type' => 'order_item',
        'reference_id' => $item->id,
    ]);

    $order->update(['status' => Order::STATUS_REFUND_APPROVED, 'refund_approved_at' => now()]);

    $after = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    // The sale leaves the counted set and reappears as a refund; both sides
    // reverse, so nothing is left stranded on either.
    expect($after['net_sales_paise'])->toBe(-422_881)
        ->and($after['cogs_paise'])->toBe(0)
        ->and($after['refunded_orders'])->toBe(1);
});

it('keeps a sale and its cost in the same period', function (): void {
    $variant = prVariant();

    $old = prShippedOrder([['variant' => $variant, 'qty' => 1, 'inclusive_paise' => 118_000]], now()->subMonths(2)->toDateTimeString());
    prPackAtCost($old, 50_000);

    $recent = prShippedOrder([['variant' => $variant, 'qty' => 1, 'inclusive_paise' => 118_000]]);
    prPackAtCost($recent, 50_000);

    $data = prReport()->tradingAccount(
        SalesScope::all(),
        now()->subDays(7)->startOfDay(),
        now()->endOfDay(),
        SalesReportService::BASIS_SHIPPED,
    );

    // Only the recent order — and crucially its cost came with it, so the
    // window shows a real margin rather than revenue with no cost behind it.
    expect($data['orders'])->toBe(1)
        ->and($data['net_sales_paise'])->toBe(100_000)
        ->and($data['cogs_paise'])->toBe(50_000);
});

it('excludes an unpaid order and a cancelled one', function (): void {
    $variant = prVariant();
    $counted = prShippedOrder([['variant' => $variant, 'qty' => 1, 'inclusive_paise' => 118_000]]);
    prPackAtCost($counted, 50_000);

    prShippedOrder([['variant' => $variant, 'qty' => 1, 'inclusive_paise' => 118_000]])
        ->update(['status' => Order::STATUS_PLACED, 'paid_at' => null]);
    prShippedOrder([['variant' => $variant, 'qty' => 1, 'inclusive_paise' => 118_000]])
        ->update(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()]);

    $data = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    expect($data['orders'])->toBe(1)
        ->and($data['net_sales_paise'])->toBe(100_000);
});

it('reports profit per product, worst margin last', function (): void {
    $good = prVariant();
    $poor = prVariant();

    $a = prShippedOrder([['variant' => $good, 'qty' => 1, 'inclusive_paise' => 118_000]]);
    prPackAtCost($a, 20_000);

    $b = prShippedOrder([['variant' => $poor, 'qty' => 1, 'inclusive_paise' => 118_000]]);
    prPackAtCost($b, 90_000);

    $rows = prReport()->byProduct(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['sku'])->toBe($good->variant_sku)
        ->and($rows[0]['gross_profit_paise'])->toBe(80_000)
        ->and($rows[0]['margin_pct'])->toBe(80.0)
        ->and($rows[1]['gross_profit_paise'])->toBe(10_000);
});

it('rolls up to category without re-implementing the query', function (): void {
    $one = prVariant();
    $two = prVariant();

    $a = prShippedOrder([['variant' => $one, 'qty' => 1, 'inclusive_paise' => 118_000]]);
    prPackAtCost($a, 20_000);
    $b = prShippedOrder([['variant' => $two, 'qty' => 1, 'inclusive_paise' => 118_000]]);
    prPackAtCost($b, 20_000);

    $rows = prReport()->byProduct(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED, groupByCategory: true);

    // Both products are uncategorised, so they collapse to one row.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['product'])->toBe('Uncategorised')
        ->and($rows[0]['net_sales_paise'])->toBe(200_000)
        ->and($rows[0]['cogs_paise'])->toBe(40_000);
});

it('never divides by zero when there were no sales', function (): void {
    $data = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    expect($data['net_sales_paise'])->toBe(0)
        ->and($data['margin_pct'])->toBeNull()
        ->and($data['markup_pct'])->toBeNull();
});

it('keeps a distributor scope out of another distributor rows', function (): void {
    $variant = prVariant();
    $order = prShippedOrder([['variant' => $variant, 'qty' => 1, 'inclusive_paise' => 118_000]]);
    prPackAtCost($order, 50_000);

    // Nobody is attributed, so a distributor-scoped read must see nothing.
    $rows = prReport()->byProduct(SalesScope::distributor(999_999), null, null, SalesReportService::BASIS_SHIPPED);
    $register = prReport()->lineRegister(SalesScope::distributor(999_999), null, null, SalesReportService::BASIS_SHIPPED);

    expect($rows)->toBeEmpty()
        ->and($register)->toBeEmpty();
});

it('refuses to compute a trading account for one distributor', function (): void {
    // Purchases, stock valuation and commission outflow are company figures:
    // narrowing the sales half while those stay company-wide would put the
    // company's supplier spend on a distributor's screen (hard rule 3).
    expect(fn () => prReport()->tradingAccount(SalesScope::distributor(999_999), null, null, SalesReportService::BASIS_SHIPPED))
        ->toThrow(InvalidArgumentException::class);

    $all = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    expect($all)->toHaveKeys(['purchases_paise', 'opening_stock_paise', 'closing_stock_paise', 'commission_paise']);
});

it('lets finance in and keeps compliance out of every profit view', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('admin-finance');

    $compliance = User::factory()->create();
    $compliance->assignRole('admin-compliance');

    foreach (['index', 'summary', 'by-product', 'by-category', 'register'] as $view) {
        $this->actingAs($finance)->get(route("admin.reports.profit.{$view}"))->assertOk();
        $this->actingAs($compliance)->get(route("admin.reports.profit.{$view}"))->assertForbidden();
    }

    // The landing-price trail is the same cost sheet one variant at a time, so
    // it keeps the same door (R-17) even though it lives under /catalog.
    $variant = prVariant();
    $history = route('admin.catalog.products.landing-price-history', $variant);

    $this->actingAs($finance)->get($history)->assertOk();
    $this->actingAs($compliance)->get($history)->assertForbidden();
});

it('gates every profit route on the profit permission, not merely the first', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $r): bool => str_starts_with((string) $r->getName(), 'admin.reports.profit.'));

    expect($routes)->not->toBeEmpty();

    $routes->each(function (Route $route): void {
        expect($route->gatherMiddleware())->toContain('can:profit.report.view');
    });
});

it('reconciles: opening stock plus purchases minus closing stock equals cost of goods sold', function (): void {
    $variant = prVariant();
    $actor = User::factory()->create()->id;
    $ledger = app(StockLedger::class);

    // A real goods receipt: 100 units at Rs 190, plus Rs 900 of freight,
    // landing at Rs 199.00 each. Total landed 19,90,000 paise.
    $supplier = Supplier::create(['name' => 'REC Supplier', 'status' => Supplier::STATUS_ACTIVE]);
    $service = app(PurchaseInvoiceService::class);

    $grn = $service->post($service->createDraft(
        [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => null,
            'warehouse_code' => 'DEFAULT',
            'supplier_invoice_no' => 'SI-REC-'.random_int(1000, 9999),
            'supplier_invoice_date' => now()->toDateString(),
            'freight_paise' => 90_000,
        ],
        [[
            'product_variant_id' => $variant->id,
            'batch_no' => 'REC-1',
            'mfg_date' => null, 'expiry_date' => null,
            'qty' => 100, 'unit_cost_paise' => 19_000, 'gst_rate_bp' => 1800,
        ]],
        $actor,
    ), $actor);

    expect($grn->fresh()->landed_total_paise)->toBe(19_90_000);

    $batch = StockBatch::query()->where('product_variant_id', $variant->id)->firstOrFail();
    expect($batch->unit_cost_paise)->toBe(19_900);

    // Sell 30.
    $order = prShippedOrder([['variant' => $variant, 'qty' => 30, 'inclusive_paise' => 14_97_000]]);
    /** @var OrderItem $item */
    $item = $order->items()->firstOrFail();
    $ledger->post([
        'type' => StockMovement::TYPE_SALE_OUT, 'variant_id' => $variant->id,
        'warehouse_code' => 'DEFAULT', 'batch_id' => $batch->id,
        'qty' => -30, 'unit_cost_paise' => 19_900,
        'reference_type' => 'order_item', 'reference_id' => $item->id,
    ]);

    // Write off 5 — the movement type that carried no cost at all until this
    // change, and the one that silently broke this identity when it did not.
    app(StockAdjustmentService::class)->adjust(
        warehouseCode: 'DEFAULT',
        variantId: $variant->id,
        batchId: $batch->id,
        qtyDelta: -5,
        reason: 'damaged',
        notes: 'Crushed in transit',
        actorUserId: $actor,
    );

    $data = prReport()->tradingAccount(SalesScope::all(), null, null, SalesReportService::BASIS_SHIPPED);

    // opening 0 + landed purchases 19,90,000 - closing (65 x 19,900 = 12,93,500)
    //   = 6,96,500 consumed, of which 5,97,000 was sold and 99,500 written off.
    expect($data['opening_stock_paise'])->toBe(0)
        ->and($data['landed_purchases_paise'])->toBe(19_90_000)
        ->and($data['closing_stock_paise'])->toBe(12_93_500)
        ->and($data['implied_consumption_paise'])->toBe(6_96_500)
        ->and($data['cogs_paise'])->toBe(5_97_000)
        // The identity holds exactly, and the gap is the write-off — named,
        // not folded into the cost of the goods that actually sold.
        ->and($data['reconciling_difference_paise'])->toBe(99_500)
        ->and($data['unvalued_qty'])->toBe(0);
});

it('exports every view as a real workbook, and as CSV', function (): void {
    $variant = prVariant();
    $order = prShippedOrder([['variant' => $variant, 'qty' => 10, 'inclusive_paise' => 499_000]]);
    prPackAtCost($order, 19_900);

    $finance = User::factory()->create();
    $finance->assignRole('admin-finance');

    foreach (['summary', 'by-product', 'by-category', 'register'] as $view) {
        $xlsx = $this->actingAs($finance)
            ->get(route("admin.reports.profit.{$view}", ['format' => 'xlsx']))
            ->assertOk()
            ->streamedContent();

        $rows = XlsxReader::rows($xlsx);
        expect($rows)->not->toBeEmpty();

        $this->actingAs($finance)
            ->get(route("admin.reports.profit.{$view}", ['format' => 'csv']))
            ->assertOk();
    }
});

it('writes an audit row when a cost sheet leaves the building', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('admin-finance');

    $before = AuditLog::query()->where('action', 'profit.report.exported')->count();

    $this->actingAs($finance)
        ->get(route('admin.reports.profit.by-product', ['format' => 'xlsx']))
        ->assertOk()
        ->streamedContent();

    expect(AuditLog::query()->where('action', 'profit.report.exported')->count())->toBe($before + 1);
});

it('exports money ungrouped so a spreadsheet reads it as a number', function (): void {
    $variant = prVariant();
    $order = prShippedOrder([['variant' => $variant, 'qty' => 10, 'inclusive_paise' => 499_000]]);
    prPackAtCost($order, 19_900);

    $finance = User::factory()->create();
    $finance->assignRole('admin-finance');

    $rows = XlsxReader::rows(
        $this->actingAs($finance)
            ->get(route('admin.reports.profit.by-product', ['format' => 'xlsx']))
            ->assertOk()
            ->streamedContent()
    );

    // 4228.81 as a bare number, never "₹4,228.81".
    expect(XlsxReader::anyCellContains($rows, '4228.81'))->toBeTrue();
});
