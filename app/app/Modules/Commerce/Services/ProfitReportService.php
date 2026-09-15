<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Support\SalesScope;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Services\CogsResolver;
use App\Modules\Inventory\Services\DTOs\LineCost;
use App\Modules\Inventory\Services\StockValuationService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Composes the profit views out of the services that own each figure. It
 * queries orders, cost and stock value through SalesReportService,
 * CogsResolver and StockValuationService rather than around them (plan D5) —
 * this class owns the arithmetic between those numbers, and nothing else.
 *
 * ## Purchases are not cost of goods sold
 *
 * The client asked for purchases, sales and profit in one report. Those three
 * do not subtract: buying 500 units in March and selling 20 of them does not
 * make March a loss. The only framing in which all three belong in one table
 * is the Trading Account, which reconciles them through stock:
 *
 *     opening stock + purchases - closing stock = cost of goods sold
 *     net sales - cost of goods sold             = gross profit
 *
 * That is what {@see tradingAccount()} returns, and it is why it carries
 * opening and closing stock the client did not ask for: without them the
 * other three numbers cannot be shown together honestly.
 */
final class ProfitReportService
{
    public function __construct(
        private readonly SalesReportService $sales,
        private readonly CogsResolver $cogs,
        private readonly StockValuationService $valuation,
        private readonly WalletService $wallet,
    ) {}

    /**
     * Company-wide only, and it says so in code rather than in a comment.
     *
     * Sales and COGS here honour the scope, but purchases, stock valuation and
     * commission outflow are company figures with no per-distributor meaning —
     * they cannot be narrowed, so a distributor-scoped caller would be handed
     * the company's supplier spend and total commission under their own name
     * (hard rule 3). Refusing the call is the only honest answer: a
     * distributor-scoped profit view needs its own method, not this one.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the scope is not the whole book
     */
    public function tradingAccount(
        SalesScope $scope,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        string $basis,
        ?string $warehouseCode = null,
    ): array {
        if (! $scope->isAll()) {
            throw new \InvalidArgumentException(
                'tradingAccount() reports company-wide purchases, stock and commission and cannot be scoped to one distributor.'
            );
        }

        $sales = $this->sales->totals($scope, $from, $to, $basis);
        $refunds = $this->sales->refundTotals($scope, $from, $to);

        $netSales = $sales['gross_ex_gst_paise'] - $refunds['gross_ex_gst_paise'];

        $cogs = $this->cogsForPeriod($scope, $from, $to, $basis);
        $refundedCogs = $this->refundedCogsForPeriod($scope, $from, $to);
        $netCogs = $cogs['cogs_paise'] - $refundedCogs['cogs_paise'];

        $purchases = $this->purchaseTotals($from, $to, $warehouseCode);

        // Opening is the balance the instant BEFORE the window opens, so an
        // inclusive `>= from` sales range and an exclusive opening balance
        // describe the same boundary rather than double-counting the first day.
        //
        // An open-ended start means "since the beginning", and nothing existed
        // then — passing null through to valueAtPaise() would instead ask for
        // EVERY movement ever, i.e. today's stock, and report the whole
        // warehouse as opening stock on an unfiltered report.
        $opening = $from === null ? 0 : $this->valuation->valueAtPaise($from->copy()->subSecond(), $warehouseCode);
        $closing = $this->valuation->valueAtPaise($to, $warehouseCode);

        $commission = $this->wallet->commissionTotalsByType($from, $to);
        $commissionTotal = array_sum($commission);

        $grossProfit = $netSales - $netCogs;

        return [
            'opening_stock_paise' => $opening,
            'purchases_paise' => $purchases['taxable_paise'],
            'purchase_charges_paise' => $purchases['charges_paise'],
            'landed_purchases_paise' => $purchases['landed_paise'],
            'purchase_gst_paise' => $purchases['gst_paise'],
            'closing_stock_paise' => $closing,
            // What the stock account says was consumed: everything that came in
            // less what is still here. COGS is read independently, from the
            // sales themselves, so the gap between the two is the cross-check.
            'implied_consumption_paise' => $opening + $purchases['landed_paise'] - $closing,
            'unvalued_qty' => $this->valuation->unvaluedQtyAt($to, $warehouseCode)
                - ($from === null ? 0 : $this->valuation->unvaluedQtyAt($from->copy()->subSecond(), $warehouseCode)),

            'gross_sales_paise' => $sales['gross_ex_gst_paise'],
            'refunds_paise' => $refunds['gross_ex_gst_paise'],
            'net_sales_paise' => $netSales,
            'discount_paise' => $sales['discount_paise'] - $refunds['discount_paise'],
            'points_paise' => $sales['points_paise'] - $refunds['points_paise'],
            'orders' => $sales['orders'],
            'refunded_orders' => $refunds['orders'],

            'cogs_paise' => $netCogs,
            // Stock consumed by something other than a sale: write-offs,
            // damage, samples, transfers out, and the paise lost to expressing
            // a line total as a per-unit cost. Shown, never absorbed into COGS
            // — a write-off charged to cost of sales makes the margin look
            // worse than trading actually was, and hides the loss.
            'reconciling_difference_paise' => ($opening + $purchases['landed_paise'] - $closing) - $netCogs,
            'gross_profit_paise' => $grossProfit,
            'margin_pct' => $this->pct($grossProfit, $netSales),
            'markup_pct' => $this->pct($grossProfit, $netCogs),

            'commission_by_type' => $commission,
            'commission_paise' => $commissionTotal,
            'contribution_paise' => $grossProfit - $commissionTotal,
            'contribution_pct' => $this->pct($grossProfit - $commissionTotal, $netSales),

            'shipping_collected_paise' => $sales['shipping_paise'] - $refunds['shipping_paise'],
            'gst_output_paise' => $sales['gst_paise'] - $refunds['gst_paise'],
            'gst_input_paise' => $purchases['gst_paise'],
            'bv_paise' => $this->bvForPeriod($scope, $from, $to, $basis),

            'estimated_lines' => $cogs['estimated_lines'],
            'total_lines' => $cogs['total_lines'],
        ];
    }

