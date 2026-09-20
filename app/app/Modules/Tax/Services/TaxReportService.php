<?php

declare(strict_types=1);

namespace App\Modules\Tax\Services;

use App\Modules\Commerce\Services\SalesReportService;
use App\Modules\Commerce\Support\SalesScope;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Payments\Support\InvoiceGapWorklist;
use App\Modules\Shared\Support\IndianStates;
use App\Modules\Tax\Support\TaxPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The two statutory reports — GST and TDS — over one query layer.
 *
 * Everything here is read-only and historical: it reports documents that were
 * already issued and deductions that were already made. Nothing on these
 * screens is a projection, and no figure is ever guessed to make a total look
 * complete. Where the data cannot answer a question — a supplier with no state
 * on file, a refund whose GST reversal does not match its invoice — the figure
 * is reported as unclassified or flagged, never allocated to a head.
 *
 * ## Three clocks, deliberately different
 *
 * - Outward GST is dated by the **tax invoice date**, because that is the
 *   document GSTR-1 reports and the invoice cannot be re-dated afterwards.
 * - Inward GST is dated by the **supplier's invoice date**, not the date the
 *   goods receipt was posted, because that is the date on the document the
 *   input credit is claimed against.
 * - TDS is dated by the **payout batch date**, because that is the deduction
 *   date a Form 26Q return is built around.
 *
 * None of the three ties to the Profit summary, which counts a sale when the
 * goods shipped. That is stated on each page rather than reconciled away.
 */
final class TaxReportService
{
    /** The ledger account the GST collected on a sale sits in until it is paid over. */
    private const GST_OUTPUT_ACCOUNT = 'liability.gst_output';

    /** The revenue account a refund debits; its amount is the taxable value reversed. */
    private const SALES_ACCOUNT = 'revenue.sales';

    private const REFUND_SOURCE_TYPE = 'order.refund_approved';

    public function __construct(
        private readonly TaxSettings $settings,
        private readonly SalesReportService $sales,
        private readonly InvoiceGapWorklist $invoiceGaps,
    ) {}

    /**
     * The GSTR-3B-shaped summary: output tax, credit notes, input credit and
     * what is left payable, each broken down by head and by rate.
     *
     * @return array<string, mixed>
     */
    public function gstSummary(TaxPeriod $period): array
    {
        $output = $this->outwardTotals($period);
        $creditNotes = $this->creditNoteRows($period);
        $input = $this->inwardTotals($period);

        $creditByHead = $this->sumCreditNotes($creditNotes);
        $creditByRate = $this->creditNotesByRate($creditNotes);

        // No cross-utilisation: CGST cannot be set off against SGST, and the
        // order in which IGST credit is used is a return-preparation decision
        // this report does not make for the filer.
        $netPayable = [
            'cgst_paise' => $output['by_head']['cgst_paise'] - $creditByHead['cgst_paise'] - $input['by_head']['cgst_paise'],
            'sgst_paise' => $output['by_head']['sgst_paise'] - $creditByHead['sgst_paise'] - $input['by_head']['sgst_paise'],
            'igst_paise' => $output['by_head']['igst_paise'] - $creditByHead['igst_paise'] - $input['by_head']['igst_paise'],
        ];

        return [
            'output_by_head' => $output['by_head'],
            'output_by_rate' => $output['by_rate'],
            'credit_notes_by_head' => $creditByHead,
            'credit_notes_by_rate' => $creditByRate,
            'input_by_head' => $input['by_head'],
            'input_by_rate' => $input['by_rate'],
            'net_payable_by_head' => $netPayable,

            'orders_without_invoice' => $this->ordersWithoutInvoice($period),
            'order_book_gst_paise' => $this->orderBookGstPaise($period),
            'refunds_without_tax_credit' => $this->refundsWithoutTaxCredit($period),

            // Keyed on the documents in the period, not on the setting as it
            // stands today: `invoices.seller_gstin` is frozen per document, so
            // setting the GSTIN this morning does not turn last month's
            // receipts into tax invoices.
            'receipts_without_gstin' => $output['receipts_without_gstin'],
            'receipts_without_gstin_gst_paise' => $output['receipts_without_gstin_gst_paise'],
            'seller_gstin' => $this->settings->sellerGstin(),
        ];
    }

