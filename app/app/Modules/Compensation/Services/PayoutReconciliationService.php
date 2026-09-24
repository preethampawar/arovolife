<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\PayoutBankFile;
use App\Modules\Compensation\Models\PayoutBankFileRow;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manual-NEFT reconciliation: read the bank's response file back into the
 * batch.
 *
 * Finance downloads the NEFT CSV, uploads it to the bank's portal, and gets a
 * response file naming which lines settled and which bounced. This turns that
 * file into line-item statuses and UTRs — the manual-mode equivalent of the
 * Razorpay payout webhook.
 *
 * Deliberately forgiving about the file's shape (banks all differ) and
 * unforgiving about what it will change: a row can mark a `pending` line
 * transferred or failed and nothing else. A row naming an unknown ADN, or one
 * already settled, is reported back to the admin rather than applied.
 *
 * Two further refusals, because a wrong response file must not be able to
 * record a distributor as paid when they were not:
 *
 *   amount    — when the file carries an amount column, the row's amount has
 *               to equal the line's `net_transferred_paise`. A file from
 *               another batch, or one column out of step, is rejected row by
 *               row instead of importing silently (QA F14).
 *   reference — one UTR settles one line. A reference already recorded on any
 *               payout line, in this file or an earlier import, is rejected
 *               (QA F15); `uniq_payout_line_items_utr` enforces the same rule
 *               in the database, where a concurrent import is also visible.
 *
 * Every uploaded file is kept (encrypted, {@see PayoutBankFileVault}) before a
 * single row is applied, with one parsed row per CSV row recording what the
 * file said and what was done with it — the timeline and the import
 * comparisons on the batch page read those. A file that cannot be stored is
 * refused outright: nothing is applied without its evidence.
 */
final class PayoutReconciliationService
{
    /** Column headers we recognise, lower-cased and stripped to letters. */
    private const ADN_HEADERS = ['adn', 'distributorid', 'distributorno', 'beneficiaryid'];

    private const UTR_HEADERS = ['utr', 'utrnumber', 'utrno', 'referenceno', 'transactionref'];

    private const STATUS_HEADERS = ['status', 'transactionstatus', 'result'];

    private const REASON_HEADERS = ['failurereason', 'reason', 'remarks', 'errordescription'];

    /**
     * Columns carrying the rupee amount the bank actually moved. Our own export
     * writes "Net Amount (₹)", which normalises to `netamount`.
     */
    private const AMOUNT_HEADERS = ['amount', 'netamount', 'netamountinr', 'netamountrs', 'nettransferred', 'transferamount', 'amountinr', 'creditamount', 'txnamount', 'transactionamount'];

    /** Values in the status column read as "the money arrived". */
    private const SUCCESS_VALUES = ['success', 'successful', 'processed', 'completed', 'paid', 'transferred'];

    private const FAILURE_VALUES = ['failed', 'failure', 'rejected', 'returned', 'reversed', 'bounced'];

    public function __construct(
        private readonly RazorpayPayoutDispatchService $dispatcher,
        private readonly PayoutBankFileVault $vault,
    ) {}

    /**
     * Every line's settlement state on a batch, for the before/after digests
     * of a bank-response import.
     *
     * @return array<int, array{status: string, utr_number: string|null}>
     */
    private function lineStatuses(PayoutBatch $batch): array
    {
        return PayoutLineItem::where('payout_batch_id', $batch->id)
            ->orderBy('id')
            ->get(['id', 'status', 'utr_number'])
            ->mapWithKeys(fn (PayoutLineItem $line): array => [(int) $line->id => [
                'status' => (string) $line->status,
                'utr_number' => $line->utr_number,
            ]])
            ->all();
    }

