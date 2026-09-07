<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stamp `earned_on` — the DAY the income was earned — onto the Group A ledger
 * rows written before that column existed.
 *
 * The weekly batch pays a Wednesday→Tuesday earning week one Tuesday later and
 * lets a null `earned_on` through, so a row left null is swept by the first
 * batch that sees it. That is the right fallback for a legacy row, but it is
 * the wrong answer for the ones still sitting unswept when this ships: without
 * the backfill a cut-off from last Tuesday and one from this morning are
 * indistinguishable, and the first batch after deployment pays both.
 *
 * The day is read from the result row each ledger entry already points at —
 * `reference_type` names the table, `reference_id` the row — so nothing here is
 * inferred. Every entry hanging off one result row shares that row's day: the
 * gross credit, its `repurchase_transfer` debit, and the `repurchase_deduction`
 * credit that moved the withheld share into the repurchase wallet.
 *
 * A row whose reference cannot be resolved is LEFT NULL. The null-passthrough in
 * the batch still answers for it, and a guessed earning day on money that is
 * about to be wired is worse than an honest absence.
 *
 * Forward-only and idempotent — it only ever touches rows that are still null.
 */
return new class extends Migration
{
    /**
     * reference_type => [result table, the column holding the day it was earned].
     *
     * Only the two Group A streams: they are the only ones the weekly earning
     * week applies to. The monthly streams are earned for a month, not a day,
     * and keep `earned_on` null by design.
     *
     * @var array<string, array{string, string}>
     */
    private const SOURCES = [
        'gsb_cutoff_result' => ['gsb_cutoff_results', 'cutoff_date'],
        'mentorship_bonus_result' => ['mentorship_bonus_results', 'cutoff_date'],
    ];

    /**
     * The ledger types that hang off a Group A result row and share its day.
     *
     * `manual_credit` is deliberately absent even though an admin correction
     * references a `gsb_cutoff_result`: it is not a Group A type, no payout
     * batch sweeps it, and it was not earned on the cut-off day.
     *
     * @var list<string>
     */
    private const TYPES = ['gsb_credit', 'mb_credit', 'repurchase_transfer', 'repurchase_deduction'];

    public function up(): void
    {
        foreach (self::SOURCES as $referenceType => [$table, $dateColumn]) {
            // Which result rows the still-null ledger entries actually point at,
            // so a table with far more result rows than credited ones is not
            // walked in full.
            $referenceIds = DB::table('wallet_ledger_entries')
                ->whereNull('earned_on')
                ->where('reference_type', $referenceType)
                ->whereIn('type', self::TYPES)
                ->whereNotNull('reference_id')
                ->distinct()
                ->pluck('reference_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($referenceIds === []) {
                continue;
            }

            /** @var array<string, list<int>> $idsByDay */
            $idsByDay = [];

            foreach (array_chunk($referenceIds, 1000) as $chunk) {
                $rows = DB::table($table)
                    ->whereIn('id', $chunk)
                    ->whereNotNull($dateColumn)
                    ->get(['id', $dateColumn]);

                foreach ($rows as $row) {
                    $day = Carbon::parse((string) $row->{$dateColumn})->toDateString();
                    $idsByDay[$day][] = (int) $row->id;
                }
            }

            foreach ($idsByDay as $day => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    DB::table('wallet_ledger_entries')
                        ->whereNull('earned_on')
                        ->where('reference_type', $referenceType)
                        ->whereIn('type', self::TYPES)
                        ->whereIn('reference_id', $chunk)
                        ->update(['earned_on' => $day]);
                }
            }
        }
    }

    public function down(): void
    {
        // Forward-only. The earning day is a fact recovered from the result rows,
        // not a schema change; blanking it again would only hand the next batch
        // income it is not yet due to pay.
    }
};