    /**
     * Profitability per variant, or per category when $groupByCategory.
     *
     * @return list<array<string, mixed>>
     */
    public function byProduct(
        SalesScope $scope,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        string $basis,
        bool $groupByCategory = false,
    ): array {
        $lines = $this->sales->soldLines($scope, $from, $to, $basis)
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->get([
                'order_items.id',
                'order_items.product_variant_id',
                'order_items.qty',
                'order_items.taxable_value_paise',
                'order_items.bv_paise',
                'product_variants.variant_sku',
                'products.name as product_name',
                'product_categories.name as category_name',
            ]);

        $refunded = $this->refundedLineValues($scope, $from, $to);
        $costs = $this->costsForLines($this->sales->soldLines($scope, $from, $to, $basis));

        $rows = [];

        foreach ($lines as $line) {
            $cost = $costs[(int) $line->id] ?? null;
            $category = $line->category_name ?? 'Uncategorised';

            $key = $groupByCategory ? $category : (int) $line->product_variant_id;
            $row = $rows[$key] ?? [
                'sku' => $groupByCategory ? '' : $line->variant_sku,
                'product' => $groupByCategory ? $category : $line->product_name,
                'category' => $category,
                'sold_qty' => 0,
                'net_sales_paise' => 0,
                'cogs_paise' => 0,
                'bv_paise' => 0,
                'bases' => [],
            ];

            $refundedValue = $refunded[(int) $line->id] ?? 0;

            $row['sold_qty'] += $cost !== null ? $cost->units : (int) $line->qty;
            $row['net_sales_paise'] += (int) $line->taxable_value_paise - $refundedValue;
            $row['cogs_paise'] += $cost !== null ? $cost->cogsPaise : 0;
            $row['bv_paise'] += (int) $line->qty * (int) $line->bv_paise;
            $row['bases'][] = $cost !== null ? $cost->basis : LineCost::BASIS_UNKNOWN;

            $rows[$key] = $row;
        }

        return $this->finaliseProductRows($rows);
    }

