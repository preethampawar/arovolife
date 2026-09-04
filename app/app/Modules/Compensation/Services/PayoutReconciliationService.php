<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Http\UploadedFile;

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
 */
final class PayoutReconciliationService
{
    /** Column headers we recognise, lower-cased and stripped to letters. */
    private const ADN_HEADERS = ['adn', 'distributorid', 'distributorno', 'beneficiaryid'];

    private const UTR_HEADERS = ['utr', 'utrnumber', 'utrno', 'referenceno', 'transactionref'];

    private const STATUS_HEADERS = ['status', 'transactionstatus', 'result'];

    private const REASON_HEADERS = ['failurereason', 'reason', 'remarks', 'errordescription'];

    /** Values in the status column read as "the money arrived". */
    private const SUCCESS_VALUES = ['success', 'successful', 'processed', 'completed', 'paid', 'transferred'];

    private const FAILURE_VALUES = ['failed', 'failure', 'rejected', 'returned', 'reversed', 'bounced'];

    public function __construct(private readonly RazorpayPayoutDispatchService $dispatcher) {}

    /**
     * @return array{
     *   rows: int, matched: int, transferred: int, failed: int,
     *   unmatched: list<string>, skipped: list<string>, errors: list<string>
     * }
     */
    public function import(PayoutBatch $batch, UploadedFile $file, int $actorId): array
    {
        $summary = [
            'rows' => 0,
            'matched' => 0,
            'transferred' => 0,
            'failed' => 0,
            'unmatched' => [],
            'skipped' => [],
            'errors' => [],
        ];

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            $summary['errors'][] = 'The uploaded file could not be read.';

            return $summary;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $summary['errors'][] = 'The uploaded file is empty.';

            return $summary;
        }

        $columns = $this->mapColumns($header);
        if ($columns['adn'] === null) {
            fclose($handle);
            $summary['errors'][] = 'No ADN column found. The file must have a header row with an "ADN" column.';

            return $summary;
        }
        if ($columns['status'] === null) {
            fclose($handle);
            $summary['errors'][] = 'No Status column found. The file must have a header row with a "Status" column.';

            return $summary;
        }

        // One line-item lookup for the whole batch, keyed by ADN — a file of
        // 5,000 rows must not be 5,000 joins.
        $linesByAdn = $this->lineItemsByAdn($batch);

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

            $line = $linesByAdn[$adn] ?? null;
            if ($line === null) {
                $summary['unmatched'][] = $adn;

                continue;
            }

            $verdict = $this->verdict($this->cell($row, $columns['status']));
            if ($verdict === null) {
                $summary['skipped'][] = $adn.' (unrecognised status)';

                continue;
            }

            // Only a line still waiting for the bank may be settled by a file.
            // Re-importing the same response must not rewrite history.
            if ($line->status !== PayoutLineItem::STATUS_PENDING) {
                $summary['skipped'][] = $adn.' (already '.$line->status.')';

                continue;
            }

            $summary['matched']++;

            if ($verdict === PayoutLineItem::STATUS_TRANSFERRED) {
                $line->forceFill([
                    'status' => PayoutLineItem::STATUS_TRANSFERRED,
                    'utr_number' => $columns['utr'] !== null
                        ? (trim($this->cell($row, $columns['utr'])) ?: $line->utr_number)
                        : $line->utr_number,
                    'failure_reason' => null,
                ])->save();

                $summary['transferred']++;

                continue;
            }

            $reason = $columns['reason'] !== null ? trim($this->cell($row, $columns['reason'])) : '';

            $line->forceFill([
                'status' => PayoutLineItem::STATUS_FAILED,
                'failure_reason' => mb_substr($reason !== '' ? $reason : 'The bank reported this transfer as failed.', 0, 500),
            ])->save();

            $summary['failed']++;
        }

        fclose($handle);

        AuditLog::create([
            'actor_id' => $actorId,
            'action' => 'payout.batch.reconciled',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
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
            ],
            'ip' => request()->ip(),
        ]);

        $this->dispatcher->refreshBatchStatus($batch->refresh());

        return $summary;
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
     * @return array{adn: int|null, utr: int|null, status: int|null, reason: int|null}
     */
    private function mapColumns(array $header): array
    {
        $found = ['adn' => null, 'utr' => null, 'status' => null, 'reason' => null];

        foreach ($header as $index => $label) {
            // Strip the UTF-8 BOM Excel writes onto the first header cell.
            $normalized = strtolower((string) preg_replace('/[^A-Za-z]/', '', (string) $label));

            foreach ([
                'adn' => self::ADN_HEADERS,
                'utr' => self::UTR_HEADERS,
                'status' => self::STATUS_HEADERS,
                'reason' => self::REASON_HEADERS,
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