    /**
     * @return array{
     *   rows: int, matched: int, transferred: int, failed: int,
     *   unmatched: list<string>, skipped: list<string>, rejected: list<string>,
     *   errors: list<string>, amount_checked: bool, bank_file_id: int|null
     * }
     */
    public function import(PayoutBatch $batch, UploadedFile $file, int $actorId): array
    {
        $lineStatusesBefore = AuditDigests::of($this->lineStatuses($batch));

        $summary = [
            'rows' => 0,
            'matched' => 0,
            'transferred' => 0,
            'failed' => 0,
            'unmatched' => [],
            'skipped' => [],
            // Rows the file names but this import refuses to apply: the amount
            // does not match the line, or the bank reference is already in use.
            'rejected' => [],
            'errors' => [],
            'amount_checked' => false,
            'bank_file_id' => null,
        ];

        $bytes = @file_get_contents($file->getRealPath());
        if ($bytes === false) {
            $summary['errors'][] = 'The uploaded file could not be read.';

            return $summary;
        }

        try {
            $bankFile = $this->vault->keep($batch, PayoutBankFile::DIRECTION_IMPORT, $bytes, $file->getClientOriginalName(), $actorId);
        } catch (Throwable $e) {
            Log::error('Bank response file could not be stored — import refused', [
                'payout_batch_id' => $batch->id,
                'error' => $e->getMessage(),
            ]);
            $summary['errors'][] = 'The file could not be saved to storage, so nothing was imported. Every bank file is kept as evidence before it is applied — try again, and tell the developer if it keeps failing.';

            return $summary;
        }

        $summary['bank_file_id'] = (int) $bankFile->id;

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            $summary['errors'][] = 'The uploaded file could not be read.';

            return $this->refuse($bankFile, $summary);
        }
        fwrite($handle, $bytes);
        rewind($handle);

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $summary['errors'][] = 'The uploaded file is empty.';

