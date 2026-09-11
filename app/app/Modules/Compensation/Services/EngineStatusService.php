<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Answers "has this engine already run for this period?".
 *
 * The primary proof is a succeeded `engine_runs` row. That table only starts
 * accumulating history from the day this feature ships, so every engine also
 * has a data-derived fallback — the result rows it would have written. The
 * fallback is what stops the dependency resolver from re-running months of
 * cut-offs on day one; once the run log has history it dominates, because it
 * can also tell "ran and produced nothing" from "never ran".
 */
final class EngineStatusService
{
    /**
     * @param  Carbon  $period  Normalised by the caller: the date, or the first of the month.
     */
    public function isPeriodComputed(string $key, Carbon $period): bool
    {
        if ($this->hasSucceededRun($key, $period)) {
            return true;
        }

        return $this->hasDerivedProof($key, $period);
    }

    /**
     * A succeeded run recorded in the run log that is proven to have seen the
     * WHOLE period — i.e. one that started after the period had ended.
     *
     * The date alone is not a sufficient key. Every engine here freezes its
     * period's economics the first time it runs and credits from that snapshot,
     * so a run stamped INSIDE its own period priced a partial period: September
     * frozen on the 5th is priced on five days of BV. Accepting such a row as
     * "this period is done" is worse than not having it — the monthly close
     * then resumes past the step and never reprices the month, and the payout
     * gate a week later reads the same row as a completed month and pays on it
     * (staging, 05 Sep 2026: all seven September steps recorded succeeded by a
     * mid-month recompute, 12.3 % short and silently permanent).
     *
     * Same rule as {@see hasSucceededRunAfterDay()}, one period type wider: the
     * boundary is the app-timezone midnight that opens the next day for a
     * date-typed engine and the next month for a month-typed one.
     */
    public function hasSucceededRun(string $key, Carbon $period): bool
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->whereDate('period_start', $period->toDateString())
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->where('started_at', '>=', self::periodEndsAt($key, $period)->toDateTimeString())
            ->exists();
    }

    /**
     * The first instant AFTER the period $key works on — the next day for a
     * date engine, the first of the next month for a month engine.
     *
     * An unregistered key is treated as date-typed: the only callers are
     * registry keys, and a day boundary is the narrower assumption.
     */
    public static function periodEndsAt(string $key, Carbon $period): Carbon
    {
        return EngineRegistry::has($key)
            && EngineRegistry::get($key)->periodType === EnginePeriodType::Month
                ? $period->copy()->startOfMonth()->addMonthNoOverflow()
                : $period->copy()->startOfDay()->addDay();
    }

    /**
     * A succeeded run for this engine whose period is $period or later.
     *
     * "Or later" is what makes this the right question for a dependency that
     * stamps state forward rather than per-period: `repurchase:evaluate --date=D`
     * resolves every cycle due up to D, so a run for D + 1 has already answered
     * everything day D can ask. Compared by DATE — the hour a run started is
     * irrelevant to which day it evaluated.
     */
    public function hasSucceededRunOnOrAfter(string $key, Carbon $period): bool
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->whereDate('period_start', '>=', $period->toDateString())
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->exists();
    }

    /**
     * A succeeded run for this engine that is proven to have SEEN all of $day.
     *
     * `hasSucceededRunOnOrAfter()` is not enough for the GSB cut-off. The
     * scheduled `repurchase:evaluate --date=D` runs at 00:05 ON D, so it cannot
     * have seen a fulfilment purchase made later that same day; accepting it
     * would let the cut-off for D forfeit a day the distributor actually
     * fulfilled — permanently, since the forfeit is never corrected. Accepted
     * proof is therefore either a run for a LATER date, or a run dated D that
     * started after D had ended (a re-run, a manual trigger, or the Engine Runs
     * chain filling the gap the next morning).
     *
     * `started_at` is stamped with `Carbon::now()` in the app timezone, so the
     * boundary is the app-timezone midnight opening D + 1.
     */
    public function hasSucceededRunAfterDay(string $key, Carbon $day): bool
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->where(self::sawWholeDay($day))
            ->exists();
    }

    /**
     * The most recent run of $key that could have seen all of $day, whatever
     * its status — the diagnostic companion to
     * {@see hasSucceededRunAfterDay()}.
     *
     * When that gate says no, the operator's next question is always "why" and
     * the answer is on the run itself: a `repurchase.evaluate` run that threw
     * on two distributors names them in its summary, and that is the difference
     * between a five-minute fix and a platform-wide cut-off stalled overnight.
     */
    public function latestRunAfterDay(string $key, Carbon $day): ?EngineRun
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->where(self::sawWholeDay($day))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A run for a LATER date, or a run dated $day that started after $day had
     * ended — the two shapes that prove the whole day was visible to it.
     */
    private static function sawWholeDay(Carbon $day): \Closure
    {
        $dayEnds = $day->copy()->startOfDay()->addDay();

        return function (Builder $query) use ($day, $dayEnds): void {
            $query->whereDate('period_start', '>', $day->toDateString())
                ->orWhere(function (Builder $sameDay) use ($day, $dayEnds): void {
                    $sameDay->whereDate('period_start', '>=', $day->toDateString())
                        ->where('started_at', '>=', $dayEnds->toDateTimeString());
                });
        };
    }

    /**
     * True when the engine has a live run in flight — either one this process
     * knows about or a cron run that started moments ago. `running` rows older
     * than the staleness cutoff are treated as abandoned, not live.
     */
    public function hasRunInFlight(string $key): bool
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->where('status', EngineRun::STATUS_RUNNING)
            ->where('started_at', '>=', Carbon::now()->subMinutes(EngineRun::STALE_AFTER_MINUTES))
            ->exists();
    }

    /**
     * Failed runs whose period has not since been rebuilt — a failed run with no
     * succeeded run for the same engine and period after it.
     *
     * Until this existed nothing in the platform read STATUS_FAILED at all: a
     * month in which an engine crashed looked exactly like a month in which one
     * did not, until somebody happened to open the Engine Runs page. It feeds
     * the admin sidebar badge, and it clears itself the moment the engine is
     * re-run successfully.
     */
    public function unresolvedFailureCount(int $withinDays = 30): int
    {
        return $this->unresolvedFailureQuery(Carbon::now()->subDays($withinDays))->count();
    }

    /**
     * The same failures the badge counts, as rows — newest first.
     *
     * The daily health digest needs the engine, the period and the recorded
     * error, not a number, and the two must never disagree about what counts as
     * unresolved: both read {@see unresolvedFailureQuery()}.
     *
     * @return Collection<int, EngineRun>
     */
    public function unresolvedFailures(int $withinDays = 30): Collection
    {
        return $this->unresolvedFailureQuery(Carbon::now()->subDays($withinDays))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Runs still marked `running` long after they started — the process died
     * without ever writing an outcome, so nothing else in the platform reports
     * them: they are neither a failure nor a success.
     *
     * @return Collection<int, EngineRun>
     */
    public function stuckRuns(int $olderThanHours = 3): Collection
    {
        return EngineRun::query()
            ->where('status', EngineRun::STATUS_RUNNING)
            ->where('started_at', '<', Carbon::now()->subHours($olderThanHours))
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Builder<EngineRun>
     */
    private function unresolvedFailureQuery(Carbon $since): Builder
    {
        return EngineRun::query()
            ->where('status', EngineRun::STATUS_FAILED)
            ->where('started_at', '>=', $since)
            ->whereNotExists(function ($query) use ($since): void {
                $query->select(DB::raw(1))
                    ->from('engine_runs as later')
                    ->whereColumn('later.engine_key', 'engine_runs.engine_key')
                    ->whereColumn('later.period_start', 'engine_runs.period_start')
                    ->whereColumn('later.started_at', '>=', 'engine_runs.started_at')
                    ->where('later.status', EngineRun::STATUS_SUCCEEDED)
                    ->where('later.started_at', '>=', $since);
            });
    }

    public function lastRun(string $key): ?EngineRun
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Latest run per engine in ONE query — the Engine Runs index lists all ten
     * and must not fan out into ten (or, with the actor, twenty) round trips.
     *
     * @param  list<string>  $keys
     * @return array<string, EngineRun>
     */
    public function lastRunPerEngine(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return EngineRun::query()
            ->with('actor:id,full_name,email')
            ->whereIn('engine_key', $keys)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->reduce(function (array $carry, EngineRun $run): array {
                $carry[$run->engine_key] ??= $run;

                return $carry;
            }, []);
    }

    /**
     * Bootstrap fallback for the index page: the newest period for which the
     * engine's own result tables prove it ran, when the run log has no history.
     */
    public function lastComputedPeriod(string $key): ?Carbon
    {
        $latest = match ($key) {
            'gsb.daily-cutoff' => GsbCutoffResult::query()->max('cutoff_date'),
            'gsb.weekly-payout' => PayoutBatch::query()
                ->where('batch_type', PayoutBatch::TYPE_WEEKLY)->max('batch_date'),
            'payout.monthly' => PayoutBatch::query()
                ->where('batch_type', PayoutBatch::TYPE_MONTHLY)->max('batch_date'),
            'gbb.monthly' => GbbMonthlyPool::query()->max('month_start'),
            'rank.check' => RankQualification::query()->max('month_start'),
            'rank.bonus' => RankBonusResult::query()->max('month_start'),
            'fortune.enroll', 'fortune.payout' => FortuneMonthlyPool::query()->max('month_start'),
            'adc.bonus' => AdcBonusResult::query()->max('month_start'),
            // repurchase:evaluate mutates cycle rows in place and leaves no
            // per-run trace, so there is nothing to derive — the run log is the
            // only evidence it ever ran.
            default => null,
        };

        return is_string($latest) && $latest !== ''
            ? EngineRegistry::get($key)->periodStart(Carbon::parse($latest))
            : null;
    }

    /**
     * Every day in the inclusive range for which a GSB cut-off is proven done.
     * One query per source, so a month-wide dependency check stays at two
     * queries regardless of how many days are missing.
     *
     * @return list<string> Y-m-d
     */
    public function computedCutoffDatesBetween(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $fromRunLog = EngineRun::query()
            ->where('engine_key', 'gsb.daily-cutoff')
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->whereBetween('period_start', [$fromDate, $to->copy()->endOfDay()->toDateTimeString()])
            ->pluck('period_start')
            ->map(fn (Carbon $date): string => $date->toDateString());

        $fromResults = GsbCutoffResult::query()
            ->whereBetween('cutoff_date', [$fromDate, $toDate])
            ->distinct()
            ->pluck('cutoff_date')
            ->map(fn (Carbon $date): string => $date->toDateString());

        return array_values($fromRunLog->merge($fromResults)->unique()->sort()->all());
    }

    /** The engine's own result rows for a period — the pre-run-log fallback. */
    private function hasDerivedProof(string $key, Carbon $period): bool
    {
        $date = $period->toDateString();

        return match ($key) {
            // Authoritative day-level source: AdminDailyCutoffController lists a
            // day by `cutoff_date` on gsb_cutoff_results, so a row for the day
            // is exactly what "this day was cut off" means everywhere else.
            'gsb.daily-cutoff' => GsbCutoffResult::query()
                ->whereDate('cutoff_date', $date)->exists(),

            // Any status counts: a pending batch still means the engine ran.
            'gsb.weekly-payout' => $this->batchExists(PayoutBatch::TYPE_WEEKLY, $period, monthWide: false),
            'payout.monthly' => $this->batchExists(PayoutBatch::TYPE_MONTHLY, $period, monthWide: true),

            'gbb.monthly' => GbbMonthlyPool::query()->whereDate('month_start', $date)->exists(),
            'rank.check' => RankQualification::query()->whereDate('month_start', $date)->exists(),
            'rank.bonus' => RankBonusResult::query()->whereDate('month_start', $date)->exists(),
            'adc.bonus' => AdcBonusResult::query()->whereDate('month_start', $date)->exists(),

            // A frozen Fortune pool means enrolment is closed for the month —
            // FortuneBonusService::enrollEligible() refuses with
            // `refused_pool_frozen` — so "pool exists" reads as "enrolment done",
            // never as a failure to chase.
            'fortune.enroll', 'fortune.payout' => FortuneMonthlyPool::query()
                ->whereDate('month_start', $date)->exists(),

            default => false,
        };
    }

    private function batchExists(string $type, Carbon $period, bool $monthWide): bool
    {
        return PayoutBatch::query()
            ->where('batch_type', $type)
            ->when(
                $monthWide,
                fn (Builder $query): Builder => $query->whereBetween('batch_date', [
                    $period->copy()->startOfMonth()->toDateString(),
                    $period->copy()->endOfMonth()->toDateString(),
                ]),
                fn (Builder $query): Builder => $query->whereDate('batch_date', $period->toDateString()),
            )
            ->exists();
    }
}
