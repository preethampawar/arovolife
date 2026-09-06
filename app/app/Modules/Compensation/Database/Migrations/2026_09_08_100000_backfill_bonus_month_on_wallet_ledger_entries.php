<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\WalletService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stamp `bonus_month` — the first day of the IST month the income was EARNED
 * for — onto the ledger rows written before that column existed.
 *
 * Both monthly ceilings (the ₹10,000 repurchase deduction and the ₹50,00,000
 * combined income cap) fall back to `created_at` when `bonus_month` is null.
 * The monthly engines all run in the small hours of the 1st for the month that
 * just closed, so under that fallback August's Rank, Growth Booster and Fortune
 * credits — written on 1 September — compete with September's own credits for a
 * single September ceiling. Left alone, the fallback misbills for one more month
 * on every distributor who earned in August.
 *
 * The earned month is read from the result row each ledger entry already points
 * at, so nothing here is inferred: `reference_type` names the result table and
 * `reference_id` the row, and every entry hanging off that row — the gross
 * credit, its `repurchase_transfer` debit, the matching `repurchase_deduction`
 * credit and any reversal of them — belongs to that row's month by definition.
 *
 * A row whose reference cannot be resolved (no such result row, a reference
 * type this map does not cover, or a result row with no date) is LEFT NULL: the
 * created_at fallback still answers for it, and a guessed month on money is
 * worse than an honest absence.
 *
 * Forward-only and idempotent — it only ever touches rows that are still null.
 */
return new class extends Migration
{
    /**
     * reference_type prefix => [result table, the column holding its earned month].
     *
     * The prefix also matches the `_reversal` suffix
     * ({@see WalletService::REVERSAL_REFERENCE_SUFFIX}),
     * which hangs off the same result row and so shares its month.
     *
     * @var array<string, array{string, string}>
     */
    private const SOURCES = [
        'gsb_cutoff_result' => ['gsb_cutoff_results', 'cutoff_date'],
        'rank_bonus_result' => ['rank_bonus_results', 'month_start'],
        'gbb_monthly_result' => ['gbb_monthly_results', 'year_month'],
        'fortune_bonus_result' => ['fortune_bonus_results', 'month_start'],
    ];

    public function up(): void
    {
        foreach (self::SOURCES as $referenceType => [$table, $dateColumn]) {
            $referenceTypes = [$referenceType, $referenceType.'_reversal'];

            // Which result rows the still-null ledger entries actually point at,
            // so a table with far more result rows than credited ones is not
            // walked in full.
            $referenceIds = DB::table('wallet_ledger_entries')
                ->whereNull('bonus_month')
                ->whereIn('reference_type', $referenceTypes)
                ->whereNotNull('reference_id')
                ->distinct()
                ->pluck('reference_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($referenceIds === []) {
                continue;
            }

            /** @var array<string, list<int>> $idsByMonth */
            $idsByMonth = [];

            foreach (array_chunk($referenceIds, 1000) as $chunk) {
                $rows = DB::table($table)
                    ->whereIn('id', $chunk)
                    ->whereNotNull($dateColumn)
                    ->get(['id', $dateColumn]);

                foreach ($rows as $row) {
                    $month = Carbon::parse((string) $row->{$dateColumn})->startOfMonth()->toDateString();
                    $idsByMonth[$month][] = (int) $row->id;
                }
            }

            foreach ($idsByMonth as $month => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    DB::table('wallet_ledger_entries')
                        ->whereNull('bonus_month')
                        ->whereIn('reference_type', $referenceTypes)
                        ->whereIn('reference_id', $chunk)
                        ->update(['bonus_month' => $month]);
                }
            }
        }
    }

    public function down(): void
    {
        // Forward-only. The earned month is a fact recovered from the result
        // rows, not a schema change; blanking it again would only restore the
        // misbilling this fixed.
    }
};