            return $this->refuse($bankFile, $summary);
        }

        $columns = $this->mapColumns($header);
        if ($columns['adn'] === null) {
            fclose($handle);
            $summary['errors'][] = 'No ADN column found. The file must have a header row with an "ADN" column.';

            return $this->refuse($bankFile, $summary);
        }
        if ($columns['status'] === null) {
            fclose($handle);
            $summary['errors'][] = 'No Status column found. The file must have a header row with a "Status" column.';

            return $this->refuse($bankFile, $summary);
        }

        /** @var list<array{row_no: int, adn: string, payout_line_item_id: int|null, bank_status: string|null, verdict: string|null, utr: string|null, amount_paise: int|null, reason: string|null, result: string}> $recorded */
        $recorded = [];

        $summary['amount_checked'] = $columns['amount'] !== null;

        // One line-item lookup for the whole batch, keyed by ADN — a file of
        // 5,000 rows must not be 5,000 joins.
        $linesByAdn = $this->lineItemsByAdn($batch);

        // Bank references already recorded anywhere in the platform. One
        // transfer settles one line: a repeated reference means the file is
        // wrong, not that two distributors were paid by one NEFT.
        $usedUtrs = $this->utrsAlreadyRecorded();

        while (($row = fgetcsv($handle)) !== false) {
            // fgetcsv yields [null] for a blank line.
            if ($row === [null] || $row === []) {
                continue;
            }

            $summary['rows']++;

            $adn = strtoupper(trim($this->cell($row, $columns['adn'])));
            if ($adn === '') {
                continue;
            }

            $rawStatus = trim($this->cell($row, $columns['status']));
            $verdict = $this->verdict($rawStatus);
            $utr = $columns['utr'] !== null ? trim($this->cell($row, $columns['utr'])) : '';
            $claimedPaise = $columns['amount'] !== null
                ? $this->amountPaise($this->cell($row, $columns['amount']))
                : null;
            $reason = $columns['reason'] !== null ? trim($this->cell($row, $columns['reason'])) : '';

            $line = $linesByAdn[$adn] ?? null;

            // What the file said about this ADN, whatever becomes of the row —
            // the import comparisons are built from these.
            $record = function (string $result) use (&$recorded, $summary, $adn, $line, $rawStatus, $verdict, $utr, $claimedPaise, $reason): void {
                $recorded[] = [
                    'row_no' => $summary['rows'],
                    'adn' => $adn,
                    'payout_line_item_id' => $line !== null ? (int) $line->id : null,
                    'bank_status' => $rawStatus !== '' ? $rawStatus : null,
                    'verdict' => $verdict ?? 'unrecognised',
                    'utr' => $utr !== '' ? $utr : null,
                    'amount_paise' => $claimedPaise,
                    'reason' => $reason !== '' ? $reason : null,
                    'result' => $result,
                ];
            };

            if ($line === null) {
                $summary['unmatched'][] = $adn;
                $record(PayoutBankFileRow::RESULT_UNMATCHED);

                continue;
            }

            if ($verdict === null) {
                $summary['skipped'][] = $adn.' (unrecognised status)';
                $record(PayoutBankFileRow::RESULT_UNRECOGNISED_STATUS);

                continue;
            }

            // Only a line still waiting for the bank may be settled by a file.
            // Re-importing the same response must not rewrite history.
            if ($line->status !== PayoutLineItem::STATUS_PENDING) {
                $summary['skipped'][] = $adn.' (already '.$line->status.')';
                $record(PayoutBankFileRow::RESULT_ALREADY_SETTLED);

                continue;
            }

            // The bank's amount has to be the amount this line owes. A response
            // file whose amounts belong to another batch — or one column out of
            // step — used to import silently and record the wrong people paid
            // (QA F14). A blank cell is not a mismatch: banks routinely leave it
            // empty on a returned transfer.
            if ($claimedPaise !== null && $claimedPaise !== (int) $line->net_transferred_paise) {
                $summary['rejected'][] = $adn.' (amount '.$this->rupees($claimedPaise).
                    ' does not match the line’s '.$this->rupees((int) $line->net_transferred_paise).')';
                $record(PayoutBankFileRow::RESULT_REJECTED_AMOUNT);

                continue;
            }

            // One UTR, one settled line — within this file and against every
            // line item already recorded (QA F15). `uniq_payout_line_items_utr`
            // is the database's half of the same rule.
            if ($utr !== '' && isset($usedUtrs[strtoupper($utr)])) {
                $summary['rejected'][] = $adn.' (bank reference already settles another payout line)';
                $record(PayoutBankFileRow::RESULT_REJECTED_UTR);

                continue;
            }

            $summary['matched']++;

            if ($verdict === PayoutLineItem::STATUS_TRANSFERRED) {
                $line->forceFill([
                    'status' => PayoutLineItem::STATUS_TRANSFERRED,
                    'utr_number' => $utr !== '' ? $utr : $line->utr_number,
                    'failure_reason' => null,
                ])->save();

                if ($utr !== '') {
                    $usedUtrs[strtoupper($utr)] = true;
                }

                $summary['transferred']++;
                $record(PayoutBankFileRow::RESULT_MARKED_PAID);

                continue;
            }

            $line->forceFill([
                'status' => PayoutLineItem::STATUS_FAILED,
                'failure_reason' => mb_substr($reason !== '' ? $reason : 'The bank reported this transfer as failed.', 0, 500),
            ])->save();

            $summary['failed']++;
            $record(PayoutBankFileRow::RESULT_MARKED_FAILED);
        }

        fclose($handle);

        $this->vault->finish($bankFile, PayoutBankFile::OUTCOME_APPLIED, $this->fileSummary($summary), $recorded);

        AuditLog::create([
            'actor_id' => $actorId,
            'action' => 'payout.batch.reconciled',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            'before_hash' => $lineStatusesBefore,
            'after_hash' => AuditDigests::of($this->lineStatuses($batch)),
            'details' => [
                'batch_type' => $batch->batch_type,
                'batch_date' => $batch->batch_date->toDateString(),
                'file_name' => $file->getClientOriginalName(),
                'rows' => $summary['rows'],
                'matched' => $summary['matched'],
                'transferred' => $summary['transferred'],
                'failed' => $summary['failed'],
                // Capped: an admin uploading the wrong file entirely must not
                // write thousands of ADNs into one audit row.
                'unmatched' => array_slice($summary['unmatched'], 0, 50),
                'unmatched_count' => count($summary['unmatched']),
                'skipped_count' => count($summary['skipped']),
                'rejected' => array_slice($summary['rejected'], 0, 50),
                'rejected_count' => count($summary['rejected']),
                'amount_checked' => $summary['amount_checked'],
                'payout_bank_file_id' => (int) $bankFile->id,
            ],
            'ip' => request()->ip(),
        ]);

        $this->dispatcher->refreshBatchStatus($batch->refresh());

        return $summary;
    }

    /**
     * Keep a file that could not be read at all, with the reason, and apply
     * nothing from it.
     *
     * @param  array{rows: int, matched: int, transferred: int, failed: int, unmatched: list<string>, skipped: list<string>, rejected: list<string>, errors: list<string>, amount_checked: bool, bank_file_id: int|null}  $summary
     * @return array{rows: int, matched: int, transferred: int, failed: int, unmatched: list<string>, skipped: list<string>, rejected: list<string>, errors: list<string>, amount_checked: bool, bank_file_id: int|null}
     */
    private function refuse(PayoutBankFile $bankFile, array $summary): array
    {
        $this->vault->finish($bankFile, PayoutBankFile::OUTCOME_REFUSED, $this->fileSummary($summary), []);

        return $summary;
    }

    /**
     * The counts kept on the stored file's row — the timeline's one-line
     * description of what the import did.
     *
     * @param  array{rows: int, matched: int, transferred: int, failed: int, unmatched: list<string>, skipped: list<string>, rejected: list<string>, errors: list<string>, amount_checked: bool, bank_file_id: int|null}  $summary
     * @return array<string, mixed>
     */
    private function fileSummary(array $summary): array
    {
        return [
            'rows' => $summary['rows'],
            'transferred' => $summary['transferred'],
            'failed' => $summary['failed'],
            'skipped' => count($summary['skipped']),
            'rejected' => count($summary['rejected']),
            'unmatched' => count($summary['unmatched']),
            'amount_checked' => $summary['amount_checked'],
            'errors' => $summary['errors'],
        ];
    }

    /**
     * The batch's line items keyed by the distributor's ADN.
     *
     * @return array<string, PayoutLineItem>
     */
    private function lineItemsByAdn(PayoutBatch $batch): array
    {
        $rows = PayoutLineItem::query()
            ->where('payout_line_items.payout_batch_id', $batch->id)
            ->join('distributors', 'distributors.id', '=', 'payout_line_items.distributor_id')
            ->select('payout_line_items.*', 'distributors.adn')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[strtoupper((string) $row->getAttribute('adn'))] = $row;
        }

        return $map;
    }

    /**
     * Locate the columns we care about by header name, tolerating the
     * punctuation and casing every bank formats differently.
     *
     * @param  list<string|null>  $header
     * @return array{adn: int|null, utr: int|null, status: int|null, reason: int|null, amount: int|null}
     */
    private function mapColumns(array $header): array
    {
        $found = ['adn' => null, 'utr' => null, 'status' => null, 'reason' => null, 'amount' => null];

        foreach ($header as $index => $label) {
            // Strip the UTF-8 BOM Excel writes onto the first header cell.
            $normalized = strtolower((string) preg_replace('/[^A-Za-z]/', '', (string) $label));

            foreach ([
                'adn' => self::ADN_HEADERS,
                'utr' => self::UTR_HEADERS,
                'status' => self::STATUS_HEADERS,
                'reason' => self::REASON_HEADERS,
                'amount' => self::AMOUNT_HEADERS,
            ] as $field => $candidates) {
                if ($found[$field] === null && in_array($normalized, $candidates, true)) {
                    $found[$field] = (int) $index;
                }
            }
        }

        return $found;
    }

    /** `transferred`, `failed`, or null when the bank's wording is unknown. */
    private function verdict(string $raw): ?string
    {
        $value = strtolower((string) preg_replace('/[^A-Za-z]/', '', $raw));

        return match (true) {
            in_array($value, self::SUCCESS_VALUES, true) => PayoutLineItem::STATUS_TRANSFERRED,
            in_array($value, self::FAILURE_VALUES, true) => PayoutLineItem::STATUS_FAILED,
            default => null,
        };
    }

    /**
     * Every bank reference already recorded on a payout line, upper-cased and
     * keyed for lookup. Platform-wide, not batch-wide: a reference re-used
     * across two batches is the same double settlement as one re-used inside
     * a single file.
     *
     * @return array<string, true>
     */
    private function utrsAlreadyRecorded(): array
    {
        $used = [];

        PayoutLineItem::query()
            ->whereNotNull('utr_number')
            ->where('utr_number', '!=', '')
            ->pluck('utr_number')
            ->each(function (string $utr) use (&$used): void {
                $used[strtoupper(trim($utr))] = true;
            });

        return $used;
    }

    /**
     * The rupee amount a bank row claims, in paise, or null when the cell is
     * blank or not a number. Tolerates the ₹ sign, Indian digit grouping and
     * the parentheses some banks use for a returned amount.
     */
    private function amountPaise(string $raw): ?int
    {
        $value = trim(str_replace([',', ' ', "\u{20B9}", 'INR', 'Rs.', 'Rs'], '', $raw));
        $value = trim($value, '()');

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /** ₹ figure for an admin-facing rejection line. */
    private function rupees(int $paise): string
    {
        return '₹'.IndianNumber::format($paise / 100, 2);
    }

    /** @param  list<string|null>  $row */
    private function cell(array $row, ?int $index): string
    {
        if ($index === null) {
            return '';
        }

        // Undo the leading apostrophe our own export adds to neutralise
        // formula injection, so a round-tripped file still matches.
        return ltrim((string) ($row[$index] ?? ''), "'");
    }
}
