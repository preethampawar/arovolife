<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every month the pre-freeze Rank Bonus engine already PAID the pool row
 * the frozen engine now requires.
 *
 * `create_rank_monthly_pools_table` only created the table. A month credited
 * before it shipped therefore has credited `rank_bonus_results` and no pool row
 * — exactly the state `RankBonusService::refuseUnfrozenPaidMonth()` throws on,
 * because freezing such a month afresh would divide a new pool against today's
 * roster and pay a second time out of a pool the month has already spent. The
 * refusal is right; having no way out of it is not. Any later Rank Bonus run
 * for that month — including `compensation:monthly-close --restart`, which is
 * what an operator reaches for when a close went wrong — aborts at the Rank
 * step and takes every step after it down with it (staging, 10 Sep 2026: four
 * credited September rows, zero pool rows).
 *
 * Nothing is invented. The pre-freeze engine wrote the month's economics onto
 * EVERY result row — company turnover, the rank's pool, the qualifier count and
 * the points snapshot — so the pool row is reconstructed from the rows it paid:
 * pool and turnover as recorded, payout as the gross actually written, leftover
 * as the difference. Two inputs were never snapshotted anywhere, the envelope
 * and the rank's pool percentage, and they stay 0 rather than being guessed
 * from today's settings — today's values are not the month's, which is the
 * whole reason the freeze exists.
 *
 * Idempotent and forward-only: a month that already has a pool row is left
 * alone, and re-running inserts nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rank_monthly_pools') || ! Schema::hasTable('rank_bonus_results')) {
            return;
        }

        $now = Carbon::now();

        foreach ($this->paidMonthsWithoutPool() as $month) {
            $rows = DB::table('rank_bonus_results')
                ->select('rank_number')
                ->selectRaw('MAX(company_turnover_paise) AS company_turnover_paise')
                ->selectRaw('MAX(pool_paise) AS pool_paise')
                ->selectRaw('MAX(qualifier_count) AS qualifier_count')
                ->selectRaw('MAX(rap_points) AS rap_points')
                ->selectRaw('SUM(COALESCE(aogo_points, 0)) AS aogo_points')
                ->selectRaw('MAX(total_points) AS total_points')
                ->selectRaw('MAX(point_value_paise) AS point_value_paise')
                ->selectRaw('MAX(gross_paise) AS gross_per_qualifier_paise')
                ->selectRaw('SUM(gross_paise) AS payout_paise')
                ->whereDate('month_start', $month)
                ->groupBy('rank_number')
                ->get();

            foreach ($rows as $row) {
                $pool = (int) $row->pool_paise;
                $payout = (int) $row->payout_paise;

                DB::table('rank_monthly_pools')->insert([
                    'month_start' => $month,
                    'rank_number' => (int) $row->rank_number,
                    'company_turnover_paise' => (int) $row->company_turnover_paise,
                    // Never snapshotted by the pre-freeze engine. Zero says
                    // "unknown" honestly; a plausible number would not.
                    'envelope_bp' => 0,
                    'pool_pct' => 0,
                    'pool_paise' => $pool,
                    'rap_points' => $row->rap_points === null ? null : (int) $row->rap_points,
                    'payable_count' => (int) $row->qualifier_count,
                    'aogo_points' => (int) $row->aogo_points,
                    'total_points' => $row->total_points === null ? null : (int) $row->total_points,
                    'point_value_paise' => $row->point_value_paise === null ? null : (int) $row->point_value_paise,
                    'gross_per_qualifier_paise' => (int) $row->gross_per_qualifier_paise,
                    'payout_paise' => $payout,
                    // Never negative by construction on a frozen row; a legacy
                    // month that paid more than its recorded pool reads 0
                    // rather than inverting the invariant.
                    'leftover_paise' => max(0, $pool - $payout),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Forward-only: the rows this wrote are indistinguishable from a freeze
        // the engine made itself, and deleting them would put the month back in
        // the state no Rank Bonus run can touch.
    }

    /**
     * Months with at least one paid rank result and no pool row at all.
     *
     * "Paid" is credited or reversed — the same set `refuseUnfrozenPaidMonth()`
     * counts. A month whose rows are all still pending was never priced, so it
     * has nothing to reconstruct and must stay open to a real freeze.
     *
     * @return list<string> Y-m-d, the first of each month
     */
    private function paidMonthsWithoutPool(): array
    {
        $frozen = DB::table('rank_monthly_pools')
            ->distinct()
            ->pluck('month_start')
            ->map(static fn ($month): string => Carbon::parse((string) $month)->toDateString())
            ->all();

        return array_values(DB::table('rank_bonus_results')
            ->whereIn('status', ['credited', 'reversed'])
            ->distinct()
            ->pluck('month_start')
            ->map(static fn ($month): string => Carbon::parse((string) $month)->toDateString())
            ->reject(static fn (string $month): bool => in_array($month, $frozen, true))
            ->all());
    }
};
