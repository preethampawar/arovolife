<?php

declare(strict_types=1);

namespace App\Modules\Tax\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Shared\Support\IndianNumber;
use App\Modules\Shared\Support\ReportExport;
use App\Modules\Tax\Services\TaxReportService;
use App\Modules\Tax\Support\TaxPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The statutory reports — GST and TDS — five views over one query layer.
 *
 * Admin-only, behind the same `profit.report.view` door as the rest of the
 * finance reporting: everything here is company-wide tax data and no
 * distributor-facing route reaches this controller. The TDS register is the
 * deductee register behind Form 26Q, so it shows a distributor's payout
 * figures — to the finance and operations staff who already see them on the
 * payout batch pages, with PAN masked and never the full number (hard rule 8).
 */
final class AdminTaxReportController extends Controller
{
    /** A register is a line-per-document listing; both caps keep one request bounded. */
    private const REGISTER_SCREEN_LIMIT = 2000;

    private const REGISTER_EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly TaxReportService $reports,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function tdsSummary(Request $request): View|StreamedResponse
    {
        $period = TaxPeriod::fromRequest($request);
        $data = $this->reports->tdsSummary($period);

        if ($this->wantsExport($request)) {
            return $this->export($request, 'tds-summary', $this->lineColumns(), $this->tdsSummaryExportRows($data));
        }

        return view('admin.reports.tax.tds-summary', $this->viewData($period) + [
            'title' => 'TDS report',
            'slug' => 'tds',
            'data' => $data,
        ]);
    }

    public function tdsRegister(Request $request): View|StreamedResponse
    {
        $period = TaxPeriod::fromRequest($request);
        $limit = $this->limit($request);
        $rows = $this->reports->tdsRegister($period, $limit);

        $columns = [
            ['key' => 'batch_date', 'label' => 'Batch date'],
            ['key' => 'batch_type', 'label' => 'Batch'],
            ['key' => 'batch_id', 'label' => 'Batch no'],
            ['key' => 'adn', 'label' => 'ADN'],
            ['key' => 'name', 'label' => 'Deductee'],
            ['key' => 'pan_masked', 'label' => 'PAN (masked)'],
            ['key' => 'gross', 'label' => 'Gross', 'align' => 'right'],
            ['key' => 'repurchase', 'label' => 'Repurchase', 'align' => 'right'],
            ['key' => 'admin_charge', 'label' => 'Admin charge', 'align' => 'right'],
            ['key' => 'payable', 'label' => 'Payable', 'align' => 'right'],
            ['key' => 'tds', 'label' => 'TDS', 'align' => 'right'],
            ['key' => 'net', 'label' => 'Net paid', 'align' => 'right'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'utr_number', 'label' => 'UTR'],
            ['key' => 'dispatched_at', 'label' => 'Dispatched'],
        ];

        $mapped = array_map(fn (array $row): array => [
            'batch_date' => $this->date($row['batch_date']),
            'batch_type' => Str::headline((string) $row['batch_type']),
            'batch_id' => $row['batch_id'],
            'adn' => $row['adn'],
            'name' => $row['name'],
            'pan_masked' => $row['pan_masked'] ?? '—',
            'gross' => $this->money((int) $row['gross_paise'], $request),
            'repurchase' => $this->money((int) $row['repurchase_deduction_paise'], $request),
            'admin_charge' => $this->money((int) $row['admin_charge_paise'], $request),
            'payable' => $this->money((int) $row['payable_paise'], $request),
            'tds' => $this->money((int) $row['tds_paise'], $request),
            'net' => $this->money((int) $row['net_transferred_paise'], $request),
            'status' => Str::headline((string) $row['status']),
            'utr_number' => $row['utr_number'] ?? '—',
            'dispatched_at' => $this->date($row['dispatched_at']),
        ], $rows);

        if (! $this->wantsExport($request)) {
            $this->auditRegisterView($request, $period, 'tds-register', count($rows));
        }

        return $this->renderTable($request, $period, 'TDS register', 'tds-register', $columns, $mapped, count($rows) === $limit, $limit);
    }