    /**
     * The GSTR-1 register: one row per invoice line, then the credit notes.
     *
     * Rate-wise rather than invoice-wise because that is the shape the return
     * is filed in — an invoice carrying two rates is two rows there and two
     * rows here.
     *
     * @return list<array<string, mixed>>
     */
    public function outwardRegister(TaxPeriod $period, int $limit): array
    {
        // The credit notes are fetched first and the invoice lines capped at
        // what is left: capping the lines at the whole limit and slicing the
        // merged list afterwards drops every credit note the moment the lines
        // fill the cap, and a return filed off that is missing its §34 side.
        $creditNotes = $this->creditNoteRows($period);
        $invoiceLimit = max(0, $limit - count($creditNotes));

        $rows = DB::table('invoice_lines as il')
            ->join('invoices as inv', 'inv.id', '=', 'il.invoice_id')
            ->join('orders as o', 'o.id', '=', 'inv.order_id')
            ->where('inv.issued_at', '>=', $period->from)
            ->where('inv.issued_at', '<', $period->toExclusive)
            ->orderBy('inv.issued_at')
            ->orderBy('inv.invoice_no')
            ->orderBy('il.id')
            ->limit($invoiceLimit)
            ->get([
                'inv.invoice_no', 'inv.issued_at', 'inv.buyer_gstin', 'inv.place_of_supply',
                'o.order_no', 'o.buyer_legal_name',
                'il.hsn_code', 'il.qty', 'il.gst_rate_bp',
                'il.taxable_value_paise', 'il.cgst_paise', 'il.sgst_paise', 'il.igst_paise',
            ]);

        $out = [];

        foreach ($rows as $row) {
            $gstin = $row->buyer_gstin !== null && $row->buyer_gstin !== '' ? (string) $row->buyer_gstin : null;
            $taxable = (int) $row->taxable_value_paise;
            $cgst = (int) $row->cgst_paise;
            $sgst = (int) $row->sgst_paise;
            $igst = (int) $row->igst_paise;

            $out[] = [
                'doc_type' => 'Invoice',
                'invoice_no' => (string) $row->invoice_no,
                'issued_at' => (string) $row->issued_at,
                'order_no' => (string) $row->order_no,
                'supply_type' => $gstin !== null ? 'B2B' : 'B2C',
                'buyer_gstin' => $gstin,
                // A consumer's name is not a GSTR field, and this register is
                // open to a role that cannot open an order. Only the registered
                // buyer's legal name — which the return does carry — is shown.
                'buyer_legal_name' => $gstin !== null ? ($row->buyer_legal_name !== null ? (string) $row->buyer_legal_name : null) : null,
                'place_of_supply' => (string) $row->place_of_supply,
                'pos_code' => $this->gstStateCode((string) $row->place_of_supply),
                'hsn_code' => (string) $row->hsn_code,
                'qty' => (int) $row->qty,
                'rate_bp' => (int) $row->gst_rate_bp,
                'taxable_value_paise' => $taxable,
                'cgst_paise' => $cgst,
                'sgst_paise' => $sgst,
                'igst_paise' => $igst,
                'total_paise' => $taxable + $cgst + $sgst + $igst,
                'note' => null,
            ];
        }

        return array_slice(array_merge($out, $creditNotes), 0, $limit);
    }

