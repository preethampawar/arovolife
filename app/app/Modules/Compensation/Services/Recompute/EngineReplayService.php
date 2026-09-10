<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\RankCheckCommand;
use App\Modules\Compensation\Support\EngineCadence;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\OpenMonthGuard;
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
 *   • Unscheduled prerequisites run before whichever engine declares them, for
 *     the period that engine's dependency declares. Every registered engine
 *     currently has a cadence, so that pass finds nothing — it stays as the
 *     safety net for the next manual-only engine.
 *   • repurchase:evaluate is never deferred to the catch-up: the engines due
 *     later the same day refuse to run without it (see isInFlight()).
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

    /**
     * Engines whose commands REFUSE to run — exit 1, a FAILED engine run —
     * while the repurchase engine is on and `repurchase:evaluate` has no
     * succeeded run far enough forward: {@see GsbDailyCutoffCommand}
     * needs one as at the cut-off date, {@see RankCheckCommand}
     * one dated the 1st of the month after the month it checks.
     *
     * A partial replay that ticks one of these without ticking Repurchase
     * Evaluation does not merely leave a gap: the wipe has already deleted the
     * repurchase cycles for the window, the first refusal aborts the replay
     * ({@see self::invoke()}), and the database is left half-rebuilt. So the
     * selection is refused before anything is deleted.
     *
     * @var list<string>
     */
    public const GUARDED_BY_REPURCHASE_EVALUATE = ['gsb.daily-cutoff', 'rank.check'];

    public function __construct(private readonly RecomputeProgress $progress) {}

    /**
     * Which of the selected engines cannot run because the selection leaves
     * Repurchase Evaluation out. Empty for a full replay (null / no selection),
     * and empty once the evaluation is selected too.
     *
     * The caller checks the repurchase flag: with the engine off the guards are
     * skipped and any selection is runnable.
     *
     * @param  list<string>|null  $onlyKeys
     * @return list<string>
     */
    public static function guardedEnginesMissingEvaluate(?array $onlyKeys): array
    {
        if ($onlyKeys === null || $onlyKeys === [] || in_array('repurchase.evaluate', $onlyKeys, true)) {
            return [];
        }

        return array_values(array_intersect(self::GUARDED_BY_REPURCHASE_EVALUATE, $onlyKeys));
    }

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

                $this->invoke($definition, $period, $at, $this->overridesCutoffEvaluateGuard($definition, $period));
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
            if (! $definition->cadence->isScheduled() || $definition->isOrchestrator || ! $this->isSelected($definition)) {
                continue;
            }

            // (i) In-flight period at the horizon.
            //
            // A Date engine is not necessarily a *daily* engine: the weekly
            // payout batch fires only on a Tuesday, because it sweeps the
            // Wednesday-Tuesday week that closed seven days earlier. Handing it
            // the horizon date whenever the horizon is not a Tuesday made the
            // command refuse the batch outright ("a weekly payout batch is
            // dated a Tuesday"), aborting the whole replay on six days out of
            // seven. The period in flight for a date engine is therefore the
            // LATEST date its cadence would have fired on at or before the
            // horizon: the horizon itself for a daily engine, the most recent
            // Tuesday for the weekly batch. Dropping the engine instead would
            // leave it uncomputed, which is the one thing this pass exists to
            // prevent.
            $inFlight = $definition->periodType === EnginePeriodType::Month
                ? $horizon->copy()->startOfMonth()
                : $this->latestFiringAtOrBefore($definition, $horizon);

            if (! isset($this->invoked[$this->invocationKey($definition, $inFlight)])) {
                $pending[] = ['definition' => $definition, 'period' => $inFlight, 'inFlight' => true];
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

            $options = $this->overridesGuardAtHorizon($entry['definition'], $entry['period'], $horizon)
                ? ['--force' => true]
                : $this->overridesCutoffEvaluateGuard($entry['definition'], $entry['period']);

            // The month in flight at the horizon is computed provisionally by
            // design ("this month's bonuses, computed as at this moment"); the
            // freezing engines refuse an open month unless told so explicitly.
            if ($entry['inFlight'] ?? false) {
                $options += OpenMonthGuard::overrideFor($entry['definition']->commandSignature, $entry['period']);
            }

            $this->invoke($entry['definition'], $entry['period'], $stampAt, $options);
        }
    }

    /**
     * Whether this catch-up invocation has to override the engine's own
     * repurchase guard, because the run that would satisfy it lies beyond the
     * window.
     *
     * `rank:check-qualifications` refuses without a `repurchase:evaluate` run
     * dated the 1st of the month AFTER the month it checks — proof that every
     * cycle due in that month has been judged. For the month still IN FLIGHT at
     * the horizon that date has not arrived, so no replay can produce it: the
     * choice is between forcing this one invocation and aborting the whole
     * replay after the wipe. The month is provisional by construction, and the
     * report already says so.
     *
     * Narrow on purpose, never blanket:
     *  • only rank.check, and only from the catch-up pass;
     *  • only when the required date is beyond the horizon — every CLOSED month
     *    is judged by an evaluation the day loop really ran, and keeps its
     *    guard;
     *  • only when this replay has itself run the evaluation, so a selection
     *    that left it out cannot slip a forced rank check past the operator.
     */
    private function overridesGuardAtHorizon(EngineDefinition $definition, Carbon $period, Carbon $horizon): bool
    {
        return $definition->key === 'rank.check'
            && ($this->engineRuns['repurchase:evaluate'] ?? 0) > 0
            && $period->copy()->startOfMonth()->addMonthNoOverflow()->gt($horizon);
    }

    /**
     * Whether this cut-off invocation has to override its own evaluate guard.
     *
     * `gsb:daily-cutoff --date=D` refuses without a `repurchase:evaluate` run
     * that has SEEN all of D — in production the 00:05 run on D + 1, because a
     * purchase made late on D would otherwise still read as a failed cycle and
     * be forfeited permanently. A replay has no such hazard: it fires each
     * day's engines in cadence order over data that is already complete in the
     * database, so the evaluation it ran moments earlier for the same day has
     * seen every purchase that day will ever hold. Without the override the
     * replay would abort on its first day, after the wipe.
     *
     * Narrow, like the horizon override:
     *  • only gsb.daily-cutoff;
     *  • only when this replay has itself already run the evaluation for that
     *    exact day, so a selection that left evaluate out cannot slip a forced
     *    cut-off past the operator.
     *
     * @return array<string, bool> `--force` when overridden, empty otherwise
     */
    private function overridesCutoffEvaluateGuard(EngineDefinition $definition, Carbon $period): array
    {
        if ($definition->key !== 'gsb.daily-cutoff') {
            return [];
        }

        $evaluateKey = $this->invocationKey(EngineRegistry::get('repurchase.evaluate'), $period);

        return isset($this->invoked[$evaluateKey]) ? ['--force' => true] : [];
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
            if ($definition->cadence->isScheduled() && ! $definition->isOrchestrator && ! $this->isSelected($definition)) {
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
            // Orchestrators are skipped: the replay drives the individual
            // engines directly, so running the close as well would invoke every
            // one of its steps a second time.
            if ($definition->cadence->isScheduled()
                && ! $definition->isOrchestrator
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
     * Prerequisites nothing in the scheduler fires. Every registered engine has
     * a cadence today — rank.check gained one when it joined the monthly close —
     * so this returns nothing; it is kept as the mechanism for the next
     * manual-only prerequisite, read from the engine's declared dependencies
     * rather than hardcoded here, including the 'prev-month' shift GBB's rank
     * gate needs.
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
        // The evaluation is never deferred. It freezes no period's economics —
        // it refreshes each cycle as at a date — and the engines due later the
        // same day REFUSE to run without it. Handing today's evaluation to the
        // catch-up starved them: a replay whose window ends on the 1st of a
        // month aborted on rank:check-qualifications for the month that just
        // closed, after the wipe had already run. It runs in the day loop at
        // the instant the scheduler would have used, and the catch-up then
        // finds it already invoked.
        if ($definition->key === 'repurchase.evaluate') {
            return false;
        }

        return $definition->periodType === EnginePeriodType::Month
            ? $period->isSameMonth($realNow)
            : $period->isSameDay($realNow);
    }

    /**
     * The period a date-type engine would most recently have been fired for, at
     * or before the horizon — the horizon itself for a daily cadence, the last
     * Tuesday for the weekly payout batch. Every registered date engine fires
     * within seven days; a cadence that fired on no day in the preceding month
     * would be a registry mistake, and it aborts loudly rather than letting the
     * catch-up drop that engine in silence.
     *
     * When the horizon is not a Tuesday and that last Tuesday lies before the
     * window start, the catch-up creates an empty `pending` weekly batch dated
     * that Tuesday. The real scheduler never writes such a row; the client
     * accepted it on 2026-09-10 because this tool is temporary and the next
     * recompute wipes the row.
     */
    private function latestFiringAtOrBefore(EngineDefinition $definition, Carbon $horizon): Carbon
    {
        $candidate = $horizon->copy()->startOfDay();

        for ($i = 0; $i < 31; $i++, $candidate->subDay()) {
            if ($definition->cadence->runsOn($candidate)) {
                return $definition->periodRelativeTo($candidate);
            }
        }

        throw new RuntimeException(sprintf(
            'Replay aborted: %s fires on no day in the 31 days up to %s, so its period in flight cannot be determined.',
            $definition->commandSignature,
            $horizon->toDateString(),
        ));
    }

    /** Identifies one engine run for one period, so it can happen only once. */
    private function invocationKey(EngineDefinition $definition, Carbon $period): string
    {
        return $definition->key.'|'.$definition->formatPeriod($period);
    }

    /**
     * @param  array<string, bool|string>  $extraOptions  guard overrides — see
     *                                                    {@see self::overridesGuardAtHorizon()}; empty everywhere else
     */
    private function invoke(EngineDefinition $definition, Carbon $period, Carbon $at, array $extraOptions = []): void
    {
        Carbon::setTestNow($at);

        $exit = Artisan::call($definition->commandSignature, [
            $definition->periodOption => $definition->formatPeriod($period),
            ...$extraOptions,
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