    public function gstSummary(Request $request): View|StreamedResponse
    {
        $period = TaxPeriod::fromRequest($request);
        $data = $this->reports->gstSummary($period);

        if ($this->wantsExport($request)) {
            return $this->export($request, 'gst-summary', $this->lineColumns(), $this->gstSummaryExportRows($data));
        }

        return view('admin.reports.tax.gst-summary', $this->viewData($period) + [
            'title' => 'GST report',
            'slug' => 'gst',
            'data' => $data,
        ]);
    }

    public function gstOutward(Request $request): View|StreamedResponse
    {
        $period = TaxPeriod::fromRequest($request);
        $limit = $this->limit($request);
        $rows = $this->reports->outwardRegister($period, $limit);

        $columns = [
            ['key' => 'doc_type', 'label' => 'Document'],
            ['key' => 'invoice_no', 'label' => 'Invoice no'],
            ['key' => 'issued_at', 'label' => 'Date'],
            ['key' => 'order_no', 'label' => 'Order no'],
            ['key' => 'supply_type', 'label' => 'Supply'],
            ['key' => 'buyer_gstin', 'label' => 'Buyer GSTIN'],
            ['key' => 'buyer_legal_name', 'label' => 'Buyer (registered)'],
            ['key' => 'place_of_supply', 'label' => 'Place of supply'],
            ['key' => 'pos_code', 'label' => 'POS code'],
            ['key' => 'hsn_code', 'label' => 'HSN'],
            ['key' => 'qty', 'label' => 'Qty', 'align' => 'right'],
            ['key' => 'rate', 'label' => 'Rate', 'align' => 'right'],
            ['key' => 'taxable', 'label' => 'Taxable value', 'align' => 'right'],
            ['key' => 'cgst', 'label' => 'CGST', 'align' => 'right'],
            ['key' => 'sgst', 'label' => 'SGST', 'align' => 'right'],
            ['key' => 'igst', 'label' => 'IGST', 'align' => 'right'],
            ['key' => 'total', 'label' => 'Total', 'align' => 'right'],
            ['key' => 'note', 'label' => 'Note'],
        ];

        $mapped = array_map(fn (array $row): array => [
            'doc_type' => $row['doc_type'],
            'invoice_no' => $row['invoice_no'] ?? '—',
            'issued_at' => $this->date($row['issued_at']),
            'order_no' => $row['order_no'] ?? '—',
            'supply_type' => $row['supply_type'],
            'buyer_gstin' => $row['buyer_gstin'] ?? '—',
            'buyer_legal_name' => $row['buyer_legal_name'] ?? '—',
            'place_of_supply' => $row['place_of_supply'] ?? '—',
            'pos_code' => $row['pos_code'] ?? '—',
            'hsn_code' => $row['hsn_code'] ?? '—',
            'qty' => $this->qty($row['qty'] ?? null, $request),
            'rate' => $this->rate($row['rate_bp'], $request),
            'taxable' => $this->money((int) $row['taxable_value_paise'], $request),
            'cgst' => $this->money((int) $row['cgst_paise'], $request),
            'sgst' => $this->money((int) $row['sgst_paise'], $request),
            'igst' => $this->money((int) $row['igst_paise'], $request),
            'total' => $this->money((int) $row['total_paise'], $request),
            'note' => $this->outwardNote($row),
        ], $rows);

        return $this->renderTable($request, $period, 'GST outward register', 'gst-outward', $columns, $mapped, count($rows) === $limit, $limit);
    }

