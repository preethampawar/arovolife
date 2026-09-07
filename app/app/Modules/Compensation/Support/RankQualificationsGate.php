<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;

/**
 * Precondition for every engine that READS `rank_qualifications`.
 *
 * `rank:check-qualifications` is the table's only writer. Rank Bonus, Growth
 * Booster and Fortune enrolment all read it and none of them writes it, and the
 * SCHEDULER does not resolve dependency chains — only the admin trigger and the
 * recompute replay do. So if the 00:15 check fails, the engines behind it read
 * an empty table as a valid answer:
 *
 *   • Rank Bonus prices the pool from turnover, pays no RAP achiever, and still
 *     issues AO-GO grants against the whole Rank 1 pool, consuming a lifetime
 *     use that `alreadyGrantedThisMonth` will not re-issue.
 *   • Growth Booster's `rejectRankedLastMonth()` rejects NOBODY, so every
 *     distributor the plan excludes is credited and the inflated denominator
 *     dilutes the point value for the genuinely eligible.
 *   • Fortune's `buildIneligibleRankIds()` returns [], so rank 6–9 seniors are
 *     enrolled into the capacity-capped 29,524-position FCFS matrix and
 *     permanently displace eligible distributors for that month.
 *
 * All three freeze their economics, and none of it raises an error. Hence a
 * precondition rather than a test: the failure mode is an empty table read as a
 * valid answer, which passes every type check and every test that seeds
 * qualifications.
 *
 * The MONTH DIFFERS PER ENGINE, which is exactly why this lives in one place:
 * Rank Bonus and Fortune enrolment for month M read month M, while Growth
 * Booster for month M reads M-1 (`rejectRankedLastMonth`).
 */
final class RankQualificationsGate
{
    /**
     * Whether `rank:check-qualifications` completed for the given month.
     *
     * A flag-off no-op records STATUS_SKIPPED and a crash records STATUS_FAILED
     * (RecordEngineRun), so only a real completed check opens the gate. A month
     * whose ladder was genuinely empty still records a succeeded run, so this
     * refuses the missing prerequisite and never a legitimately quiet month.
     */
    public static function checkedFor(Carbon $month): bool
    {
        return EngineRun::query()
            ->where('engine_key', 'rank.check')
            ->whereDate('period_start', $month->copy()->startOfMonth()->toDateString())
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->exists();
    }

    /**
     * True when a month could not possibly have produced a rank qualification:
     * `group_bv_daily` has zero rows dated inside it AND `rank_qualifications`
     * has zero rows for it. With no Genos BV posted, nobody could have ranked,
     * so the exclusion set the check would have produced is provably empty —
     * a missing `rank:check-qualifications` run carries no risk.
     *
     * NARROW USE ONLY: this exists for Growth Booster's replay of the first
     * BV month in the platform's history (June 2026 precedes any BV and the
     * scheduler never runs a check for a month before BV existed, so a full
     * recompute replay can never produce one). It must never be used to waive
     * `checkedFor()` for a month that already has BV or a qualification row —
     * that is exactly the empty-table-read-as-valid-answer failure this class
     * exists to prevent. Callers other than the GBB prior-month check should
     * not use this.
     */
    public static function monthHadNoGenosBv(Carbon $month): bool
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return ! GroupBvDaily::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->exists()
            && ! RankQualification::query()
                ->where('month_start', $start->toDateString())
                ->exists();
    }

    /** Operator-facing refusal naming the month at fault and the way out. */
    public static function refusalMessage(Carbon $month, string $consequence): string
    {
        return sprintf(
            "Rank Qualification Check has not succeeded for %s — refusing to run.\n%s\nRun: php artisan rank:check-qualifications --month=%s",
            $month->format('F Y'),
            $consequence,
            $month->format('Y-m'),
        );
    }
}
