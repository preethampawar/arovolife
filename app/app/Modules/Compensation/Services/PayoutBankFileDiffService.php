<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\PayoutBankFile;
use App\Modules\Compensation\Models\PayoutBankFileRow;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Support\BankFiles\BankFileComparison;
use App\Modules\Compensation\Support\BankFiles\BankFileImportHistory;
use App\Modules\Compensation\Support\BankFiles\BankFileRowChange;
use Illuminate\Support\Collection;

/**
 * Compares the bank response files imported into a batch.
 *
 * Reads only the parsed rows ({@see PayoutBankFileRow}), never the encrypted
 * files, so a comparison needs no decryption and keeps working after the
 * retention sweep has deleted the files themselves. Rows are matched on ADN.
 */
final class PayoutBankFileDiffService
{
    public const KIND_NEW = 'new';

    public const KIND_MISSING = 'missing';

    public const KIND_STATUS = 'status';

    public const KIND_UTR = 'utr';

    public const KIND_AMOUNT = 'amount';

    public const KIND_REASON = 'reason';

    /** What changed from `$from` (the earlier file) to `$to`. */
    public function compare(PayoutBankFile $from, PayoutBankFile $to): BankFileComparison
    {
        [$before, $beforeDuplicates] = $this->rowsByAdn($from);
        [$after, $afterDuplicates] = $this->rowsByAdn($to);

        $counts = array_fill_keys([
            self::KIND_NEW, self::KIND_MISSING, self::KIND_STATUS,
            self::KIND_UTR, self::KIND_AMOUNT, self::KIND_REASON,
        ], 0);
        $changes = [];
        $unchanged = 0;

        $adns = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($adns, SORT_NATURAL);

        foreach ($adns as $adn) {
            $old = $before[$adn] ?? null;
            $new = $after[$adn] ?? null;

            $kinds = match (true) {
                $old === null => [self::KIND_NEW],
                $new === null => [self::KIND_MISSING],
                default => $this->differences($old, $new),
            };

            if ($kinds === []) {
                $unchanged++;

                continue;
            }

            foreach ($kinds as $kind) {
                $counts[$kind]++;
            }

            $changes[] = new BankFileRowChange((string) $adn, $kinds, $old, $new);
        }

        // What changed for an ADN in both files first, then new ADNs, then
        // missing ones last: a follow-up file usually covers only the lines
        // sent again, so "missing" is expected and would otherwise bury the
        // few rows that actually moved.
        $rank = static fn (BankFileRowChange $change): int => match (true) {
            in_array(self::KIND_MISSING, $change->kinds, true) => 2,
            in_array(self::KIND_NEW, $change->kinds, true) => 1,
            default => 0,
        };
        usort($changes, static fn (BankFileRowChange $a, BankFileRowChange $b): int => [$rank($a), $a->adn] <=> [$rank($b), $b->adn]);

        $duplicates = array_values(array_unique(array_merge($beforeDuplicates, $afterDuplicates)));
        sort($duplicates, SORT_NATURAL);

        return new BankFileComparison($from, $to, $changes, $counts, $unchanged, $duplicates);
    }

    /**
     * Every import of the batch side by side. Unless `$all`, only the ADNs
     * that two imports report differently are kept — an ADN missing from a
     * later file is not by itself a difference.
     */
    public function history(PayoutBatch $batch, bool $all = false): BankFileImportHistory
    {
        /** @var list<PayoutBankFile> $imports */
        $imports = $this->imports($batch)->all();

        $byFile = [];
        $adns = [];
        foreach ($imports as $import) {
            [$rows] = $this->rowsByAdn($import);
            $byFile[(int) $import->id] = $rows;
            $adns = array_merge($adns, array_keys($rows));
        }

        $adns = array_values(array_unique(array_map('strval', $adns)));
        sort($adns, SORT_NATURAL);

        $grid = [];
        foreach ($adns as $adn) {
            $cells = [];
            $signatures = [];
            foreach ($imports as $import) {
                $row = $byFile[(int) $import->id][$adn] ?? null;
                $cells[(int) $import->id] = $row;
                // Absence is not a change: a follow-up file usually carries
                // only the lines sent again. An ADN differs when two files
                // that do name it disagree about it.
                if ($row !== null) {
                    $signatures[] = $this->signature($row);
                }
            }

            if ($all || count(array_unique($signatures)) > 1) {
                $grid[$adn] = $cells;
            }
        }

        return new BankFileImportHistory($imports, $grid, count($adns));
    }

    /**
     * The batch's import files, oldest first.
     *
     * @return Collection<int, PayoutBankFile>
     */
    public function imports(PayoutBatch $batch): Collection
    {
        return PayoutBankFile::query()
            ->where('payout_batch_id', $batch->id)
            ->where('direction', PayoutBankFile::DIRECTION_IMPORT)
            ->with('actor')
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * "#n" for each file of a batch, counted separately for exports and
     * imports in the order they happened: file id => ordinal.
     *
     * @return array<int, int>
     */
    public function ordinals(PayoutBatch $batch): array
    {
        $ordinals = [];
        $counters = [];

        PayoutBankFile::query()
            ->where('payout_batch_id', $batch->id)
            ->orderBy('id')
            ->get(['id', 'direction'])
            ->each(function (PayoutBankFile $file) use (&$ordinals, &$counters): void {
                $counters[$file->direction] = ($counters[$file->direction] ?? 0) + 1;
                $ordinals[(int) $file->id] = $counters[$file->direction];
            });

        return $ordinals;
    }

    /**
     * The file's rows keyed by ADN — the first row wins for an ADN listed
     * twice — and the ADNs that were listed more than once.
     *
     * @return array{0: array<string, PayoutBankFileRow>, 1: list<string>}
     */
    private function rowsByAdn(PayoutBankFile $file): array
    {
        $rows = [];
        $duplicates = [];

        PayoutBankFileRow::query()
            ->where('payout_bank_file_id', $file->id)
            ->orderBy('row_no')
            ->get()
            ->each(function (PayoutBankFileRow $row) use (&$rows, &$duplicates): void {
                $adn = strtoupper(trim($row->adn));

                if (isset($rows[$adn])) {
                    $duplicates[] = $adn;

                    return;
                }

                $rows[$adn] = $row;
            });

        return [$rows, array_values(array_unique($duplicates))];
    }

    /** @return list<string> */
    private function differences(PayoutBankFileRow $old, PayoutBankFileRow $new): array
    {
        $kinds = [];

        if ($old->verdict !== $new->verdict) {
            $kinds[] = self::KIND_STATUS;
        }
        if ($this->normalised($old->utr) !== $this->normalised($new->utr)) {
            $kinds[] = self::KIND_UTR;
        }
        if ($old->amount_paise !== $new->amount_paise) {
            $kinds[] = self::KIND_AMOUNT;
        }
        if (trim((string) $old->reason) !== trim((string) $new->reason)) {
            $kinds[] = self::KIND_REASON;
        }

        return $kinds;
    }

    private function signature(PayoutBankFileRow $row): string
    {
        return implode('|', [
            (string) $row->verdict,
            $this->normalised($row->utr),
            (string) $row->amount_paise,
            trim((string) $row->reason),
        ]);
    }

    private function normalised(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }
}