    /**
     * One row per sold line — the drill-down finance uses to check any
     * aggregate above.
     *
     * @return list<array<string, mixed>>
     */
    public function lineRegister(
        SalesScope $scope,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        string $basis,
        int $limit = 2000,
    ): array {
        // dateColumn() only ever returns one of two hardcoded column names, so
        // this is a fixed string despite reading like interpolation.
        $dateColumn = $basis === SalesReportService::BASIS_ORDERED
            ? 'orders.placed_at'
            : 'orders.shipped_at';

        $lines = $this->sales->soldLines($scope, $from, $to, $basis)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoin('distributors', 'distributors.id', '=', 'orders.attributed_distributor_id')
            ->orderByDesc($dateColumn)
            ->limit($limit)
            ->get([
                'order_items.id',
                'order_items.qty',
                'order_items.taxable_value_paise',
                'order_items.variant_sku_snapshot',
                'order_items.product_name_snapshot',
                'orders.order_no',
                'orders.status',
                DB::raw($basis === SalesReportService::BASIS_ORDERED
                    ? 'orders.placed_at as sold_at'
                    : 'orders.shipped_at as sold_at'),
                'customers.display_name as customer_name',
                'distributors.adn as distributor_adn',
            ]);

        $costs = $this->costsForLines($this->sales->soldLines($scope, $from, $to, $basis));

        /** @var list<array<string, mixed>> $mapped */
        $mapped = $lines->map(function (object $line) use ($costs): array {
            $cost = $costs[(int) $line->id] ?? null;
            $net = (int) $line->taxable_value_paise;
            $cogs = $cost !== null ? $cost->cogsPaise : 0;

            return [
                'sold_at' => $line->sold_at,
                'order_no' => $line->order_no,
                'customer' => $line->customer_name,
                'adn' => $line->distributor_adn ?? '',
                'sku' => $line->variant_sku_snapshot,
                'product' => $line->product_name_snapshot,
                'qty' => (int) $line->qty,
                'net_sale_paise' => $net,
                'cogs_paise' => $cogs,
                'gross_profit_paise' => $net - $cogs,
                'margin_pct' => $this->pct($net - $cogs, $net),
                'cost_basis' => $cost !== null ? $cost->label() : LineCost::LABELS[LineCost::BASIS_UNKNOWN],
                'status' => $line->status,
            ];
        })->values()->all();

        return $mapped;
    }