    /**
     * Refunds that actually reversed GST, as credit-note rows (CGST §34).
     *
     * The reversal is read from the ledger, not from the order: a refund reason
     * that does not refund tax writes no `liability.gst_output` debit at all,
     * and taking the order's GST would credit tax that was never given back.
     *
     * @return list<array<string, mixed>>
     */
    public function creditNoteRows(TaxPeriod $period): array
    {
        $reversals = DB::table('ledger_tx as tx')
            ->join('ledger_entries as le', 'le.ledger_tx_id', '=', 'tx.id')
            ->join('ledger_accounts as la', 'la.id', '=', 'le.account_id')
            ->leftJoin('orders as o', 'o.id', '=', 'tx.source_id')
            ->leftJoin('invoices as inv', 'inv.order_id', '=', 'o.id')
            ->where('tx.source_type', self::REFUND_SOURCE_TYPE)
            ->where('la.code', self::GST_OUTPUT_ACCOUNT)
            ->where('le.side', 'debit')
            ->where('tx.occurred_at', '>=', $period->from)
            ->where('tx.occurred_at', '<', $period->toExclusive)
            ->orderBy('tx.occurred_at')
            ->orderBy('tx.id')
            ->get([
                'tx.id as tx_id', 'tx.occurred_at', 'tx.memo', 'tx.source_id as order_id',
                'le.amount_paise',
                'o.order_no', 'o.buyer_gstin', 'o.buyer_legal_name',
                'inv.id as invoice_id', 'inv.invoice_no', 'inv.place_of_supply',
                'inv.cgst_paise', 'inv.sgst_paise', 'inv.igst_paise',
            ]);

        if ($reversals->isEmpty()) {
            return [];
        }

        $txIds = array_values($reversals->pluck('tx_id')->map(fn ($id): int => (int) $id)->all());
        $orderIds = array_values($reversals->pluck('order_id')->filter()->map(fn ($id): int => (int) $id)->all());
        $invoiceIds = array_values($reversals->pluck('invoice_id')->filter()->map(fn ($id): int => (int) $id)->all());

        $taxableReversed = $this->reversedTaxableByTx($txIds);
        $reasons = $this->refundReasonsByOrder($orderIds);
        $invoiceLines = $this->invoiceLinesByInvoice($invoiceIds);

        $rows = [];

        foreach ($reversals as $reversal) {
            $reversed = abs((int) $reversal->amount_paise);
            $invoiceId = $reversal->invoice_id !== null ? (int) $reversal->invoice_id : null;
            $invoiceGst = (int) $reversal->cgst_paise + (int) $reversal->sgst_paise + (int) $reversal->igst_paise;
            $orderId = $reversal->order_id !== null ? (int) $reversal->order_id : null;

            $base = [
                'doc_type' => 'Credit note',
                'invoice_no' => $invoiceId !== null ? (string) $reversal->invoice_no : '—',
                'issued_at' => (string) $reversal->occurred_at,
                'order_no' => $reversal->order_no !== null ? (string) $reversal->order_no : null,
                'supply_type' => $reversal->buyer_gstin !== null && $reversal->buyer_gstin !== '' ? 'B2B' : 'B2C',
                'buyer_gstin' => $reversal->buyer_gstin !== null && $reversal->buyer_gstin !== '' ? (string) $reversal->buyer_gstin : null,
                'buyer_legal_name' => $reversal->buyer_gstin !== null && $reversal->buyer_gstin !== ''
                    ? ($reversal->buyer_legal_name !== null ? (string) $reversal->buyer_legal_name : null)
                    : null,
                'place_of_supply' => $invoiceId !== null ? (string) $reversal->place_of_supply : null,
                'pos_code' => $invoiceId !== null ? $this->gstStateCode((string) $reversal->place_of_supply) : null,
                'reason' => $orderId !== null && isset($reasons[$orderId])
                    ? $reasons[$orderId]
                    : ($reversal->memo !== null ? (string) $reversal->memo : null),
            ];

            // No invoice on record: the tax was reversed but there is no
            // document to credit it against, and no head to put it under.
            // Reported as it is rather than allocated to a guess.
            if ($invoiceId === null || $invoiceGst === 0) {
                $rows[] = $base + [
                    'hsn_code' => null,
                    'qty' => null,
                    'rate_bp' => null,
                    'taxable_value_paise' => -($taxableReversed[(int) $reversal->tx_id] ?? 0),
                    'cgst_paise' => 0,
                    'sgst_paise' => 0,
                    'igst_paise' => 0,
                    'unclassified_gst_paise' => $reversed,
                    'total_paise' => -$reversed,
                    'note' => 'No tax invoice on record',
                ];

                continue;
            }

            // The whole invoice reversed: credit each of its lines, so the
            // register stays rate-wise on both sides of the return.
            if ($reversed === $invoiceGst) {
                foreach ($invoiceLines[$invoiceId] ?? [] as $line) {
                    $rows[] = $base + [
                        'hsn_code' => $line['hsn_code'],
                        'qty' => -$line['qty'],
                        'rate_bp' => $line['gst_rate_bp'],
                        'taxable_value_paise' => -$line['taxable_value_paise'],
                        'cgst_paise' => -$line['cgst_paise'],
                        'sgst_paise' => -$line['sgst_paise'],
                        'igst_paise' => -$line['igst_paise'],
                        'unclassified_gst_paise' => 0,
                        'total_paise' => -($line['taxable_value_paise'] + $line['cgst_paise'] + $line['sgst_paise'] + $line['igst_paise']),
                        'note' => null,
                    ];
                }

                continue;
            }

            // A partial reversal. The heads are known (they are the invoice's),
            // but which LINE the refund came off is not recorded anywhere, so
            // one row per order and a flag — never an invented per-line split.
            $split = $this->prorateAcrossHeads($reversed, [
                'cgst_paise' => (int) $reversal->cgst_paise,
                'sgst_paise' => (int) $reversal->sgst_paise,
                'igst_paise' => (int) $reversal->igst_paise,
            ]);

            $rows[] = $base + [
                'hsn_code' => null,
                'qty' => null,
                'rate_bp' => null,
                'taxable_value_paise' => -($taxableReversed[(int) $reversal->tx_id] ?? 0),
                'cgst_paise' => -$split['cgst_paise'],
                'sgst_paise' => -$split['sgst_paise'],
                'igst_paise' => -$split['igst_paise'],
                'unclassified_gst_paise' => 0,
                'total_paise' => -($reversed + ($taxableReversed[(int) $reversal->tx_id] ?? 0)),
                'note' => 'Partial — check',
            ];
        }

        return $rows;
    }

