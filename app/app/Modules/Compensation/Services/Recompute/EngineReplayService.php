<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Support\EngineCadence;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Replays the scheduler day by day over a historical window.
 *
 * The loop does not know the schedule: it asks each engine's
 * {@see EngineCadence} whether it would have
 * fired on the day in question, and asks {@see EngineDefinition::periodRelativeTo()}
 * which period it would have worked on. Both live in the registry, which
 * EngineRegistryTest pins against routes/console.php — so the replay follows
 * the real schedule without restating it.
 *
 * Ordering is load-bearing and not negotiable:
 *   • Days ascend. gsb_carryforward is a single rolling row per distributor with
 *     no per-day history and slab-1 CF accumulates for life, so one day out of
 *     order corrupts every day after it. GsbCutoffService throws if it sees a
 *     later date already processed — a loud failure, by design.
 *   • Within a day, engines run in scheduled-time order, which is how the real
 *     cut-off/payout/bonus sequence was designed.
 *   • Unscheduled prerequisites (rank.check) run before whichever engine
 *     declares them, for the period that engine's dependency declares.
 *
 * The clock is travelled to each engine's scheduled instant so the rows carry
 * the timestamps the real run would have written. That is not cosmetic:
 * PayoutService windows the monthly income cap and the repurchase deduction on
 * wallet_ledger_entries.created_at, so replaying six weeks under one wall-clock
 * date would collapse them into a single capped month.
 *
 * Catch-up: when the window reaches today or later, a second pass runs after
 * the day loop and fires every scheduled engine for the periods the loop could
 * not cover: (i) the period still in flight at the horizon, and (ii) closed
 * months whose scheduled run day lies beyond the horizon. Future days are
 * simulated at their scheduled instants; the real scheduler finds those periods
 * already computed and skips them. The scheduler itself is untouched.
 */
final class EngineReplayService
{
    /** @var array<string, int> command signature => times invoked this replay */
    private array $engineRuns = [];

    /**
     * "engine.key|period" pairs already invoked this replay. Stops an engine
     * being run twice for one period — as a prerequisite of two different
     * engines, or by the catch-up pass for a period the day loop covered.
     *
     * @var array<string, true>
     */
    private array $invoked = [];

    /**
     * Engine keys this replay is limited to, or null for every engine. A
     * partial replay is a deliberate testing shortcut ("just show me GSB"), so
     * the runner reports what was left out rather than letting the gap pass as
     * a complete rebuild.
     *
     * @var list<string>|null
     */
    private ?array $onlyKeys = null;

    public function __construct(private readonly RecomputeProgress $progress) {}

    /**
     * @param  Closure(string): void|null  $progress
     * @param  list<string>|null  $onlyKeys  replay only these engine keys (their
     *                                       unscheduled prerequisites still run);
     *                                       null replays every engine
     * @return array{days: int, engines: array<string, int>, skipped: list<string>}
     */
    public function replay(Carbon $from, Carbon $to, ?Closure $progress = null, ?array $onlyKeys = null): array
    {
        $log = $progress ?? static fn (string $_m): null => null;

        $this->engineRuns = [];
        $this->invoked = [];
        $this->onlyKeys = $onlyKeys === null || $onlyKeys === [] ? null : array_values($onlyKeys);
        $days = 0;

        $skipped = $this->skippedKeys();

        if ($skipped !== []) {
            $log('  Skipping (not selected): '.implode(', ', $skipped));
        }

        // Read before the first travel. The last day's engines may be due at an
        // hour that has not arrived yet — 00:10 when it is 00:04. A future day
        // is simulated deliberately and keeps its scheduled instant.
        $realNow = Carbon::now();
        $horizon = $to->copy()->startOfDay();

        // The catch-up pass runs whenever the window reaches today or later. It
        // covers (i) the period in flight at the horizon and (ii) closed months
        // whose scheduled run day lies beyond the horizon. A window ending before
        // today has no partial periods to catch up and turns the pass off.
        $catchUpWillRun = $horizon->gte($realNow->copy()->startOfDay());

        $this->progress->daysTotal((int) $from->diffInDays($to) + 1);

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $due = $this->enginesDueOn($day);

            if ($due !== []) {
                $log(sprintf(
                    '  %s  %s',
                    $day->format('D d M Y'),
                    implode(', ', array_map(
                        static fn (EngineDefinition $d): string => $d->commandSignature,
                        $due,
                    )),
                ));
            }

            foreach ($due as $definition) {
                $period = $definition->periodRelativeTo($day);

                if ($catchUpWillRun && $this->isInFlight($definition, $period, $horizon)) {
                    continue;
                }

                $at = $definition->cadence->atOn($day);
                // Today's engines may be due at an hour that has not arrived
                // (00:10 when it is 00:04): clamp those to the wall clock. A
                // future day is simulated deliberately and keeps its scheduled
                // instant, so its rows say when the scheduler would have written
                // them.
                if ($at->gt($realNow) && $day->lte($realNow)) {
                    $at = $realNow->copy();
                }

                foreach ($this->unscheduledPrerequisites($definition, $period) as $key => $prerequisite) {
                    if (isset($this->invoked[$key])) {
                        continue;
                    }

                    $this->invoke($prerequisite['definition'], $prerequisite['period'], $at);
                }

                $this->invoke($definition, $period, $at);
            }

            $days++;

            $this->progress->dayReplayed(
                $day->toDateString(),
                array_map(static fn (EngineDefinition $d): string => $d->commandSignature, $due),
                $days,
                array_sum($this->engineRuns),
            );
        }