    /**
     * Posted goods receipts in the window.
     *
     * GST is excluded from the cost of goods — it is recoverable input credit,
     * not something the goods cost us — but reported alongside so the register
     * still reconciles to the supplier invoices.
     *
     * @return array{taxable_paise: int, charges_paise: int, landed_paise: int, gst_paise: int}
     */
    public function purchaseTotals(?CarbonInterface $from, ?CarbonInterface $to, ?string $warehouseCode = null): array
    {
        $row = DB::table('purchase_invoices')
            ->where('status', PurchaseInvoice::STATUS_POSTED)
            ->when($warehouseCode !== null && $warehouseCode !== '', fn ($q) => $q->where('warehouse_code', $warehouseCode))
            ->when($from !== null, fn ($q) => $q->where('posted_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('posted_at', '<=', $to))
            ->selectRaw(
                'COALESCE(SUM(subtotal_paise), 0) as taxable_paise, '.
                'COALESCE(SUM(freight_paise + insurance_paise + handling_paise + other_charges_paise), 0) as charges_paise, '.
                'COALESCE(SUM(landed_total_paise), 0) as landed_paise, '.
                'COALESCE(SUM(gst_paise), 0) as gst_paise'
            )
            ->first();

        return [
            'taxable_paise' => (int) ($row->taxable_paise ?? 0),
            'charges_paise' => (int) ($row->charges_paise ?? 0),
            'landed_paise' => (int) ($row->landed_paise ?? 0),
            'gst_paise' => (int) ($row->gst_paise ?? 0),
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function finaliseProductRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $profit = $row['net_sales_paise'] - $row['cogs_paise'];
            /** @var list<string> $bases */
            $bases = array_values(array_unique($row['bases']));

            unset($row['bases']);

            $out[] = $row + [
                'gross_profit_paise' => $profit,
                'margin_pct' => $this->pct($profit, $row['net_sales_paise']),
                'markup_pct' => $this->pct($profit, $row['cogs_paise']),
                'avg_sale_rate_paise' => $row['sold_qty'] > 0 ? intdiv($row['net_sales_paise'], $row['sold_qty']) : 0,
                'landed_rate_paise' => $row['sold_qty'] > 0 ? intdiv($row['cogs_paise'], $row['sold_qty']) : 0,
                // A rolled-up row reports the weakest basis it contains, so an
                // aggregate can never look more certain than its parts.
                'cost_basis' => $this->weakestBasis($bases),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['gross_profit_paise'] <=> $a['gross_profit_paise']);

        return $out;
    }

    /** @param list<string> $bases */
    private function weakestBasis(array $bases): string
    {
        foreach ([LineCost::BASIS_UNKNOWN, LineCost::BASIS_ESTIMATED, LineCost::BASIS_DERIVED, LineCost::BASIS_SUPPLIER] as $weak) {
            if (in_array($weak, $bases, true)) {
                return LineCost::LABELS[$weak];
            }
        }

        return LineCost::LABELS[LineCost::BASIS_LANDED];
    }

    /**
     * @return array{cogs_paise: int, estimated_lines: int, total_lines: int}
     */
    private function cogsForPeriod(SalesScope $scope, ?CarbonInterface $from, ?CarbonInterface $to, string $basis): array
    {
        $orderIds = $this->sales->countedOrders($scope, $from, $to, $basis)->pluck('orders.id')->all();

        $cogs = 0;
        $estimated = 0;
        $total = 0;

        foreach ($this->cogs->forOrders(array_map('intval', array_values($orderIds))) as $line) {
            $cogs += $line->cogsPaise;
            $total++;

            if (! $line->isActual()) {
                $estimated++;
            }
        }

        return ['cogs_paise' => $cogs, 'estimated_lines' => $estimated, 'total_lines' => $total];
    }

    /** @return array{cogs_paise: int} */
    private function refundedCogsForPeriod(SalesScope $scope, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $orderIds = $this->sales->refundedOrders($scope, $from, $to)->pluck('orders.id')->all();

        $cogs = 0;

        foreach ($this->cogs->forOrders(array_map('intval', array_values($orderIds))) as $line) {
            $cogs += $line->cogsPaise;
        }

        return ['cogs_paise' => $cogs];
    }

    /**
     * @param  Builder  $lineQuery
     * @return array<int, LineCost>
     */
    private function costsForLines($lineQuery): array
    {
        $orderIds = (clone $lineQuery)->distinct()->pluck('order_items.order_id')->all();

        return $this->cogs->forOrders(array_map('intval', array_values($orderIds)));
    }

    /** @return array<int, int> refunded taxable value keyed by order_item id */
    private function refundedLineValues(SalesScope $scope, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return $this->sales->refundedLines($scope, $from, $to)
            ->pluck('order_items.taxable_value_paise', 'order_items.id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    private function bvForPeriod(SalesScope $scope, ?CarbonInterface $from, ?CarbonInterface $to, string $basis): int
    {
        return (int) $this->sales->soldLines($scope, $from, $to, $basis)
            ->selectRaw('COALESCE(SUM(order_items.qty * order_items.bv_paise), 0) as bv')
            ->value('bv');
    }

    /** One decimal place, and never a division by zero dressed up as 0%. */
    private function pct(int $numerator, int $denominator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        return round($numerator / $denominator * 100, 1);
    }
}
