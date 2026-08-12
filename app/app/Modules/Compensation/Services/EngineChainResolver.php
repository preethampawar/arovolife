<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Services\DTOs\EngineChainPlan;
use App\Modules\Compensation\Services\DTOs\EngineChainStep;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * Expands "run engine X for period P" into the ordered list of engines that
 * must actually run.
 *
 * Semantics confirmed with KP: a manual run pulls in the engines it *depends
 * on* (prerequisites first, skipped when already computed) and never the
 * engines that depend on it — running the Growth Booster must not fire the
 * monthly payout. The requested engine itself always runs: every engine is an
 * idempotent re-runner, so a redundant run is safe while a skipped one is not.
 */
final class EngineChainResolver
{
    /**
     * A month-wide backfill of daily cut-offs is already a long job; this caps
     * a single chain so one click can never queue an unbounded amount of work.
     */
    private const MAX_STEPS = 80;

    public function __construct(private readonly EngineStatusService $status) {}

    public function resolve(string $engineKey, Carbon $period): EngineChainPlan
    {
        $engine = EngineRegistry::get($engineKey);

        /** @var list<EngineChainStep> $steps */
        $steps = [];
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, true> $warnings */
        $warnings = [];

        $this->visit($engine, $engine->periodStart($period), EngineChainStep::REASON_TARGET, $steps, $seen, $warnings);

        if (count($steps) > self::MAX_STEPS) {
            // Keep the earliest prerequisites and the requested engine: dropping
            // from the middle preserves execution order, and the admin is told
            // to run the rest separately.
            $target = array_pop($steps);
            $steps = array_slice($steps, 0, self::MAX_STEPS - 1);
            $steps[] = $target;

            $warnings[sprintf(
                'This chain was capped at %d steps. Run the remaining daily cut-offs separately before relying on the result.',
                self::MAX_STEPS,
            )] = true;
        }

        return new EngineChainPlan(array_values($steps), array_keys($warnings));
    }

    /**
     * Depth-first, post-order: a step is appended only after everything it
     * needs has been appended.
     *
     * @param  list<EngineChainStep>  $steps
     * @param  array<string, true>  $seen
     * @param  array<string, true>  $warnings
     */
    private function visit(
        EngineDefinition $engine,
        Carbon $period,
        string $reason,
        array &$steps,
        array &$seen,
        array &$warnings,
    ): void {
        $id = $engine->key.'|'.$engine->formatPeriod($period);

        if (isset($seen[$id])) {
            return;
        }

        // Marked before recursing so a registry mistake degrades into a skipped
        // duplicate rather than infinite recursion. EngineRegistryTest proves
        // the declared graph is acyclic; this is the belt to that braces.
        $seen[$id] = true;

        foreach ($engine->dependencies as $dependency) {
            $prerequisite = EngineRegistry::get($dependency['key']);

            // A flag-off prerequisite cannot produce anything: its command
            // no-ops. Running it would only add skipped rows (and abort the
            // chain), so it is left out and the omission surfaced to the admin.
            if ($this->featureFlagIsOff($prerequisite)) {
                $warnings[sprintf(
                    '%s was left out: its feature flag is off, so it cannot compute anything.',
                    $prerequisite->label,
                )] = true;

                continue;
            }

            foreach ($this->dependencyPeriods($engine, $prerequisite, $dependency, $period, $warnings) as $prerequisitePeriod) {
                if ($this->status->isPeriodComputed($prerequisite->key, $prerequisitePeriod)) {
                    continue;
                }

                $this->visit(
                    $prerequisite,
                    $prerequisitePeriod,
                    EngineChainStep::REASON_DEPENDENCY,
                    $steps,
                    $seen,
                    $warnings,
                );
            }
        }

        $steps[] = new EngineChainStep($engine, $period, $reason);
    }

    /**
     * Which period(s) of the prerequisite this dependency edge points at.
     *
     * @param  array{key: string, shift?: string, expand?: string}  $dependency
     * @param  array<string, true>  $warnings
     * @return list<Carbon>
     */
    private function dependencyPeriods(
        EngineDefinition $engine,
        EngineDefinition $prerequisite,
        array $dependency,
        Carbon $period,
        array &$warnings,
    ): array {
        $expand = $dependency['expand'] ?? null;

        if ($expand === 'month') {
            return $this->missingCutoffDays(
                $period->copy()->startOfMonth(),
                $period->copy()->endOfMonth(),
                $engine,
                $warnings,
            );
        }

        if ($expand === 'week') {
            // PayoutService::runWeeklyBatch() sweeps every unpaid weekly-bonus
            // wallet entry rather than a dated window, so there is no formal
            // "payout week" to derive. The seven days ending on the batch date
            // are the days a Tuesday-to-Tuesday batch would newly cover — enough
            // to catch a missed cut-off without backfilling all of history.
            return $this->missingCutoffDays(
                $period->copy()->subDays(6),
                $period->copy(),
                $engine,
                $warnings,
            );
        }

        if (($dependency['shift'] ?? null) === 'prev-month') {
            return [$prerequisite->periodStart($period->copy()->startOfMonth()->subMonthNoOverflow())];
        }

        if ($prerequisite->key === 'repurchase.evaluate') {
            return $this->repurchasePeriods($period, $warnings);
        }

        return [$prerequisite->periodStart($period)];
    }

    private function featureFlagIsOff(EngineDefinition $engine): bool
    {
        return $engine->featureFlagClass !== null
            && ! Feature::for(null)->active($engine->featureFlagClass);
    }

    /**
     * RepurchaseCycleService::evaluate() takes an as-of date but *mutates the
     * cycle rows in place* and emits the transition events as it goes — running
     * it for an older date would push a cycle back to active/grace and re-emit
     * a reactivation. It is therefore only safe on today or yesterday; for a
     * historical backfill it is left out and the omission surfaced to the admin.
     *
     * @param  array<string, true>  $warnings
     * @return list<Carbon>
     */
    private function repurchasePeriods(Carbon $period, array &$warnings): array
    {
        if ($period->isToday() || $period->isYesterday()) {
            return [$period->copy()->startOfDay()];
        }

        $warnings['Repurchase Evaluation was left out for past dates: it refreshes each cycle as at today, so re-running it for an old date would overwrite current repurchase status.'] = true;

        return [];
    }

    /**
     * Days in the range with no proven GSB cut-off, capped at yesterday: a
     * cut-off for today would freeze a partial day (credited results are never
     * recomputed), and future days have no BV at all.
     *
     * @param  array<string, true>  $warnings
     * @return list<Carbon>
     */
    private function missingCutoffDays(Carbon $from, Carbon $to, EngineDefinition $engine, array &$warnings): array
    {
        $lastRunnable = Carbon::yesterday();
        $end = $to->copy()->min($lastRunnable);
        $start = $from->copy()->startOfDay();

        if ($end->lt($start)) {
            $warnings[sprintf(
                '%s covers a period that has not finished yet — daily cut-offs only run for completed days, so nothing was backfilled.',
                $engine->label,
            )] = true;

            return [];
        }

        if ($to->gt($lastRunnable)) {
            $warnings[sprintf(
                'Daily cut-offs were backfilled only up to %s; the remaining days of this period have not completed yet.',
                $end->format('d M Y'),
            )] = true;
        }

        $computed = array_flip($this->status->computedCutoffDatesBetween($start, $end));

        $missing = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if (! isset($computed[$day->toDateString()])) {
                $missing[] = $day->copy();
            }
        }

        return $missing;
    }
}