    /**
     * The inward register: one row per goods receipt and rate.
     *
     * @return list<array<string, mixed>>
     */
    public function inwardRegister(TaxPeriod $period, int $limit): array
    {
        $rows = DB::table('purchase_invoice_items as pii')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'pii.purchase_invoice_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->where('pi.status', PurchaseInvoice::STATUS_POSTED)
            ->whereBetween('pi.supplier_invoice_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy(
                'pi.id', 'pi.grn_no', 'pi.supplier_invoice_no', 'pi.supplier_invoice_date', 'pi.posted_at',
                's.name', 's.gstin', 's.state', 'pii.gst_rate_bp',
            )
            ->orderBy('pi.supplier_invoice_date')
            ->orderBy('pi.grn_no')
            ->orderBy('pii.gst_rate_bp')
            ->limit($limit)
            ->get([
                'pi.grn_no', 'pi.supplier_invoice_no', 'pi.supplier_invoice_date', 'pi.posted_at',
                's.name as supplier', 's.gstin as supplier_gstin', 's.state as supplier_state',
                'pii.gst_rate_bp',
                DB::raw('COALESCE(SUM(pii.taxable_value_paise), 0) as taxable_value_paise'),
                DB::raw('COALESCE(SUM(pii.gst_paise), 0) as gst_paise'),
            ]);

        $out = [];

        foreach ($rows as $row) {
            $head = $this->supplyHead($row->supplier_state !== null ? (string) $row->supplier_state : null);
            $split = $this->splitByHead((int) $row->gst_paise, $head);
            $taxable = (int) $row->taxable_value_paise;

            $out[] = [
                'grn_no' => (string) $row->grn_no,
                'supplier_invoice_no' => (string) $row->supplier_invoice_no,
                'supplier_invoice_date' => (string) $row->supplier_invoice_date,
                'supplier' => $row->supplier !== null ? (string) $row->supplier : '—',
                'supplier_gstin' => $row->supplier_gstin !== null && $row->supplier_gstin !== '' ? (string) $row->supplier_gstin : null,
                'supplier_state' => $row->supplier_state !== null && $row->supplier_state !== '' ? (string) $row->supplier_state : null,
                'head' => $this->headLabel($head),
                'rate_bp' => (int) $row->gst_rate_bp,
                'taxable_value_paise' => $taxable,
                'cgst_paise' => $split['cgst_paise'],
                'sgst_paise' => $split['sgst_paise'],
                'igst_paise' => $split['igst_paise'],
                'unclassified_gst_paise' => $split['unclassified_gst_paise'],
                'total_paise' => $taxable + (int) $row->gst_paise,
                'posted_at' => $row->posted_at !== null ? (string) $row->posted_at : null,
            ];
        }

        return $out;
    }

    /**
     * TDS actually deducted in the period, read from the wallet-ledger debits.
     *
     * Never from `payout_line_items.tds_paise`: a `below_minimum` line carries
     * a TDS figure that was computed and discarded, and a held line is written
     * afresh by every batch over the same credits. Summing the column would
     * report tax that was never withheld, several times over.
     *
     * @return array<string, mixed>
     */
    public function tdsSummary(TaxPeriod $period): array
    {
        $row = $this->tdsBaseQuery($period)
            ->selectRaw(
                'COALESCE(SUM(ABS(wle.amount_paise)), 0) as tds_paise, '.
                'COUNT(DISTINCT pli.distributor_id) as deductees, '.
                'COUNT(*) as line_count, '.
                'COALESCE(SUM(pli.gross_paise - pli.repurchase_deduction_paise - pli.admin_charge_paise), 0) as payable_paise, '.
                'COALESCE(SUM(pli.net_transferred_paise), 0) as net_paise, '.
                'COALESCE(SUM(CASE WHEN pli.tds_paise <> ABS(wle.amount_paise) THEN 1 ELSE 0 END), 0) as line_tds_mismatch'
            )
            ->first();

        $byBatchType = $this->tdsBaseQuery($period)
            ->groupBy('pb.batch_type')
            ->orderBy('pb.batch_type')
            ->get([
                'pb.batch_type',
                DB::raw('COUNT(DISTINCT pb.id) as batch_count'),
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(pli.gross_paise - pli.repurchase_deduction_paise - pli.admin_charge_paise), 0) as payable_paise'),
                DB::raw('COALESCE(SUM(ABS(wle.amount_paise)), 0) as tds_paise'),
            ]);

