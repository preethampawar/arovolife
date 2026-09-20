<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Services\ProfitReportService;
use App\Modules\Commerce\Services\SalesReportService;
use App\Modules\Commerce\Support\SalesScope;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Shared\Support\IndianNumber;
use App\Modules\Shared\Support\ReportExport;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profit on sales — four views over one query layer (plan D4/D5).
 *
 * Admin-only and scoped to `SalesScope::all()`: these screens show what the
 * company pays its suppliers and earns per product. No distributor-facing
 * route reaches this controller, and none should.
 */
final class AdminProfitReportController extends Controller
{
    /** The register is a line-per-sale listing; both caps keep one request bounded. */
    private const REGISTER_SCREEN_LIMIT = 2000;

    private const REGISTER_EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly ProfitReportService $profit,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function index(): View
    {
        return view('admin.reports.profit.index');
    }

    public function summary(Request $request): View|StreamedResponse
    {
        [$from, $to, $basis, $warehouse] = $this->filters($request);

        $data = $this->profit->tradingAccount(SalesScope::all(), $from, $to, $basis, $warehouse);

        if ($this->wantsExport($request)) {
            return $this->export($request, 'profit-summary', [
                ['key' => 'line', 'label' => 'Line'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ], $this->summaryExportRows($data));
        }

        return view('admin.reports.profit.summary', $this->viewData($request, $data) + [
            'title' => 'Profit summary',
            'slug' => 'summary',
        ]);
    }

    /**
     * The owners' page: the trading account carried all the way through to
     * what is left with the company, on an accrual footing — a bonus counts
     * the moment it is credited to a wallet, whether it has been paid or not.
     */
    public function companySnapshot(Request $request): View|StreamedResponse
    {
        [$from, $to, $basis, $warehouse] = $this->filters($request);

        $data = $this->profit->companySnapshot(SalesScope::all(), $from, $to, $basis, $warehouse);

        if ($this->wantsExport($request)) {
            return $this->export($request, 'profit-company-snapshot', [
                ['key' => 'line', 'label' => 'Line'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ], $this->snapshotExportRows($data));
        }

        return view('admin.reports.profit.company-snapshot', $this->viewData($request, $data) + [
            'title' => 'Company snapshot',
            'slug' => 'company-snapshot',
            'rates' => $this->planRates(),
        ]);
    }

    /**
     * The same page on a cash footing: only a bank-confirmed transfer counts
     * as money gone, and everything credited but unpaid is listed below as a
     * commitment instead. Same service call — the two pages differ in which of
     * its figures they subtract, never in what they read.
     */
    public function companyCashSnapshot(Request $request): View|StreamedResponse
    {
        [$from, $to, $basis, $warehouse] = $this->filters($request);

        $data = $this->profit->companySnapshot(SalesScope::all(), $from, $to, $basis, $warehouse);

        if ($this->wantsExport($request)) {
            return $this->export($request, 'profit-company-cash-snapshot', [
                ['key' => 'line', 'label' => 'Line'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ], $this->cashSnapshotExportRows($data));
        }

        return view('admin.reports.profit.company-cash-snapshot', $this->viewData($request, $data) + [
            'title' => 'Company cash snapshot',
            'slug' => 'company-cash-snapshot',
            'rates' => $this->planRates(),
        ]);
    }

    public function byProduct(Request $request): View|StreamedResponse
    {
        return $this->productLike($request, groupByCategory: false);
    }

    public function byCategory(Request $request): View|StreamedResponse
    {
        return $this->productLike($request, groupByCategory: true);
    }

    public function register(Request $request): View|StreamedResponse
    {
        [$from, $to, $basis] = $this->filters($request);

        // A cost sheet that silently stops short does not tie to the summary,
        // and a finance user reconciling a month would never know. The cap
        // stays (an unbounded register is a memory risk), but it is now stated
        // wherever it bites — on screen and in the export itself.
        $limit = $this->wantsExport($request) ? self::REGISTER_EXPORT_LIMIT : self::REGISTER_SCREEN_LIMIT;

        $rows = $this->profit->lineRegister(SalesScope::all(), $from, $to, $basis, $limit);
        $truncated = count($rows) === $limit;

        // The consumer's name is not a margin figure. admin-finance holds
        // `profit.report.view` but not `commerce.order.manage`, so without this
        // the register would become a new route to customer names for a role
        // that cannot open an order (DPDP purpose limitation). `order_no` stays
        // as the drill-down key for whoever can follow it.
        $showCustomer = (bool) $request->user()?->can('commerce.order.manage');

        $columns = array_values(array_filter([
            ['key' => 'sold_at', 'label' => 'Date'],
            ['key' => 'order_no', 'label' => 'Order no'],
            $showCustomer ? ['key' => 'customer', 'label' => 'Customer'] : null,
            ['key' => 'adn', 'label' => 'ADN'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'product', 'label' => 'Product'],
            ['key' => 'qty', 'label' => 'Qty', 'align' => 'right'],
            ['key' => 'net_sale', 'label' => 'Net sale', 'align' => 'right'],
            ['key' => 'cogs', 'label' => 'COGS', 'align' => 'right'],
            ['key' => 'gross_profit', 'label' => 'Gross profit', 'align' => 'right'],
            ['key' => 'margin', 'label' => 'Margin %', 'align' => 'right'],
            ['key' => 'cost_basis', 'label' => 'Cost basis'],
            ['key' => 'status', 'label' => 'Status'],
        ]));

        $mapped = array_map(fn (array $r): array => array_filter([
            'sold_at' => $r['sold_at'] !== null ? Carbon::parse((string) $r['sold_at'])->format('Y-m-d') : '',
            'order_no' => $r['order_no'],
            'customer' => $showCustomer ? $r['customer'] : null,
            'adn' => $r['adn'],
            'sku' => $r['sku'],
            'product' => $r['product'],
            'qty' => $r['qty'],
            'net_sale' => $this->money($r['net_sale_paise'], $request),
            'cogs' => $this->money($r['cogs_paise'], $request),
            'gross_profit' => $this->money($r['gross_profit_paise'], $request),
            'margin' => $this->percent($r['margin_pct']),
            'cost_basis' => $r['cost_basis'],
            'status' => $r['status'],
        ], fn (mixed $v): bool => $v !== null), $rows);

        if ($truncated && $this->wantsExport($request)) {
            $mapped[] = ['order_no' => 'Truncated at '.IndianNumber::format($limit).' lines — narrow the date range for the rest.'];
        }

        return $this->renderTable(
            $request,
            'Profit register',
            'register',
            $columns,
            $mapped,
            $truncated && ! $this->wantsExport($request)
                ? 'Showing the '.IndianNumber::format($limit).' most recent lines only. Narrow the date range to see the rest — the totals on the summary view still cover the whole period.'
                : null,
        );
    }

    private function productLike(Request $request, bool $groupByCategory): View|StreamedResponse
    {
        [$from, $to, $basis] = $this->filters($request);

        $rows = $this->profit->byProduct(SalesScope::all(), $from, $to, $basis, $groupByCategory);

        $columns = array_values(array_filter([
            $groupByCategory ? null : ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'product', 'label' => $groupByCategory ? 'Category' : 'Product'],
            $groupByCategory ? null : ['key' => 'category', 'label' => 'Category'],
            ['key' => 'sold_qty', 'label' => 'Sold qty', 'align' => 'right'],
            ['key' => 'net_sales', 'label' => 'Net sales', 'align' => 'right'],
            ['key' => 'avg_sale_rate', 'label' => 'Avg sale rate', 'align' => 'right'],
            ['key' => 'landed_rate', 'label' => 'Landed rate', 'align' => 'right'],
            ['key' => 'cogs', 'label' => 'COGS', 'align' => 'right'],
            ['key' => 'gross_profit', 'label' => 'Gross profit', 'align' => 'right'],
            ['key' => 'margin', 'label' => 'Margin %', 'align' => 'right'],
            ['key' => 'markup', 'label' => 'Markup %', 'align' => 'right'],
            ['key' => 'bv', 'label' => 'BV released', 'align' => 'right'],
            ['key' => 'cost_basis', 'label' => 'Cost basis'],
        ]));

        $mapped = array_map(fn (array $r): array => array_filter([
            'sku' => $groupByCategory ? null : $r['sku'],
            'product' => $r['product'],
            'category' => $groupByCategory ? null : $r['category'],
            'sold_qty' => $r['sold_qty'],
            'net_sales' => $this->money($r['net_sales_paise'], $request),
            'avg_sale_rate' => $this->money($r['avg_sale_rate_paise'], $request),
            'landed_rate' => $this->money($r['landed_rate_paise'], $request),
            'cogs' => $this->money($r['cogs_paise'], $request),
            'gross_profit' => $this->money($r['gross_profit_paise'], $request),
            'margin' => $this->percent($r['margin_pct']),
            'markup' => $this->percent($r['markup_pct']),
            'bv' => $r['bv_paise'] / 100,
            'cost_basis' => $r['cost_basis'],
        ], static fn ($v): bool => $v !== null), $rows);

        return $this->renderTable(
            $request,
            $groupByCategory ? 'Profit by category' : 'Profit by product',
            $groupByCategory ? 'by-category' : 'by-product',
            $columns,
            $mapped,
        );
    }

    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderTable(Request $request, string $title, string $slug, array $columns, array $rows, ?string $notice = null): View|StreamedResponse
    {
        if ($this->wantsExport($request)) {
            return $this->export($request, "profit-{$slug}", $columns, $rows);
        }

        return view('admin.reports.profit.table', $this->viewData($request, null) + [
            'title' => $title,
            'slug' => $slug,
            'columns' => $columns,
            'rows' => $rows,
            'notice' => $notice,
        ]);
    }

    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function export(Request $request, string $filename, array $columns, array $rows): StreamedResponse
    {
        // Finance report exports are audited in this codebase (the BV ledger
        // does the same). A cost sheet leaving the building should leave a
        // record of who took it.
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'profit.report.exported',
            'subject_type' => 'system',
            'subject_id' => null,
            'before_hash' => null,
            'after_hash' => AuditDigests::of(['report' => $filename, 'query' => $request->query()]),
            'details' => ['report' => $filename, 'filters' => $request->query()],
        ]);

        return ReportExport::respond($request, $filename.'-'.now()->toDateString(), $columns, $rows);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function summaryExportRows(array $data): array
    {
        $money = static fn (int $p): float => $p / 100;

        $rows = [
            ['line' => 'Opening stock (at cost)', 'amount' => $money($data['opening_stock_paise'])],
            ['line' => 'Add: Purchases (taxable, ex-GST)', 'amount' => $money($data['purchases_paise'])],
            ['line' => 'Add: Freight & other charges', 'amount' => $money($data['purchase_charges_paise'])],
            ['line' => 'Landed purchases', 'amount' => $money($data['landed_purchases_paise'])],
            ['line' => 'Less: Closing stock (at cost)', 'amount' => $money($data['closing_stock_paise'])],
            ['line' => 'Stock consumed (opening + purchases - closing)', 'amount' => $money($data['implied_consumption_paise'])],
            ['line' => 'Less: written off, damaged, transferred or rounded', 'amount' => $money($data['reconciling_difference_paise'])],
            ['line' => 'Cost of goods sold', 'amount' => $money($data['cogs_paise'])],
            ['line' => 'Gross sales (ex-GST)', 'amount' => $money($data['gross_sales_paise'])],
            ['line' => 'Less: Returns & refunds', 'amount' => $money($data['refunds_paise'])],
            ['line' => 'Net sales', 'amount' => $money($data['net_sales_paise'])],
            ['line' => 'Gross profit', 'amount' => $money($data['gross_profit_paise'])],
            ['line' => 'Margin %', 'amount' => $data['margin_pct']],
            ['line' => 'Markup %', 'amount' => $data['markup_pct']],
        ];

        foreach ($data['commission_by_type'] as $type => $paise) {
            $rows[] = ['line' => 'Less: Commission — '.$type, 'amount' => $money((int) $paise)];
        }

        $rows[] = ['line' => 'Contribution after commission', 'amount' => $money($data['contribution_paise'])];
        $rows[] = ['line' => 'Memo: Shipping collected', 'amount' => $money($data['shipping_collected_paise'])];
        $rows[] = ['line' => 'Memo: GST output', 'amount' => $money($data['gst_output_paise'])];
        $rows[] = ['line' => 'Memo: GST input credit', 'amount' => $money($data['gst_input_paise'])];
        $rows[] = ['line' => 'Memo: BV released', 'amount' => $money($data['bv_paise'])];

        return $rows;
    }

    /**
     * The accrual snapshot, one row per statement line, in the order the page
     * shows them — so a finance user reading the workbook is reading the same
     * document they exported.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function snapshotExportRows(array $data): array
    {
        $money = static fn (int $p): float => $p / 100;

        $rows = $this->goodsAndSalesExportRows($data);

        $rows[] = ['line' => 'Paid to distributors', 'amount' => null];
        $rows[] = ['line' => 'Gross swept into payout batches', 'amount' => $money($data['gross_swept_paise'])];
        $rows[] = ['line' => 'Less: Repurchase deduction (held for goods)', 'amount' => $money($data['repurchase_swept_paise'])];
        $rows[] = ['line' => 'Less: Admin charge retained by arovolife', 'amount' => $money($data['admin_charge_paise'])];
        $rows[] = ['line' => 'Less: TDS deducted, to be remitted', 'amount' => $money($data['tds_paise'])];
        $rows[] = ['line' => 'Net paid to distributors', 'amount' => $money($data['net_transferred_paise'])];
        $rows[] = ['line' => 'Built, not yet in the bank — pending / failed', 'amount' => $money($data['net_in_flight_paise'])];
        $rows[] = ['line' => 'Memo: Repurchase share withheld at credit time this period', 'amount' => $money($data['repurchase_withheld_paise'])];

        $rows[] = ['line' => 'What arovolife keeps', 'amount' => null];
        $rows[] = ['line' => 'Gross profit', 'amount' => $money($data['gross_profit_paise'])];
        $rows[] = ['line' => 'Less: Bonus credited under the plan', 'amount' => $money($data['commission_paise'])];

        foreach ($data['commission_by_type'] as $type => $paise) {
            $rows[] = ['line' => '  Memo: '.Str::headline(str_replace('_credit', '', (string) $type)), 'amount' => $money((int) $paise)];
        }

        $rows[] = ['line' => 'Add back: Reversed by admin', 'amount' => $money($data['reversed_paise'])];
        $rows[] = ['line' => 'Add back: Forfeited under income caps', 'amount' => $money($data['forfeited_paise'])];
        $rows[] = ['line' => 'Add: Admin charge retained', 'amount' => $money($data['admin_charge_paise'])];
        $rows[] = ['line' => 'Money left with arovolife', 'amount' => $money($data['money_left_paise'])];
        $rows[] = ['line' => 'Memo: Money left as % of net sales', 'amount' => $data['money_left_pct']];

        $rows = array_merge($rows, $this->owedToOthersExportRows($data), $this->snapshotMemoExportRows($data));

        return $rows;
    }

    /**
     * The cash snapshot. Same goods and sales rows, then the cash chain.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function cashSnapshotExportRows(array $data): array
    {
        $money = static fn (int $p): float => $p / 100;

        $rows = $this->goodsAndSalesExportRows($data);

        $rows[] = ['line' => 'Paid to distributors (bank-confirmed)', 'amount' => null];
        $rows[] = ['line' => 'Net paid to distributors', 'amount' => $money($data['net_transferred_paise'])];
        $rows[] = ['line' => 'Memo: Gross swept into those batches', 'amount' => $money($data['gross_swept_paise'])];
        $rows[] = ['line' => 'Memo: Repurchase deduction held for goods', 'amount' => $money($data['repurchase_swept_paise'])];
        $rows[] = ['line' => 'Memo: Admin charge retained by arovolife', 'amount' => $money($data['admin_charge_paise'])];
        $rows[] = ['line' => 'Memo: TDS deducted on those batches', 'amount' => $money($data['tds_paise'])];

        $rows[] = ['line' => 'What actually left arovolife', 'amount' => null];
        $rows[] = ['line' => 'Gross profit', 'amount' => $money($data['gross_profit_paise'])];
        $rows[] = ['line' => "Less: Net paid to distributors' banks", 'amount' => $money($data['net_transferred_paise'])];
        $rows[] = ['line' => 'Money actually left with arovolife', 'amount' => $money($data['cash_money_left_paise'])];
        $rows[] = ['line' => 'Memo: Money actually left as % of net sales', 'amount' => $data['cash_money_left_pct']];

        $rows[] = ['line' => 'Still committed to others (will leave)', 'amount' => null];
        $rows[] = ['line' => 'Less: TDS to remit', 'amount' => $money($data['tds_paise'])];
        $rows[] = ['line' => 'Less: Payouts in flight (pending / failed)', 'amount' => $money($data['net_in_flight_paise'])];
        $rows[] = ['line' => 'Less: Bonus credited but not yet paid out', 'amount' => $money($data['wallet_balances_paise'])];
        $rows[] = ['line' => 'Less: Repurchase-wallet balances outstanding', 'amount' => $money($data['repurchase_balances_paise'])];
        $rows[] = ['line' => 'Free after commitments', 'amount' => $money($data['cash_free_after_commitments_paise'])];

        $rows = array_merge($rows, $this->gstExportRows($data), $this->snapshotMemoExportRows($data));

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function goodsAndSalesExportRows(array $data): array
    {
        $money = static fn (int $p): float => $p / 100;

        return [
            ['line' => 'Goods', 'amount' => null],
            ['line' => 'Purchases (taxable, ex-GST)', 'amount' => $money($data['purchases_paise'])],
            ['line' => 'Add: Freight & other charges', 'amount' => $money($data['purchase_charges_paise'])],
            ['line' => 'Landed purchases', 'amount' => $money($data['landed_purchases_paise'])],
            ['line' => 'Memo: Opening stock (at cost)', 'amount' => $money($data['opening_stock_paise'])],
            ['line' => 'Memo: Closing stock (at cost)', 'amount' => $money($data['closing_stock_paise'])],
            ['line' => 'Cost of goods sold', 'amount' => $money($data['cogs_paise'])],
            ['line' => 'Memo: Stock that left other than by sale', 'amount' => $money($data['reconciling_difference_paise'])],

            ['line' => 'Sales', 'amount' => null],
            ['line' => 'Gross sales (ex-GST)', 'amount' => $money($data['gross_sales_paise'])],
            ['line' => 'Less: Returns & refunds', 'amount' => $money($data['refunds_paise'])],
            ['line' => 'Net sales', 'amount' => $money($data['net_sales_paise'])],
            ['line' => 'Less: Cost of goods sold', 'amount' => $money($data['cogs_paise'])],
            ['line' => 'Gross profit', 'amount' => $money($data['gross_profit_paise'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function owedToOthersExportRows(array $data): array
    {
        $money = static fn (int $p): float => $p / 100;

        return array_merge(
            [['line' => "Owed to others (not arovolife's money)", 'amount' => null]],
            $this->gstExportRows($data, heading: false),
            [
                ['line' => 'TDS deducted, to be remitted', 'amount' => $money($data['tds_paise'])],
                ['line' => 'Bonus credited but not yet paid out', 'amount' => $money($data['wallet_balances_paise'])],
                ['line' => 'Repurchase-wallet balances outstanding', 'amount' => $money($data['repurchase_balances_paise'])],
                ['line' => 'Payouts in flight (pending / failed)', 'amount' => $money($data['net_in_flight_paise'])],
                ['line' => 'Memo: Bonus held at the latest payout', 'amount' => $money($data['held_paise'])],
                ['line' => 'Distributors held at the latest payout', 'amount' => $data['held_distributors']],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function gstExportRows(array $data, bool $heading = true): array
    {
        $money = static fn (int $p): float => $p / 100;

        return array_merge(
            $heading ? [['line' => 'GST', 'amount' => null]] : [],
            [
                ['line' => 'GST collected on sales', 'amount' => $money($data['gst_output_paise'])],
                ['line' => 'Less: GST paid on purchases (input credit)', 'amount' => $money($data['gst_input_paise'])],
                ['line' => 'Net GST payable', 'amount' => $money($data['gst_net_payable_paise'])],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function snapshotMemoExportRows(array $data): array
    {
        $money = static fn (int $p): float => $p / 100;

        return [
            ['line' => 'Memo', 'amount' => null],
            ['line' => 'Memo: Shipping collected', 'amount' => $money($data['shipping_collected_paise'])],
            ['line' => 'Memo: Collection fees collected', 'amount' => $money($data['collection_fee_collected_paise'])],
            ['line' => 'Memo: Discounts given', 'amount' => $money($data['discount_paise'])],
            ['line' => 'Memo: Points redeemed', 'amount' => $money($data['points_paise'])],
            ['line' => 'Memo: Sales settled with repurchase credit', 'amount' => $money($data['repurchase_spent_on_orders_paise'])],
            ['line' => 'Memo: BV released', 'amount' => $money($data['bv_paise'])],
            ['line' => 'Memo: Refunded orders', 'amount' => $data['refunded_orders']],
        ];
    }

    /**
     * The plan rates the snapshot row notes quote, rendered from the settings
     * that actually drive them rather than typed into the copy. Every one of
     * these is editable from Compensation → Plan settings, so a hard-coded
     * "10%" in a note becomes a lie the day finance changes it.
     *
     * Resolved here and passed in, never resolved inside a Blade view.
     *
     * The admin charge has two ceilings — one for the weekly group and one for
     * the monthly groups — and quoting only the weekly one would understate
     * the cap the moment finance sets them apart, so the note is phrased from
     * both and collapses to a single figure only while they agree.
     *
     * @return array{repurchase_rate: string, repurchase_cap: string, admin_rate: string, admin_cap_note: string, tds_rate: string}
     */
    private function planRates(): array
    {
        $weeklyCap = $this->plan->adminChargeWeeklyCapPaise();
        $monthlyCap = $this->plan->adminChargeMonthlyCapPaise();

        return [
            'repurchase_rate' => IndianNumber::percentFromBp($this->plan->repurchaseRateBp()),
            'repurchase_cap' => IndianNumber::rupees($this->plan->repurchaseCapPaise(), 0),
            'admin_rate' => IndianNumber::percentFromBp($this->plan->adminChargeRateBp()),
            'admin_cap_note' => $weeklyCap === $monthlyCap
                ? 'capped '.IndianNumber::rupees($weeklyCap, 0).' per bonus group per cycle'
                : 'capped '.IndianNumber::rupees($weeklyCap, 0).' per weekly group and '
                    .IndianNumber::rupees($monthlyCap, 0).' per monthly group',
            'tds_rate' => IndianNumber::percentFromBp($this->plan->tdsRateBp()),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    private function viewData(Request $request, ?array $data): array
    {
        [, , $basis, $warehouse] = $this->filters($request);

        return [
            'data' => $data,
            'dateFrom' => (string) $request->query('date_from', ''),
            'dateTo' => (string) $request->query('date_to', ''),
            'basis' => $basis,
            'bases' => [
                SalesReportService::BASIS_SHIPPED => 'Shipped date',
                SalesReportService::BASIS_ORDERED => 'Order date',
            ],
            'warehouseCode' => $warehouse ?? '',
            'warehouses' => Warehouse::query()->orderBy('name')->get(['code', 'name']),
        ];
    }

    /** @return array{0: ?CarbonInterface, 1: ?CarbonInterface, 2: string, 3: ?string} */
    private function filters(Request $request): array
    {
        $from = (string) $request->query('date_from', '');
        $to = (string) $request->query('date_to', '');
        $basis = (string) $request->query('basis', SalesReportService::BASIS_SHIPPED);

        return [
            $from !== '' ? Carbon::parse($from)->startOfDay() : null,
            $to !== '' ? Carbon::parse($to)->endOfDay() : null,
            in_array($basis, SalesReportService::BASES, true) ? $basis : SalesReportService::BASIS_SHIPPED,
            ($w = (string) $request->query('warehouse_code', '')) !== '' ? $w : null,
        ];
    }

    private function wantsExport(Request $request): bool
    {
        return $request->query('export') !== null || $request->query('format') !== null;
    }

    /**
     * Exports stay ungrouped so a spreadsheet reads them as numbers; screens
     * use lakh grouping. Same rule the rest of the platform follows.
     */
    private function money(int $paise, Request $request): string|float
    {
        return $this->wantsExport($request) ? $paise / 100 : IndianNumber::rupees($paise);
    }

    private function percent(?float $pct): string
    {
        return $pct === null ? '—' : IndianNumber::format($pct, 1).'%';
    }
}