    public function gstInward(Request $request): View|StreamedResponse
    {
        $period = TaxPeriod::fromRequest($request);
        $limit = $this->limit($request);
        $rows = $this->reports->inwardRegister($period, $limit);

        $columns = [
            ['key' => 'grn_no', 'label' => 'GRN'],
            ['key' => 'supplier_invoice_no', 'label' => 'Supplier invoice'],
            ['key' => 'supplier_invoice_date', 'label' => 'Invoice date'],
            ['key' => 'supplier', 'label' => 'Supplier'],
            ['key' => 'supplier_gstin', 'label' => 'Supplier GSTIN'],
            ['key' => 'supplier_state', 'label' => 'Supplier state'],
            ['key' => 'head', 'label' => 'Head'],
            ['key' => 'rate', 'label' => 'Rate', 'align' => 'right'],
            ['key' => 'taxable', 'label' => 'Taxable value', 'align' => 'right'],
            ['key' => 'cgst', 'label' => 'CGST', 'align' => 'right'],
            ['key' => 'sgst', 'label' => 'SGST', 'align' => 'right'],
            ['key' => 'igst', 'label' => 'IGST', 'align' => 'right'],
            ['key' => 'unclassified', 'label' => 'Unclassified', 'align' => 'right'],
            ['key' => 'total', 'label' => 'Total', 'align' => 'right'],
            ['key' => 'posted_at', 'label' => 'Posted'],
        ];

        $mapped = array_map(fn (array $row): array => [
            'grn_no' => $row['grn_no'],
            'supplier_invoice_no' => $row['supplier_invoice_no'],
            'supplier_invoice_date' => $this->date($row['supplier_invoice_date']),
            'supplier' => $row['supplier'],
            'supplier_gstin' => $row['supplier_gstin'] ?? '—',
            'supplier_state' => $row['supplier_state'] ?? '—',
            'head' => $row['head'],
            'rate' => $this->rate($row['rate_bp'], $request),
            'taxable' => $this->money((int) $row['taxable_value_paise'], $request),
            'cgst' => $this->money((int) $row['cgst_paise'], $request),
            'sgst' => $this->money((int) $row['sgst_paise'], $request),
            'igst' => $this->money((int) $row['igst_paise'], $request),
            'unclassified' => $this->money((int) $row['unclassified_gst_paise'], $request),
            'total' => $this->money((int) $row['total_paise'], $request),
            'posted_at' => $this->date($row['posted_at']),
        ], $rows);

        return $this->renderTable($request, $period, 'GST inward register', 'gst-inward', $columns, $mapped, count($rows) === $limit, $limit);
    }

    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderTable(Request $request, TaxPeriod $period, string $title, string $slug, array $columns, array $rows, bool $truncated, int $limit): View|StreamedResponse
    {
        if ($truncated && $this->wantsExport($request)) {
            // The first column, so the notice lands in a cell a reader sees
            // rather than one the register happens not to have.
            $rows[] = [$columns[0]['key'] => 'Truncated at '.IndianNumber::format($limit).' rows — narrow the period for the rest.'];
        }

        if ($this->wantsExport($request)) {
            return $this->export($request, $slug, $columns, $rows);
        }

        return view('admin.reports.tax.table', $this->viewData($period) + [
            'title' => $title,
            'slug' => $slug,
            'columns' => $columns,
            'rows' => $rows,
            'notice' => $truncated
                ? 'Showing the first '.IndianNumber::format($limit).' rows only. Narrow the period to see the rest — the totals on the summary still cover the whole period.'
                : null,
        ]);
    }

    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function export(Request $request, string $slug, array $columns, array $rows): StreamedResponse
    {
        // A statutory register leaving the building carries deductee-level tax
        // data, so who took it and on what filters is recorded — the same rule
        // the profit reports follow.
        $filename = 'tax-'.$slug;

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'tax.report.exported',
            'subject_type' => 'system',
            'subject_id' => null,
            'before_hash' => null,
            'after_hash' => AuditDigests::of(['report' => $filename, 'query' => $request->query()]),
            'details' => ['report' => $filename, 'filters' => $request->query()],
        ]);

        return ReportExport::respond($request, $filename.'-'.now()->toDateString(), $columns, $rows);
    }

    /**
     * Reading the deductee register on screen is as much a disclosure as
     * downloading it — the same ADNs, names, masked PANs and payout figures,
     * up to the screen cap — so the read is recorded the way the export is.
     * The row carries the period and how many rows were shown and nothing
     * else: no ADN, no name, no PAN, because an audit trail of a disclosure
     * must not itself become a second copy of what was disclosed.
     */
    private function auditRegisterView(Request $request, TaxPeriod $period, string $slug, int $rows): void
    {
        $details = [
            'report' => 'tax-'.$slug,
            'period' => $period->selection,
            'period_kind' => $period->kind,
            'rows' => $rows,
        ];

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'tax.register.viewed',
            'subject_type' => 'system',
            'subject_id' => null,
            'before_hash' => null,
            'after_hash' => AuditDigests::of($details),
            'details' => $details,
        ]);
    }

    /**
     * The TDS statement, one row per line, in the order the page shows them.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function tdsSummaryExportRows(array $data): array
    {
        $money = static fn (int $paise): float => $paise / 100;

        $rows = [
            ['line' => 'TDS deducted', 'amount' => null],
        ];

        /** @var list<array<string, mixed>> $byBatchType */
        $byBatchType = $data['by_batch_type'];

        foreach ($byBatchType as $group) {
            $rows[] = ['line' => 'Deducted on '.Str::headline((string) $group['batch_type']).' batches', 'amount' => $money((int) $group['tds_paise'])];
            $rows[] = ['line' => '  Memo: payable base on those lines', 'amount' => $money((int) $group['payable_paise'])];
            $rows[] = ['line' => '  Memo: batches', 'amount' => $group['batches']];
            $rows[] = ['line' => '  Memo: lines', 'amount' => $group['lines']];
        }

        $rows[] = ['line' => 'Total TDS deducted', 'amount' => $money((int) $data['tds_paise'])];
        $rows[] = ['line' => 'Deductees', 'amount' => $data['deductees']];
        $rows[] = ['line' => 'Lines', 'amount' => $data['lines']];
        $rows[] = ['line' => 'Payable base', 'amount' => $money((int) $data['payable_paise'])];
        $rows[] = ['line' => 'Net paid to distributors', 'amount' => $money((int) $data['net_paise'])];

        $rows[] = ['line' => 'By line status', 'amount' => null];

        /** @var list<array<string, mixed>> $byStatus */
        $byStatus = $data['by_status'];

        foreach ($byStatus as $group) {
            $rows[] = ['line' => Str::headline((string) $group['status']).' — TDS', 'amount' => $money((int) $group['tds_paise'])];
            $rows[] = ['line' => Str::headline((string) $group['status']).' — lines', 'amount' => $group['lines']];
        }

        $rows[] = ['line' => 'Memo', 'amount' => null];
        $rows[] = ['line' => 'Below-minimum lines (computed, not deducted)', 'amount' => $data['below_minimum_lines']];
        $rows[] = ['line' => 'Below-minimum TDS (computed, not deducted)', 'amount' => $money((int) $data['below_minimum_tds_paise'])];
        $rows[] = ['line' => 'Held lines at the latest batch of each type', 'amount' => $data['held_lines']];
        $rows[] = ['line' => 'Lines where the line figure and the ledger debit disagree', 'amount' => $data['line_tds_mismatch']];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function gstSummaryExportRows(array $data): array
    {
        $money = static fn (int $paise): float => $paise / 100;

        /** @var array<string, int> $output */
        $output = $data['output_by_head'];
        /** @var array<string, int> $credit */
        $credit = $data['credit_notes_by_head'];
        /** @var array<string, int> $input */
        $input = $data['input_by_head'];
        /** @var array<string, int> $net */
        $net = $data['net_payable_by_head'];

        $rows = [
            ['line' => 'Outward supplies (tax invoices issued)', 'amount' => null],
            ['line' => 'Taxable value', 'amount' => $money($output['taxable_paise'])],
            ['line' => 'CGST', 'amount' => $money($output['cgst_paise'])],
            ['line' => 'SGST', 'amount' => $money($output['sgst_paise'])],
            ['line' => 'IGST', 'amount' => $money($output['igst_paise'])],
            ['line' => 'Cess', 'amount' => $money($output['cess_paise'])],
            ['line' => 'Invoices', 'amount' => $output['invoices']],
        ];

        $rows[] = ['line' => 'Outward by rate', 'amount' => null];

        /** @var list<array<string, int|null>> $outputByRate */
        $outputByRate = $data['output_by_rate'];

        foreach ($outputByRate as $rate) {
            $label = $this->rateLabel($rate['rate_bp']);
            $rows[] = ['line' => "At {$label} — taxable value", 'amount' => $money((int) $rate['taxable_paise'])];
            $rows[] = ['line' => "At {$label} — CGST", 'amount' => $money((int) $rate['cgst_paise'])];
            $rows[] = ['line' => "At {$label} — SGST", 'amount' => $money((int) $rate['sgst_paise'])];
            $rows[] = ['line' => "At {$label} — IGST", 'amount' => $money((int) $rate['igst_paise'])];
        }

        $rows[] = ['line' => 'Less: credit notes (GST-reversing refunds)', 'amount' => null];
        $rows[] = ['line' => 'Taxable value reversed', 'amount' => $money($credit['taxable_paise'])];
        $rows[] = ['line' => 'CGST reversed', 'amount' => $money($credit['cgst_paise'])];
        $rows[] = ['line' => 'SGST reversed', 'amount' => $money($credit['sgst_paise'])];
        $rows[] = ['line' => 'IGST reversed', 'amount' => $money($credit['igst_paise'])];
        $rows[] = ['line' => 'Reversed with no tax invoice on record (unclassified)', 'amount' => $money($credit['unclassified_gst_paise'])];
        $rows[] = ['line' => 'Credit-note rows', 'amount' => $credit['notes']];

        $rows[] = ['line' => 'Net outward tax', 'amount' => null];
        $rows[] = ['line' => 'Net CGST', 'amount' => $money($output['cgst_paise'] - $credit['cgst_paise'])];
        $rows[] = ['line' => 'Net SGST', 'amount' => $money($output['sgst_paise'] - $credit['sgst_paise'])];
        $rows[] = ['line' => 'Net IGST', 'amount' => $money($output['igst_paise'] - $credit['igst_paise'])];

        $rows[] = ['line' => 'Input tax credit (goods receipts posted)', 'amount' => null];
        $rows[] = ['line' => 'Taxable value', 'amount' => $money($input['taxable_paise'])];
        $rows[] = ['line' => 'CGST', 'amount' => $money($input['cgst_paise'])];
        $rows[] = ['line' => 'SGST', 'amount' => $money($input['sgst_paise'])];
        $rows[] = ['line' => 'IGST', 'amount' => $money($input['igst_paise'])];
        $rows[] = ['line' => 'Unclassified — supplier state missing or unreadable', 'amount' => $money($input['unclassified_gst_paise'])];
        $rows[] = ['line' => 'Unclassified taxable value', 'amount' => $money($input['unclassified_taxable_paise'])];
        $rows[] = ['line' => 'Goods receipts', 'amount' => $input['invoices']];

        $rows[] = ['line' => 'Net payable by head (no cross-utilisation)', 'amount' => null];
        $rows[] = ['line' => 'CGST payable', 'amount' => $money($net['cgst_paise'])];
        $rows[] = ['line' => 'SGST payable', 'amount' => $money($net['sgst_paise'])];
        $rows[] = ['line' => 'IGST payable', 'amount' => $money($net['igst_paise'])];

        $rows[] = ['line' => 'Memo', 'amount' => null];
        $rows[] = ['line' => 'Paid orders in this period with no tax invoice', 'amount' => $data['orders_without_invoice']];
        $rows[] = ['line' => 'GST on the order book for the same window (shipped-date clock)', 'amount' => $money((int) $data['order_book_gst_paise'])];
        $rows[] = ['line' => 'Refunds approved with no tax credit', 'amount' => $data['refunds_without_tax_credit']];
        $rows[] = ['line' => 'Documents issued without a seller GSTIN (receipts, not tax invoices)', 'amount' => $data['receipts_without_gstin']];
        $rows[] = ['line' => 'Tax on those receipts (not a GSTR-1 supply)', 'amount' => $money((int) $data['receipts_without_gstin_gst_paise'])];
        $rows[] = ['line' => 'Seller GSTIN', 'amount' => $data['seller_gstin'] ?? 'not set'];

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function outwardNote(array $row): string
    {
        $note = $row['note'] ?? null;
        $reason = $row['reason'] ?? null;

        if ($note !== null && $reason !== null) {
            return (string) $note.' · '.Str::headline((string) $reason);
        }

        if ($note !== null) {
            return (string) $note;
        }

        return $reason !== null ? Str::headline((string) $reason) : '';
    }

    /** @return list<array{key: string, label: string, align?: string}> */
    private function lineColumns(): array
    {
        return [
            ['key' => 'line', 'label' => 'Line'],
            ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
        ];
    }

    /**
     * The TDS rate is a Plan setting, so the explanatory panel quotes it from
     * the settings table and never as a literal — finance can change it, and a
     * page that hardcoded the old figure would be wrong the day they did.
     *
     * @return array<string, mixed>
     */
    private function viewData(TaxPeriod $period): array
    {
        return [
            'period' => $period,
            'quarterOptions' => TaxPeriod::quarterOptions(),
            'tds_rate' => IndianNumber::percentFromBp($this->plan->tdsRateBp()),
        ];
    }

    private function limit(Request $request): int
    {
        return $this->wantsExport($request) ? self::REGISTER_EXPORT_LIMIT : self::REGISTER_SCREEN_LIMIT;
    }

    private function wantsExport(Request $request): bool
    {
        return $request->query('export') !== null || $request->query('format') !== null;
    }

    /**
     * Exports stay ungrouped so a spreadsheet reads them as numbers; screens
     * use lakh grouping. Same rule the rest of the platform follows.
     *
     * A credit note is a negative document, so the sign goes before the rupee
     * symbol — "−₹9,000.00", never "₹-9,000.00" — the same way the GST summary
     * writes its negatives. Exports keep the bare negative number.
     */
    private function money(int $paise, Request $request): string|float
    {
        if ($this->wantsExport($request)) {
            return $paise / 100;
        }

        return ($paise < 0 ? '−' : '').IndianNumber::rupees(abs($paise));
    }

    /**
     * A credit note takes goods back, so its quantity is negative and reads
     * with the same typographic minus its money does — "−1", never "-1", so a
     * row is not half one convention and half the other. Exports keep the bare
     * negative number, which is what a spreadsheet can sum.
     */
    private function qty(mixed $qty, Request $request): string|int
    {
        if ($qty === null) {
            return '—';
        }

        $qty = (int) $qty;

        if ($this->wantsExport($request)) {
            return $qty;
        }

        return ($qty < 0 ? '−' : '').abs($qty);
    }

    /** A rate reads as "18%" on screen and as a bare 18 in a spreadsheet. */
    private function rate(mixed $rateBp, Request $request): string|float
    {
        if ($rateBp === null) {
            return $this->wantsExport($request) ? '' : '—';
        }

        return $this->wantsExport($request) ? (int) $rateBp / 100 : IndianNumber::percentFromBp((int) $rateBp);
    }

    private function rateLabel(mixed $rateBp): string
    {
        return $rateBp === null ? 'no rate' : IndianNumber::percentFromBp((int) $rateBp);
    }

    private function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return Carbon::parse((string) $value)->format('Y-m-d');
    }
}