        $byStatus = $this->tdsBaseQuery($period)
            ->groupBy('pli.status')
            ->orderBy('pli.status')
            ->get([
                'pli.status',
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(ABS(wle.amount_paise)), 0) as tds_paise'),
            ]);

        $belowMinimum = DB::table('payout_line_items as pli')
            ->join('payout_batches as pb', 'pb.id', '=', 'pli.payout_batch_id')
            ->where('pli.status', PayoutLineItem::STATUS_BELOW_MINIMUM)
            ->whereBetween('pb.batch_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(pli.tds_paise), 0) as tds_paise')
            ->first();

        return [
            'tds_paise' => (int) ($row->tds_paise ?? 0),
            'deductees' => (int) ($row->deductees ?? 0),
            'lines' => (int) ($row->line_count ?? 0),
            'payable_paise' => (int) ($row->payable_paise ?? 0),
            'net_paise' => (int) ($row->net_paise ?? 0),
            'line_tds_mismatch' => (int) ($row->line_tds_mismatch ?? 0),

            'by_batch_type' => $byBatchType->map(fn ($r): array => [
                'batch_type' => (string) $r->batch_type,
                'batches' => (int) $r->batch_count,
                'lines' => (int) $r->line_count,
                'payable_paise' => (int) $r->payable_paise,
                'tds_paise' => (int) $r->tds_paise,
            ])->values()->all(),

            'by_status' => $byStatus->map(fn ($r): array => [
                'status' => (string) $r->status,
                'lines' => (int) $r->line_count,
                'tds_paise' => (int) $r->tds_paise,
            ])->values()->all(),

            'below_minimum_lines' => (int) ($belowMinimum->line_count ?? 0),
            'below_minimum_tds_paise' => (int) ($belowMinimum->tds_paise ?? 0),
            'held_lines' => $this->heldLines($period),
        ];
    }

    /**
     * The deductee-wise register behind Form 26Q.
     *
     * PAN is masked and read from `pan_last4`: the full number is never
     * selected here, on any code path (hard rule 8).
     *
     * @return list<array<string, mixed>>
     */
    public function tdsRegister(TaxPeriod $period, int $limit): array
    {
        $rows = $this->tdsBaseQuery($period)
            ->join('distributors as d', 'd.id', '=', 'pli.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->orderBy('pb.batch_date')
            ->orderBy('d.adn')
            ->limit($limit)
            ->get([
                'pb.batch_date', 'pb.batch_type', 'pb.id as batch_id',
                'd.adn', 'd.pan_last4',
                'u.full_name as name',
                'pli.gross_paise', 'pli.repurchase_deduction_paise', 'pli.admin_charge_paise',
                'pli.net_transferred_paise', 'pli.status', 'pli.utr_number', 'pli.dispatched_at',
                DB::raw('ABS(wle.amount_paise) as tds_paise'),
            ]);

        return array_values($rows->map(fn ($row): array => [
            'batch_date' => (string) $row->batch_date,
            'batch_type' => (string) $row->batch_type,
            'batch_id' => (int) $row->batch_id,
            'adn' => (string) $row->adn,
            'name' => $row->name !== null ? (string) $row->name : '—',
            'pan_masked' => $row->pan_last4 !== null && $row->pan_last4 !== ''
                ? str_repeat('X', 6).(string) $row->pan_last4
                : null,
            'gross_paise' => (int) $row->gross_paise,
            'repurchase_deduction_paise' => (int) $row->repurchase_deduction_paise,
            'admin_charge_paise' => (int) $row->admin_charge_paise,
            'payable_paise' => (int) $row->gross_paise - (int) $row->repurchase_deduction_paise - (int) $row->admin_charge_paise,
            'tds_paise' => (int) $row->tds_paise,
            'net_transferred_paise' => (int) $row->net_transferred_paise,
            'status' => (string) $row->status,
            'utr_number' => $row->utr_number !== null && $row->utr_number !== '' ? (string) $row->utr_number : null,
            'dispatched_at' => $row->dispatched_at !== null ? (string) $row->dispatched_at : null,
        ])->all());
    }

    /**
     * The one join every TDS figure is read off: a ledger debit that actually
     * happened, its payout line, and the batch that dates it.
     */
    private function tdsBaseQuery(TaxPeriod $period): Builder
    {
        return DB::table('wallet_ledger_entries as wle')
            ->join('payout_line_items as pli', 'pli.id', '=', 'wle.reference_id')
            ->join('payout_batches as pb', 'pb.id', '=', 'pli.payout_batch_id')
            ->where('wle.type', 'tds_debit')
            ->where('wle.reference_type', 'payout_line_item')
            ->whereBetween('pb.batch_date', [$period->from->toDateString(), $period->to->toDateString()]);
    }

    /**
     * Held lines in the period, counted from the LATEST batch of each type
     * only — the same rule the Company snapshot follows, because a held line
     * is rewritten by every batch over the same unswept credits.
     */
    private function heldLines(TaxPeriod $period): int
    {
        $batchIds = [];

        foreach ([PayoutBatch::TYPE_WEEKLY, PayoutBatch::TYPE_MONTHLY] as $type) {
            $id = DB::table('payout_batches')
                ->where('batch_type', $type)
                ->whereBetween('batch_date', [$period->from->toDateString(), $period->to->toDateString()])
                ->orderByDesc('batch_date')
                ->orderByDesc('id')
                ->value('id');

            if ($id !== null) {
                $batchIds[] = (int) $id;
            }
        }

        if ($batchIds === []) {
            return 0;
        }

        return DB::table('payout_line_items')
            ->whereIn('payout_batch_id', $batchIds)
            ->whereIn('status', PayoutLineItem::HELD_STATUSES)
            ->count();
    }

    /**
     * @return array{by_head: array<string, int>, by_rate: list<array<string, int>>, receipts_without_gstin: int, receipts_without_gstin_gst_paise: int}
     */
    private function outwardTotals(TaxPeriod $period): array
    {
        $head = DB::table('invoice_lines as il')
            ->join('invoices as inv', 'inv.id', '=', 'il.invoice_id')
            ->where('inv.issued_at', '>=', $period->from)
            ->where('inv.issued_at', '<', $period->toExclusive)
            ->selectRaw(
                'COALESCE(SUM(il.taxable_value_paise), 0) as taxable_paise, '.
                'COALESCE(SUM(il.cgst_paise), 0) as cgst_paise, '.
                'COALESCE(SUM(il.sgst_paise), 0) as sgst_paise, '.
                'COALESCE(SUM(il.igst_paise), 0) as igst_paise, '.
                'COUNT(DISTINCT il.invoice_id) as invoice_count'
            )
            ->first();

        // Cess lives on the invoice, not the line (it is always zero today,
        // and it is carried so the page does not silently drop a head if a
        // cess-bearing product is ever listed).
        $cess = (int) DB::table('invoices')
            ->where('issued_at', '>=', $period->from)
            ->where('issued_at', '<', $period->toExclusive)
            ->sum('cess_paise');

        // Documents issued while the seller GSTIN was unset. The generator
        // freezes it per document, so this is a fact about the period rather
        // than about the setting, and their tax is reported but is not a
        // GSTR-1 supply until the document is re-issued.
        $receipts = DB::table('invoices')
            ->whereNull('seller_gstin')
            ->where('issued_at', '>=', $period->from)
            ->where('issued_at', '<', $period->toExclusive)
            ->selectRaw(
                'COUNT(*) as receipt_count, '.
                'COALESCE(SUM(cgst_paise + sgst_paise + igst_paise + cess_paise), 0) as gst_paise'
            )
            ->first();

        $byRate = DB::table('invoice_lines as il')
            ->join('invoices as inv', 'inv.id', '=', 'il.invoice_id')
            ->where('inv.issued_at', '>=', $period->from)
            ->where('inv.issued_at', '<', $period->toExclusive)
            ->groupBy('il.gst_rate_bp')
            ->orderBy('il.gst_rate_bp')
            ->get([
                'il.gst_rate_bp',
                DB::raw('COALESCE(SUM(il.taxable_value_paise), 0) as taxable_paise'),
                DB::raw('COALESCE(SUM(il.cgst_paise), 0) as cgst_paise'),
                DB::raw('COALESCE(SUM(il.sgst_paise), 0) as sgst_paise'),
                DB::raw('COALESCE(SUM(il.igst_paise), 0) as igst_paise'),
            ]);

        return [
            'by_head' => [
                'taxable_paise' => (int) ($head->taxable_paise ?? 0),
                'cgst_paise' => (int) ($head->cgst_paise ?? 0),
                'sgst_paise' => (int) ($head->sgst_paise ?? 0),
                'igst_paise' => (int) ($head->igst_paise ?? 0),
                'cess_paise' => $cess,
                'invoices' => (int) ($head->invoice_count ?? 0),
            ],
            'by_rate' => array_values($byRate->map(fn ($r): array => [
                'rate_bp' => (int) $r->gst_rate_bp,
                'taxable_paise' => (int) $r->taxable_paise,
                'cgst_paise' => (int) $r->cgst_paise,
                'sgst_paise' => (int) $r->sgst_paise,
                'igst_paise' => (int) $r->igst_paise,
            ])->all()),
            'receipts_without_gstin' => (int) ($receipts->receipt_count ?? 0),
            'receipts_without_gstin_gst_paise' => (int) ($receipts->gst_paise ?? 0),
        ];
    }

    /**
     * Input credit, with the head derived from the supplier's state AS
     * RECORDED TODAY — the goods receipt stores no head of its own. A supplier
     * whose state is missing or unreadable lands in `unclassified`, which is
     * never added into any head and never included in the net payable.
     *
     * @return array{by_head: array<string, int>, by_rate: list<array<string, int>>}
     */
    private function inwardTotals(TaxPeriod $period): array
    {
        $groups = DB::table('purchase_invoice_items as pii')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'pii.purchase_invoice_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->where('pi.status', PurchaseInvoice::STATUS_POSTED)
            ->whereBetween('pi.supplier_invoice_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('s.state', 'pii.gst_rate_bp')
            ->orderBy('pii.gst_rate_bp')
            ->get([
                's.state as supplier_state',
                'pii.gst_rate_bp',
                DB::raw('COALESCE(SUM(pii.taxable_value_paise), 0) as taxable_paise'),
                DB::raw('COALESCE(SUM(pii.gst_paise), 0) as gst_paise'),
            ]);

        $invoices = (int) DB::table('purchase_invoices')
            ->where('status', PurchaseInvoice::STATUS_POSTED)
            ->whereBetween('supplier_invoice_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->count();

        $head = [
            'taxable_paise' => 0,
            'cgst_paise' => 0,
            'sgst_paise' => 0,
            'igst_paise' => 0,
            'unclassified_gst_paise' => 0,
            'unclassified_taxable_paise' => 0,
            'invoices' => $invoices,
        ];

        /** @var array<int, array<string, int>> $byRate */
        $byRate = [];

        foreach ($groups as $group) {
            $supplyHead = $this->supplyHead($group->supplier_state !== null ? (string) $group->supplier_state : null);
            $split = $this->splitByHead((int) $group->gst_paise, $supplyHead);
            $rate = (int) $group->gst_rate_bp;
            $taxable = (int) $group->taxable_paise;

            $head['cgst_paise'] += $split['cgst_paise'];
            $head['sgst_paise'] += $split['sgst_paise'];
            $head['igst_paise'] += $split['igst_paise'];
            $head['unclassified_gst_paise'] += $split['unclassified_gst_paise'];

            if ($supplyHead === 'unclassified') {
                $head['unclassified_taxable_paise'] += $taxable;
            } else {
                $head['taxable_paise'] += $taxable;
            }

            $byRate[$rate] ??= [
                'rate_bp' => $rate,
                'taxable_paise' => 0,
                'cgst_paise' => 0,
                'sgst_paise' => 0,
                'igst_paise' => 0,
                'unclassified_gst_paise' => 0,
            ];

            $byRate[$rate]['taxable_paise'] += $taxable;
            $byRate[$rate]['cgst_paise'] += $split['cgst_paise'];
            $byRate[$rate]['sgst_paise'] += $split['sgst_paise'];
            $byRate[$rate]['igst_paise'] += $split['igst_paise'];
            $byRate[$rate]['unclassified_gst_paise'] += $split['unclassified_gst_paise'];
        }

        ksort($byRate);

        return ['by_head' => $head, 'by_rate' => array_values($byRate)];
    }

    /**
     * The statutory numeric GST code for a place of supply as it is stored.
     *
     * Normalised first, the same way {@see supplyHead()} normalises a supplier
     * state: invoices issued before the generator canonicalised the state hold
     * whatever the order carried — `TELANGANA`, `TG` — and the code map is
     * keyed by the canonical name alone, so the raw value misses and the
     * register shows no code for a state it plainly knows. A spelling that is
     * not a state we recognise still has no code, and is left blank rather
     * than guessed at.
     */
    private function gstStateCode(?string $state): ?string
    {
        $canonical = IndianStates::canonical($state);

        return $canonical === null ? null : (IndianStates::gstCodes()[$canonical] ?? null);
    }

    /**
     * Intra-state when the supplier's state equals the supply-from state, both
     * normalised the way the tax invoice normalises them; unclassified when
     * the supplier's state is missing or is not a state we recognise.
     */
    private function supplyHead(?string $supplierState): string
    {
        $supplier = IndianStates::canonical($supplierState);
        $seller = IndianStates::canonical($this->settings->sellerState());

        if ($supplier === null || $seller === null) {
            return 'unclassified';
        }

        return $supplier === $seller ? 'intra' : 'inter';
    }

    /**
     * Split a GST amount into heads the way {@see InvoiceGenerator} does:
     * CGST takes the floor of the half and SGST the remainder, so an odd paise
     * lands in exactly one place and the two always sum back to the total.
     *
     * @return array{cgst_paise: int, sgst_paise: int, igst_paise: int, unclassified_gst_paise: int}
     */
    private function splitByHead(int $gstPaise, string $head): array
    {
        if ($head === 'intra') {
            $cgst = intdiv($gstPaise, 2);

            return ['cgst_paise' => $cgst, 'sgst_paise' => $gstPaise - $cgst, 'igst_paise' => 0, 'unclassified_gst_paise' => 0];
        }

        if ($head === 'inter') {
            return ['cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => $gstPaise, 'unclassified_gst_paise' => 0];
        }

        return ['cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 0, 'unclassified_gst_paise' => $gstPaise];
    }

    private function headLabel(string $head): string
    {
        return match ($head) {
            'intra' => 'Intra-state',
            'inter' => 'Inter-state',
            default => 'Unclassified',
        };
    }

    /**
     * Spread a partial reversal across the invoice's heads in the proportion
     * the invoice itself carried them, with the remainder to the largest head
     * so the parts sum back to the amount actually reversed.
     *
     * @param  array<string, int>  $heads
     * @return array{cgst_paise: int, sgst_paise: int, igst_paise: int}
     */
    private function prorateAcrossHeads(int $amount, array $heads): array
    {
        $total = array_sum($heads);

        if ($total <= 0) {
            return ['cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 0];
        }

        $split = [];
        $allocated = 0;

        foreach ($heads as $key => $value) {
            $split[$key] = intdiv($amount * $value, $total);
            $allocated += $split[$key];
        }

        if ($allocated !== $amount && $heads !== []) {
            $largest = array_search(max($heads), $heads, true);

            if ($largest !== false) {
                $split[$largest] += $amount - $allocated;
            }
        }

        return [
            'cgst_paise' => $split['cgst_paise'] ?? 0,
            'sgst_paise' => $split['sgst_paise'] ?? 0,
            'igst_paise' => $split['igst_paise'] ?? 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function sumCreditNotes(array $rows): array
    {
        $totals = [
            'taxable_paise' => 0,
            'cgst_paise' => 0,
            'sgst_paise' => 0,
            'igst_paise' => 0,
            'unclassified_gst_paise' => 0,
            'notes' => 0,
        ];

        foreach ($rows as $row) {
            // Credit-note rows carry negative amounts so the register reads as
            // a document; the summary subtracts them, so it takes them positive.
            $totals['taxable_paise'] += -(int) $row['taxable_value_paise'];
            $totals['cgst_paise'] += -(int) $row['cgst_paise'];
            $totals['sgst_paise'] += -(int) $row['sgst_paise'];
            $totals['igst_paise'] += -(int) $row['igst_paise'];
            $totals['unclassified_gst_paise'] += (int) ($row['unclassified_gst_paise'] ?? 0);
            $totals['notes']++;
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, int|null>>
     */
    private function creditNotesByRate(array $rows): array
    {
        /** @var array<string, array<string, int|null>> $byRate */
        $byRate = [];

        foreach ($rows as $row) {
            $rate = $row['rate_bp'] === null ? null : (int) $row['rate_bp'];
            $key = $rate === null ? 'none' : (string) $rate;

            $byRate[$key] ??= [
                'rate_bp' => $rate,
                'taxable_paise' => 0,
                'cgst_paise' => 0,
                'sgst_paise' => 0,
                'igst_paise' => 0,
            ];

            $byRate[$key]['taxable_paise'] = (int) $byRate[$key]['taxable_paise'] + -(int) $row['taxable_value_paise'];
            $byRate[$key]['cgst_paise'] = (int) $byRate[$key]['cgst_paise'] + -(int) $row['cgst_paise'];
            $byRate[$key]['sgst_paise'] = (int) $byRate[$key]['sgst_paise'] + -(int) $row['sgst_paise'];
            $byRate[$key]['igst_paise'] = (int) $byRate[$key]['igst_paise'] + -(int) $row['igst_paise'];
        }

        ksort($byRate);

        return array_values($byRate);
    }

    /**
     * The taxable value each refund reversed, read off the same entry's
     * `revenue.sales` debit — the credit note's taxable value, rather than a
     * figure worked back out of the tax.
     *
     * @param  list<int>  $txIds
     * @return array<int, int>
     */
    private function reversedTaxableByTx(array $txIds): array
    {
        if ($txIds === []) {
            return [];
        }

        $rows = DB::table('ledger_entries as le')
            ->join('ledger_accounts as la', 'la.id', '=', 'le.account_id')
            ->whereIn('le.ledger_tx_id', $txIds)
            ->where('la.code', self::SALES_ACCOUNT)
            ->where('le.side', 'debit')
            ->groupBy('le.ledger_tx_id')
            ->get([
                'le.ledger_tx_id',
                DB::raw('COALESCE(SUM(ABS(le.amount_paise)), 0) as taxable_paise'),
            ]);

        $taxable = [];

        foreach ($rows as $row) {
            $taxable[(int) $row->ledger_tx_id] = (int) $row->taxable_paise;
        }

        return $taxable;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, string>
     */
    private function refundReasonsByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $reasons = [];

        $rows = DB::table('return_requests')
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get(['order_id', 'reason']);

        foreach ($rows as $row) {
            // Last one wins: the latest request on the order is the one the
            // refund was approved against.
            $reasons[(int) $row->order_id] = (string) $row->reason;
        }

        return $reasons;
    }

    /**
     * @param  list<int>  $invoiceIds
     * @return array<int, list<array{hsn_code: string, qty: int, gst_rate_bp: int, taxable_value_paise: int, cgst_paise: int, sgst_paise: int, igst_paise: int}>>
     */
    private function invoiceLinesByInvoice(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $lines = [];

        $rows = DB::table('invoice_lines')
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('id')
            ->get(['invoice_id', 'hsn_code', 'qty', 'gst_rate_bp', 'taxable_value_paise', 'cgst_paise', 'sgst_paise', 'igst_paise']);

        foreach ($rows as $row) {
            $lines[(int) $row->invoice_id][] = [
                'hsn_code' => (string) $row->hsn_code,
                'qty' => (int) $row->qty,
                'gst_rate_bp' => (int) $row->gst_rate_bp,
                'taxable_value_paise' => (int) $row->taxable_value_paise,
                'cgst_paise' => (int) $row->cgst_paise,
                'sgst_paise' => (int) $row->sgst_paise,
                'igst_paise' => (int) $row->igst_paise,
            ];
        }

        return $lines;
    }

    /** Paid orders in the period that hold no tax invoice (CGST §31). */
    private function ordersWithoutInvoice(TaxPeriod $period): int
    {
        return $this->invoiceGaps->query()
            ->where('paid_at', '>=', $period->from)
            ->where('paid_at', '<', $period->toExclusive)
            ->count();
    }

    /**
     * The GST on the order book for the same window, on the shipped-date clock
     * the Profit summary uses. Carried as a memo so the gap between this page
     * and that one is a figure on the page rather than a question.
     */
    private function orderBookGstPaise(TaxPeriod $period): int
    {
        $sales = $this->sales->totals(SalesScope::all(), $period->from, $period->to, SalesReportService::BASIS_SHIPPED);
        $refunds = $this->sales->refundTotals(SalesScope::all(), $period->from, $period->to);

        return $sales['gst_paise'] - $refunds['gst_paise'];
    }

    /**
     * Refunds approved in the period whose ledger entry reversed no GST — a
     * buyback outside the cooling-off window, where the tax stays remitted.
     * Counted so the credit-note total can be read as complete.
     */
    private function refundsWithoutTaxCredit(TaxPeriod $period): int
    {
        return DB::table('ledger_tx as tx')
            ->where('tx.source_type', self::REFUND_SOURCE_TYPE)
            ->where('tx.occurred_at', '>=', $period->from)
            ->where('tx.occurred_at', '<', $period->toExclusive)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('ledger_entries as le')
                    ->join('ledger_accounts as la', 'la.id', '=', 'le.account_id')
                    ->whereColumn('le.ledger_tx_id', 'tx.id')
                    ->where('la.code', self::GST_OUTPUT_ACCOUNT)
                    ->where('le.side', 'debit');
            })
            ->count();
    }
}
