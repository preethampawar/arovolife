<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

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
 *   • Growth Booster's `rejectEverRanked()` misses that month's rankers
 *     (earlier months' rankers are still rejected), so distributors the plan
 *     excludes are credited and the inflated denominator dilutes the point
 *     value for the genuinely eligible.
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
 * Booster for month M reads every month before M (`rejectEverRanked`), M-1
 * being the latest — earlier months were checked before their own closes.
 */
final class RankQualificationsGate
{
    /**
     * Whether `rank:check-qualifications` completed for the given month.
     *
     * A crash records STATUS_FAILED (RecordEngineRun), so a check that did not
     * complete never opens the gate. A month whose ladder was genuinely empty
     * still records a succeeded run, so this refuses the missing prerequisite
     * and never a legitimately quiet month.
     *
     * A flag-off no-op records STATUS_SKIPPED with reason `feature_flag_off`,
     * and that ONE skip opens the gate — only while the flag is still off:
     * with the Rank Bonus engine off nobody can hold a rank, so the exclusion
     * set the dependants read is legitimately empty — exactly like a quiet
     * month. Refusing it would deadlock every monthly close (rank.bonus, gbb,
     * fortune.enroll all wait on this) for as long as the flag stays off.
     * Every other SKIPPED reason (`already_running`, `upstream_failed`,
     * `stale_worker`…) means the check did NOT happen and keeps the gate shut:
     * an empty exclusion set there would credit GBB to distributors the plan
     * bars and enrol barred seniors into the capacity-capped Fortune matrix.
     */
    public static function checkedFor(Carbon $month): bool
    {
        $runs = EngineRun::query()
            ->where('engine_key', 'rank.check')
            ->whereDate('period_start', $month->copy()->startOfMonth()->toDateString());

        if ($runs->clone()->where('status', EngineRun::STATUS_SUCCEEDED)->exists()) {
            return true;
        }

        return ! Feature::for(null)->active(RankBonusFeature::class)
            && $runs->clone()
                ->where('status', EngineRun::STATUS_SKIPPED)
                ->where('summary->reason', 'feature_flag_off')
                ->exists();
    }

    /**
     * Whether the month's `rank:check-qualifications` was a flag-off no-op
     * (STATUS_SKIPPED, reason `feature_flag_off`), whatever the flag is now.
     *
     * FOR MONTHS BEFORE M-1 ONLY, in Growth Booster's lifetime walk. With the
     * Rank Bonus engine off that month no qualification row was written, so
     * there is nobody that month for the lifetime exclusion to miss. Without
     * this, turning the flag on would make every flag-off month a permanent
     * refusal — and running the check retroactively would lifetime-exclude
     * people for months the engine was off. M-1 keeps {@see checkedFor()}'s
     * strict reading.
     */
    public static function skippedForFeatureFlagOff(Carbon $month): bool
    {
        return EngineRun::query()
            ->where('engine_key', 'rank.check')
            ->whereDate('period_start', $month->copy()->startOfMonth()->toDateString())
            ->where('status', EngineRun::STATUS_SKIPPED)
            ->where('summary->reason', 'feature_flag_off')
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

    /**
     * Every month Growth Booster's lifetime exclusion reads that still lacks a
     * check, oldest first: each month from the first `group_bv_daily` or
     * `rank_qualifications` row up to and including $lastMonth (M-1) that has
     * no succeeded check, except the two that provably cannot hide a ranker —
     *
     *   • a month with no Genos BV and no qualification row
     *     ({@see monthHadNoGenosBv()}), M-1 included;
     *   • a flag-off skipped check ({@see skippedForFeatureFlagOff()}), for
     *     months strictly before M-1 only — M-1 keeps {@see checkedFor()}'s
     *     strict reading.
     *
     * One walk for the two places that need it: the GBB command refuses on the
     * first month listed, and the Engine Runs dependency resolver fills every
     * month listed before it runs the GBB.
     *
     * @return list<Carbon>
     */
    public static function monthsMissingCheck(Carbon $lastMonth): array
    {
        $last = $lastMonth->copy()->startOfMonth();
        $first = $last->copy();

        foreach ([GroupBvDaily::query()->min('date'), RankQualification::query()->min('month_start')] as $earliest) {
            if ($earliest !== null) {
                $candidate = Carbon::parse((string) $earliest)->startOfMonth();
                $first = $candidate->lt($first) ? $candidate : $first;
            }
        }

        $missing = [];

        for ($month = $first; $month->lte($last); $month = $month->copy()->addMonthNoOverflow()) {
            if (self::checkedFor($month)) {
                continue;
            }

            // A waived month runs nothing, so it leaves this trace instead.
            $waivedFor = match (true) {
                $month->lt($last) && self::skippedForFeatureFlagOff($month) => 'rank_engine_off',
                self::monthHadNoGenosBv($month) => 'no_genos_bv',
                default => null,
            };

            if ($waivedFor !== null) {
                Log::info('rank.check.prerequisite_waived', ['month' => $month->format('Y-m'), 'reason' => $waivedFor]);

                continue;
            }

            $missing[] = $month->copy();
        }

        return $missing;
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