        if ($catchUpWillRun) {
            $this->catchUpPendingPeriods($horizon, $realNow, $log);
        }

        Carbon::setTestNow();

        return ['days' => $days, 'engines' => $this->engineRuns, 'skipped' => $skipped];
    }

    /**
     * Compute every period the scheduler has not yet reached, as at the horizon.
     *
     * Two categories:
     *
     * (i)  **In-flight period at the horizon** — today for date engines, the
     *      horizon's month for month engines. The day loop deliberately stepped
     *      around these so the catch-up owns them outright and stamps them at the
     *      correct clock instant.
     *
     * (ii) **Arrears period** — month-type engines only. The period whose data
     *      is complete (its month has ended) but whose scheduled run day lies
     *      after the horizon. Example: horizon is 2026-09-01 00:20 (after the
     *      snapshot at 00:06 but before Rank Bonus at 00:30). Rank Bonus would
     *      next fire at 00:30 on 2026-10-01 and would work on September — but
     *      September is the in-flight month and therefore already covered by
     *      (i). A better example: horizon is 2026-09-05. Rank Bonus next fires
     *      2026-10-01, period = September. September's month has not ended, so
     *      `periodStart(p).lte(horizon)` is true but September is the in-flight
     *      month — this dedupes naturally because (i) already adds it.
     *      The real arrears case: horizon 2026-09-01 00:20, next Rank Bonus
     *      firing is 00:30 the same day, period = August. August ended; the
     *      loop could not reach 00:30; this pass fills the gap.
     *
     * The catch-up stamp is the real clock when the window ends today (existing
     * behaviour, tested); a future horizon stamps its catch-up rows at 23:59 of
     * the last simulated day so they sort after every loop-stamped row —
     * PayoutService windows on wallet_ledger_entries.created_at.
     *
     * @param  Closure(string): void  $log
     */
    private function catchUpPendingPeriods(Carbon $horizon, Carbon $realNow, Closure $log): void
    {
        $stampAt = $horizon->isSameDay($realNow)
            ? $realNow->copy()
            : $horizon->copy()->setTime(23, 59);

        $pending = [];

        foreach (EngineRegistry::all() as $definition) {
            if (! $definition->cadence->isScheduled() || ! $this->isSelected($definition)) {
                continue;
            }

            // (i) In-flight period at the horizon.
            $inFlight = $definition->periodType === EnginePeriodType::Month
                ? $horizon->copy()->startOfMonth()
                : $horizon->copy()->startOfDay();

            if (! isset($this->invoked[$this->invocationKey($definition, $inFlight)])) {
                $pending[] = ['definition' => $definition, 'period' => $inFlight];
            }

            // (ii) Arrears period — month engines only: the period the *next*
            // scheduled firing after the horizon would work on, if that period
            // has already begun.
            if ($definition->periodType === EnginePeriodType::Month) {
                $candidate = $horizon->copy()->addDay();
                $arrearsAdded = false;

                for ($i = 0; $i < 31 && ! $arrearsAdded; $i++, $candidate->addDay()) {
                    if (! $definition->cadence->runsOn($candidate)) {
                        continue;
                    }

                    $arrearsPeriod = $definition->periodRelativeTo($candidate);

                    // Only include if the period has begun by the horizon and is
                    // not a duplicate of the in-flight entry.
                    if ($definition->periodStart($arrearsPeriod)->lte($horizon)
                        && ! isset($this->invoked[$this->invocationKey($definition, $arrearsPeriod)])
                        && ! $arrearsPeriod->isSameMonth($inFlight)
                    ) {
                        $pending[] = ['definition' => $definition, 'period' => $arrearsPeriod];
                    }

                    $arrearsAdded = true;
                }
            }
        }

        // Dedup invocation keys (a period may have been added from (i) and (ii)
        // if the logic overlaps on the same period).
        $seen = [];
        $pending = array_filter($pending, function (array $entry) use (&$seen): bool {
            $key = $this->invocationKey($entry['definition'], $entry['period']);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        });
        $pending = array_values($pending);

        if ($pending === []) {
            return;
        }

        // Sort by period date ascending, then by monthPosition (time within the
        // day on the 1st). August's credits must land in the wallet before
        // September's payout batch sweeps.
        usort(
            $pending,
            static fn (array $a, array $b): int => $a['period']->timestamp <=> $b['period']->timestamp
                ?: self::monthPosition($a['definition']) <=> self::monthPosition($b['definition']),
        );

        $signatures = implode(', ', array_map(
            static fn (array $entry): string => $entry['definition']->commandSignature.'@'.$entry['definition']->formatPeriod($entry['period']),
            $pending,
        ));

        $log('  Catching up periods the scheduler has not reached: '.$signatures);
        $this->progress->phase('Catching up periods the scheduler has not reached', $signatures);

        foreach ($pending as $entry) {
            foreach ($this->unscheduledPrerequisites($entry['definition'], $entry['period']) as $key => $prerequisite) {
                if (isset($this->invoked[$key])) {
                    continue;
                }

                $this->invoke($prerequisite['definition'], $prerequisite['period'], $stampAt);
            }

            $this->invoke($entry['definition'], $entry['period'], $stampAt);
        }
    }

    /** Is this engine part of the replay the caller asked for? */
    private function isSelected(EngineDefinition $definition): bool
    {
        return $this->onlyKeys === null || in_array($definition->key, $this->onlyKeys, true);
    }

    /**
     * Scheduled engines the caller left out — reported, never silent.
     *
     * @return list<string>
     */
    private function skippedKeys(): array
    {
        if ($this->onlyKeys === null) {
            return [];
        }

        $skipped = [];

        foreach (EngineRegistry::all() as $definition) {
            if ($definition->cadence->isScheduled() && ! $this->isSelected($definition)) {
                $skipped[] = $definition->key;
            }
        }

        return $skipped;
    }

    /** Where in a calendar month an engine sits; daily and weekly engines lead. */
    private static function monthPosition(EngineDefinition $definition): string
    {
        return sprintf('%02d|%s', $definition->cadence->dayOfMonth ?? 0, $definition->cadence->time ?? '');
    }

    /**
     * Engines the scheduler would fire on this date, in the order it would fire
     * them.
     *
     * @return list<EngineDefinition>
     */
    private function enginesDueOn(Carbon $day): array
    {
        $due = [];

        foreach (EngineRegistry::all() as $definition) {
            if ($definition->cadence->isScheduled()
                && $definition->cadence->runsOn($day)
                && $this->isSelected($definition)) {
                $due[] = $definition;
            }
        }

        usort(
            $due,
            static fn (EngineDefinition $a, EngineDefinition $b): int => ($a->cadence->time ?? '')
                <=> ($b->cadence->time ?? ''),
        );

        return $due;
    }

    /**
     * Prerequisites nothing in the scheduler fires — today that is only
     * rank.check, which every rank-derived engine depends on but which is
     * manual-only. Read from the engine's declared dependencies rather than
     * hardcoded here, including the 'prev-month' shift GBB's rank gate needs.
     *
     * @return array<string, array{definition: EngineDefinition, period: Carbon}>
     */
    private function unscheduledPrerequisites(EngineDefinition $definition, Carbon $period): array
    {
        $prerequisites = [];

        foreach ($definition->dependencies as $dependency) {
            if (! EngineRegistry::has($dependency['key'])) {
                continue;
            }

            $prerequisite = EngineRegistry::get($dependency['key']);

            if ($prerequisite->cadence->isScheduled()) {
                continue; // the day loop will have run it, or will
            }

            $prerequisitePeriod = ($dependency['shift'] ?? null) === 'prev-month'
                ? $period->copy()->startOfMonth()->subMonthNoOverflow()
                : $prerequisite->periodStart($period);

            $prerequisites[$this->invocationKey($prerequisite, $prerequisitePeriod)] = [
                'definition' => $prerequisite,
                'period' => $prerequisitePeriod,
            ];
        }

        return $prerequisites;
    }

    /**
     * Is this the period the engine has not finished living through — today for
     * a date engine, the current month for a month engine? Those periods belong
     * to the catch-up pass whenever the window ends today.
     */
    private function isInFlight(EngineDefinition $definition, Carbon $period, Carbon $realNow): bool
    {
        return $definition->periodType === EnginePeriodType::Month
            ? $period->isSameMonth($realNow)
            : $period->isSameDay($realNow);
    }

    /** Identifies one engine run for one period, so it can happen only once. */
    private function invocationKey(EngineDefinition $definition, Carbon $period): string
    {
        return $definition->key.'|'.$definition->formatPeriod($period);
    }

    private function invoke(EngineDefinition $definition, Carbon $period, Carbon $at): void
    {
        Carbon::setTestNow($at);

        $exit = Artisan::call($definition->commandSignature, [
            $definition->periodOption => $definition->formatPeriod($period),
        ]);

        if ($exit !== 0) {
            // Abort rather than limp on: every later day is computed from this
            // one, so a silent failure here produces plausible-looking but
            // wrong numbers for the rest of the window.
            Carbon::setTestNow();

            throw new RuntimeException(sprintf(
                'Replay aborted: %s for %s exited with code %d. Output: %s',
                $definition->commandSignature,
                $definition->displayPeriod($period),
                $exit,
                trim(Artisan::output()),
            ));
        }

        $this->engineRuns[$definition->commandSignature] = ($this->engineRuns[$definition->commandSignature] ?? 0) + 1;
        $this->invoked[$this->invocationKey($definition, $period)] = true;
    }
}
