<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Support\SalesScope;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
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
            'collection_fee_collected_paise' => $sales['collection_fee_paise'] - $refunds['collection_fee_paise'],
            'gst_output_paise' => $sales['gst_paise'] - $refunds['gst_paise'],
            'gst_input_paise' => $purchases['gst_paise'],
            'bv_paise' => $this->bvForPeriod($scope, $from, $to, $basis),

            'estimated_lines' => $cogs['estimated_lines'],
            'total_lines' => $cogs['total_lines'],
        ];
    }

    /**
     * The trading account extended past contribution into what the company
     * actually keeps: bonus taken back (reversals, cap forfeits, the admin
     * charge), what it merely holds for other people (TDS, repurchase wallets,
     * unpaid wallet balances), and what has reached distributors' banks.
     *
     * Sales, purchases and stock honour $basis and $warehouseCode exactly as
     * {@see tradingAccount()} does. Every ledger and payout figure below is
     * company-wide, keyed on `created_at` (the same clock tradingAccount()
     * already uses for commission) and ignores both the basis and the
     * warehouse — there is no warehouse of a wallet.
     *
     * Admin charge and TDS are read from the wallet-ledger DEBITS, never
     * summed off `payout_line_items`: a `below_minimum` line carries a computed
     * admin charge and TDS that were never debited, and a held line is
     * re-created by every batch with the same unswept gross. The net figures
     * come from the three line statuses whose debits do exist.
     *
     * @return array<string, mixed> every tradingAccount() key, plus:
     *                              reversed_paise, forfeited_paise, net_commission_paise,
     *                              repurchase_withheld_paise, admin_charge_paise, tds_paise,
     *                              money_left_paise, money_left_pct,
     *                              gross_swept_paise, repurchase_swept_paise,
     *                              net_transferred_paise, net_in_flight_paise,
     *                              transferred_lines, payout_lines,
     *                              held_paise, held_distributors,
     *                              wallet_balances_paise, repurchase_balances_paise,
     *                              negative_balances_notice,
     *                              gst_net_payable_paise, repurchase_spent_on_orders_paise,
     *                              cash_money_left_paise, cash_money_left_pct,
     *                              cash_free_after_commitments_paise
     *
     * @throws \InvalidArgumentException when the scope is not the whole book
     */
    public function companySnapshot(
        SalesScope $scope,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        string $basis,
        ?string $warehouseCode = null,
    ): array {
        $trading = $this->tradingAccount($scope, $from, $to, $basis, $warehouseCode);

        $ledger = $this->ledgerTotals($from, $to);
        $payouts = $this->payoutTotals($from, $to);
        $held = $this->heldTotals($to);
        $balances = $this->balancesAsOf($to);

        $grossProfit = (int) $trading['gross_profit_paise'];
        $netSales = (int) $trading['net_sales_paise'];

        // What the company is left holding: contribution, plus every rupee of
        // bonus it took back again. A reversal and a cap forfeit both undo a
        // credit that contribution already deducted, and the admin charge is
        // deducted from the distributor but never leaves the company.
        $moneyLeft = (int) $trading['contribution_paise']
            + $ledger['reversed_paise']
            + $ledger['forfeited_paise']
            + $ledger['admin_charge_paise'];

        // The same question on a cash footing: only bank-confirmed transfers
        // are treated as gone, and everything credited but unpaid is listed
        // below as a commitment instead.
        $cashMoneyLeft = $grossProfit - $payouts['net_transferred_paise'];

        // A company-wide wallet balance below zero cannot happen in normal
        // operation; on a test environment it is the trace of a recompute run
        // under live traffic, where a spend survives the wipe while the credit
        // behind it is re-derived smaller. Subtracting such a figure would
        // ADD it to free cash — a liability inflating the money available.
        // The raw balance is still reported on its own row; only the
        // commitment arithmetic floors it.
        $negativeBalances = $balances['wallet_balances_paise'] < 0
            || $balances['repurchase_balances_paise'] < 0;

        $cashFree = $cashMoneyLeft
            - $ledger['tds_paise']
            - $payouts['net_in_flight_paise']
            - max(0, $balances['wallet_balances_paise'])
            - max(0, $balances['repurchase_balances_paise']);

        return $trading + [
            'reversed_paise' => $ledger['reversed_paise'],
            'forfeited_paise' => $ledger['forfeited_paise'],
            'net_commission_paise' => (int) $trading['commission_paise'] - $ledger['reversed_paise'] - $ledger['forfeited_paise'],

            'repurchase_withheld_paise' => $ledger['repurchase_withheld_paise'],
            'admin_charge_paise' => $ledger['admin_charge_paise'],
            'tds_paise' => $ledger['tds_paise'],

            'money_left_paise' => $moneyLeft,
            'money_left_pct' => $this->pct($moneyLeft, $netSales),

            'gross_swept_paise' => $payouts['gross_swept_paise'],
            'repurchase_swept_paise' => $payouts['repurchase_swept_paise'],
            'net_transferred_paise' => $payouts['net_transferred_paise'],
            'net_in_flight_paise' => $payouts['net_in_flight_paise'],
            'transferred_lines' => $payouts['transferred_lines'],
            'payout_lines' => $payouts['lines'],

            'held_paise' => $held['held_paise'],
            'held_distributors' => $held['held_distributors'],

            'wallet_balances_paise' => $balances['wallet_balances_paise'],
            'repurchase_balances_paise' => $balances['repurchase_balances_paise'],
            'negative_balances_notice' => $negativeBalances,

            'gst_net_payable_paise' => (int) $trading['gst_output_paise'] - (int) $trading['gst_input_paise'],
            'repurchase_spent_on_orders_paise' => $this->sales->repurchaseWalletAppliedPaise($scope, $from, $to, $basis),

            'cash_money_left_paise' => $cashMoneyLeft,
            'cash_money_left_pct' => $this->pct($cashMoneyLeft, $netSales),
            'cash_free_after_commitments_paise' => $cashFree,
        ];
    }

    /**
     * Company-wide wallet-ledger movements in the window, by entry type.
     *
     * Every figure is an absolute amount: these are all debits except the
     * reversal's mirror rows, and a statement reads better with the direction
     * in the label than with a minus sign in the number.
     *
     * ## A reversal is two rows, not one
     *
     * {@see WalletService::reverseBonusCredit()} debits the `reversal` row for
     * the NET amount only — its one caller passes gross minus the repurchase
     * share — and takes the repurchase share back with a NEGATIVE
     * `repurchase_deduction` row instead. Counting only the `reversal` rows
     * would therefore under-report every reversal by its repurchase share and
     * leave that share sitting in "money left" as a cost the company never
     * bore. Negative `repurchase_deduction` rows are written by that method
     * alone: {@see WalletService::restoreRepurchaseCreditForOrder()} always
     * credits a positive amount.
     *
     * The forfeit path needs no such mirror: `writeIncomeCapForfeits()` debits
     * gross minus the repurchase share and the distributor keeps that share by
     * design, so the forfeit row is already the whole of what was taken back.
     *
     * @return array{reversed_paise: int, forfeited_paise: int, repurchase_withheld_paise: int, admin_charge_paise: int, tds_paise: int}
     */
    private function ledgerTotals(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $row = DB::table('wallet_ledger_entries')
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN ABS(amount_paise) '.
                'WHEN type = ? AND amount_paise < 0 THEN ABS(amount_paise) ELSE 0 END), 0) as reversed_paise, '.
                'COALESCE(SUM(CASE WHEN type IN (?, ?) THEN ABS(amount_paise) ELSE 0 END), 0) as forfeited_paise, '.
                'COALESCE(SUM(CASE WHEN type = ? THEN ABS(amount_paise) ELSE 0 END), 0) as repurchase_withheld_paise, '.
                'COALESCE(SUM(CASE WHEN type = ? THEN ABS(amount_paise) ELSE 0 END), 0) as admin_charge_paise, '.
                'COALESCE(SUM(CASE WHEN type = ? THEN ABS(amount_paise) ELSE 0 END), 0) as tds_paise',
                [
                    'reversal', 'repurchase_deduction',
                    'rank_cap_forfeit', 'income_cap_forfeit',
                    'repurchase_transfer',
                    'admin_charge_debit',
                    'tds_debit',
                ],
            )
            ->first();

        return [
            'reversed_paise' => (int) ($row->reversed_paise ?? 0),
            'forfeited_paise' => (int) ($row->forfeited_paise ?? 0),
            'repurchase_withheld_paise' => (int) ($row->repurchase_withheld_paise ?? 0),
            'admin_charge_paise' => (int) ($row->admin_charge_paise ?? 0),
            'tds_paise' => (int) ($row->tds_paise ?? 0),
        ];
    }

    /**
     * Payout lines built in the window, restricted to the three statuses whose
     * wallet debits actually exist. A held line never moved a rupee and a
     * below-minimum line carries deductions that were computed and discarded —
     * counting either would invent money.
     *
     * ## The window is the build date; the status is as of now
     *
     * There is no `transferred_at` column, so a line can only be placed in time
     * by `created_at` — the moment the batch was built. The transferred /
     * pending / failed split is therefore the bank's answer as it stands when
     * the report runs, not as it stood on the To date: a line built inside the
     * window and confirmed by the bank a week later counts as transferred here.
     * Every figure this returns is read the same way, and the copy on both
     * snapshot pages says so rather than promising a settlement date.
     *
     * @return array{gross_swept_paise: int, repurchase_swept_paise: int, net_transferred_paise: int, net_in_flight_paise: int, transferred_lines: int, lines: int}
     */
    private function payoutTotals(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $row = DB::table('payout_line_items')
            ->whereIn('status', [
                PayoutLineItem::STATUS_PENDING,
                PayoutLineItem::STATUS_TRANSFERRED,
                PayoutLineItem::STATUS_FAILED,
            ])
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw(
                'COALESCE(SUM(gross_paise), 0) as gross_swept_paise, '.
                'COALESCE(SUM(repurchase_deduction_paise), 0) as repurchase_swept_paise, '.
                'COALESCE(SUM(CASE WHEN status = ? THEN net_transferred_paise ELSE 0 END), 0) as net_transferred_paise, '.
                'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN net_transferred_paise ELSE 0 END), 0) as net_in_flight_paise, '.
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as transferred_lines, '.
                'COUNT(*) as line_count',
                [
                    PayoutLineItem::STATUS_TRANSFERRED,
                    PayoutLineItem::STATUS_PENDING, PayoutLineItem::STATUS_FAILED,
                    PayoutLineItem::STATUS_TRANSFERRED,
                ],
            )
            ->first();

        return [
            'gross_swept_paise' => (int) ($row->gross_swept_paise ?? 0),
            'repurchase_swept_paise' => (int) ($row->repurchase_swept_paise ?? 0),
            'net_transferred_paise' => (int) ($row->net_transferred_paise ?? 0),
            'net_in_flight_paise' => (int) ($row->net_in_flight_paise ?? 0),
            'transferred_lines' => (int) ($row->transferred_lines ?? 0),
            'lines' => (int) ($row->line_count ?? 0),
        ];
    }

    /**
     * Income the latest weekly and monthly batches could not pay — KYC
     * pending, no bank account, web-only, or bank details that would not
     * decrypt. Read from the LATEST batch of each type only: a held line is
     * written afresh by every batch over the same unswept credits, so summing
     * them across batches would report the same rupee several times.
     *
     * @return array{held_paise: int, held_distributors: int}
     */
    private function heldTotals(?CarbonInterface $to): array
    {
        $batchIds = [];

        foreach ([PayoutBatch::TYPE_WEEKLY, PayoutBatch::TYPE_MONTHLY] as $type) {
            $id = DB::table('payout_batches')
                ->where('batch_type', $type)
                ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('id');

            if ($id !== null) {
                $batchIds[] = (int) $id;
            }
        }

        if ($batchIds === []) {
            return ['held_paise' => 0, 'held_distributors' => 0];
        }

        $row = DB::table('payout_line_items')
            ->whereIn('payout_batch_id', $batchIds)
            ->whereIn('status', PayoutLineItem::HELD_STATUSES)
            ->selectRaw('COALESCE(SUM(wallet_balance_paise), 0) as held_paise, COUNT(DISTINCT distributor_id) as held_distributors')
            ->first();

        return [
            'held_paise' => (int) ($row->held_paise ?? 0),
            'held_distributors' => (int) ($row->held_distributors ?? 0),
        ];
    }

    /**
     * What the company still owes distributors as at the end of the period —
     * a balance, not a movement, so there is no lower bound on the date.
     *
     * The repurchase side floors each distributor at zero before summing, the
     * same way {@see WalletService::repurchaseWalletBalancesAsOfPaise()} does:
     * one distributor's negative position is a data fault, not a credit
     * against everybody else's balance.
     *
     * @return array{wallet_balances_paise: int, repurchase_balances_paise: int}
     */
    private function balancesAsOf(?CarbonInterface $to): array
    {
        $wallet = (int) DB::table('wallet_ledger_entries')
            ->whereNotIn('type', WalletService::REPURCHASE_TYPES)
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->sum('amount_paise');

        $perDistributor = DB::table('wallet_ledger_entries')
            ->whereIn('type', WalletService::REPURCHASE_TYPES)
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->groupBy('distributor_id')
            ->selectRaw(
                'distributor_id, '.
                'COALESCE(SUM(CASE WHEN type = ? THEN amount_paise ELSE 0 END), 0) as credits, '.
                'COALESCE(SUM(CASE WHEN type = ? THEN ABS(amount_paise) ELSE 0 END), 0) as debits',
                ['repurchase_deduction', 'repurchase_wallet_used'],
            );

        $repurchase = (int) DB::query()
            ->fromSub($perDistributor, 'per_distributor')
            ->selectRaw('COALESCE(SUM(CASE WHEN credits > debits THEN credits - debits ELSE 0 END), 0) as total')
            ->value('total');

        return [
            'wallet_balances_paise' => $wallet,
            'repurchase_balances_paise' => $repurchase,
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
