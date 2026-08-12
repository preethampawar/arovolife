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
use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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

    /** A succeeded run recorded in the run log itself. */
    public function hasSucceededRun(string $key, Carbon $period): bool
    {
        return EngineRun::query()
            ->where('engine_key', $key)
            ->whereDate('period_start', $period->toDateString())
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->exists();
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
