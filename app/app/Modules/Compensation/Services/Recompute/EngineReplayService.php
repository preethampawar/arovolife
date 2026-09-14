<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

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
 * Replays the scheduler, instant by instant, from a start date to a horizon.
 *
 * The loop does not know the schedule: it asks each engine's
 * {@see EngineCadence} whether it would have fired on the day in question, and
 * {@see EngineDefinition::periodForFireOn()} which period that firing works on.
 * Both live in the registry, which EngineRegistryTest pins against
 * routes/console.php — so the replay follows the real schedule without
 * restating it.
 *
 * **Every engine runs at the instant the scheduler would have run it, for the
 * period the scheduler would have handed it.** That is the single invariant
 * this class exists to hold, and three separate defects came from breaking it:
 *
 *  • The cut-off for day D fires at D + 1 00:10, not D 00:10. Firing it a day
 *    early left the next real scheduled run to trip the carry-forward
 *    out-of-order guard (F125), and — because a cut-off writes repurchase
 *    deductions — dated the month's last day's deductions INSIDE the month they
 *    were being judged against.
 *  • A month's crediting engines run on the 1st of the NEXT month. Running them
 *    at "now" while the month was still in flight (the old catch-up pass) meant
 *    Rank Bonus's `repurchase_deduction` rows were dated inside the month, so
 *    Growth Booster and Fortune — which ask whether the repurchase wallet was
 *    empty at the last instant of that month — saw money the month never held
 *    and refused to pay. On staging, 14 Sep 2026.
 *  • The monthly payout batch runs on the 8th, a week after crediting.
 *
 * There is therefore no "in flight" special case and no catch-up pass: a period
 * is computed exactly when its scheduled firing falls at or before the horizon,
 * and not before. {@see RecomputeHorizon} decides where the horizon is; the
 * engines' own guards (an open month, a day that has not ended, a missing
 * repurchase evaluation) are satisfied naturally because the clock is right.
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
 *
 * The clock is travelled to each engine's scheduled instant so the rows carry
 * the timestamps the real run would have written. That is not cosmetic:
 * PayoutService windows the monthly income cap and the repurchase deduction on
 * wallet_ledger_entries.created_at, so replaying six weeks under one wall-clock
 * date would collapse them into a single capped month — and the month-end
 * wallet gate reads the same column.
 */
final class EngineReplayService
{
    /** @var array<string, int> command signature => times invoked this replay */
    private array $engineRuns = [];

    /**
     * "engine.key|period" pairs already invoked this replay. Stops an engine
     * being run twice for one period — as a prerequisite of two different
     * engines, or by a second firing of the same cadence.
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
     * succeeded run far enough forward: the GSB cut-off needs one as at the
     * cut-off date, the rank check one dated the 1st of the month after the
     * month it checks.
     *
     * A replay at the scheduler's clock satisfies both by itself — the 00:05
     * evaluation of D + 1 runs minutes before the 00:10 cut-off for D, and the
     * 1st's evaluation runs before the 00:15 rank check for the month that just
     * closed. What it cannot satisfy is a SELECTION that leaves the evaluation
     * out: the wipe has already deleted the repurchase cycles for the window,
     * the first refusal aborts the replay ({@see self::invoke()}), and the
     * database is left half-rebuilt. So the selection is refused before
     * anything is deleted.
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
     * @param  Carbon  $from  first calendar day to replay
     * @param  Carbon  $horizonInstant  the last instant whose scheduled firings are replayed
     * @param  Closure(string): void|null  $progress
     * @param  list<string>|null  $onlyKeys  replay only these engine keys (their
     *                                       unscheduled prerequisites still run);
     *                                       null replays every engine
     * @return array{days: int, engines: array<string, int>, skipped: list<string>}
     */
    public function replay(Carbon $from, Carbon $horizonInstant, ?Closure $progress = null, ?array $onlyKeys = null): array
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

        // The replay is clock-NEUTRAL: it travels the clock for every
        // invocation and puts back whatever the caller had (a pinned test clock,
        // or real time) however it leaves. A replay that died mid-flight used to
        // clear the clock outright, which silently unpinned a test.
        $callerClock = Carbon::getTestNow();

        $windowStart = $from->copy()->startOfDay();

        // One day past the horizon: the runs that SETTLE the horizon day fire
        // early the next morning (00:05 evaluate, 00:10 cut-off). Whether they
        // are actually replayed is decided per engine by the instant test
        // below, not by the calendar — which is what keeps `now` faithful.
        $lastDay = $horizonInstant->copy()->startOfDay()->addDay();

        $this->progress->daysTotal((int) $windowStart->diffInDays($lastDay) + 1);

        try {
            $this->replayDays($windowStart, $lastDay, $horizonInstant, $log, $days);
        } finally {
            Carbon::setTestNow($callerClock);
        }

        return ['days' => $days, 'engines' => $this->engineRuns, 'skipped' => $skipped];
    }

    /**
     * The day loop itself. Split out so {@see self::replay()} can guarantee the
     * caller's clock is restored however this returns.
     *
     * @param  Closure(string): void  $log
     */
    private function replayDays(Carbon $windowStart, Carbon $lastDay, Carbon $horizonInstant, Closure $log, int &$days): void
    {
        for ($day = $windowStart->copy(); $day->lte($lastDay); $day->addDay()) {
            $firedToday = [];

            foreach ($this->enginesDueOn($day) as $definition) {
                $at = $definition->cadence->atOn($day);

                // The scheduler has not reached this firing. Nothing about it is
                // deferred or caught up later: it simply has not happened.
                if ($at->gt($horizonInstant)) {
                    continue;
                }

                $period = $definition->periodForFireOn($day);

                if ($this->periodPrecedesWindow($definition, $period, $windowStart)) {
                    continue;
                }

                foreach ($this->unscheduledPrerequisites($definition, $period) as $key => $prerequisite) {
                    if (isset($this->invoked[$key])) {
                        continue;
                    }

                    $this->invoke($prerequisite['definition'], $prerequisite['period'], $at);
                }

                $this->invoke($definition, $period, $at, [
                    ...$this->overridesMonthlyPayoutGate($definition),
                    // At a correct firing instant every judged period has closed,
                    // so this yields nothing — except for the monthly payout
                    // batch, whose batch month is in flight BY DESIGN (the 8th of
                    // it is the day it runs). MonthlyPayoutCloseCommand lifts the
                    // same refusal for the same reason.
                    ...OpenMonthGuard::overrideFor($definition->commandSignature, $period),
                ]);

                $firedToday[] = $definition->commandSignature;
            }

            if ($firedToday !== []) {
                $log(sprintf('  %s  %s', $day->format('D d M Y'), implode(', ', $firedToday)));
            }

            $days++;

            $this->progress->dayReplayed(
                $day->toDateString(),
                $firedToday,
                $days,
                array_sum($this->engineRuns),
            );
        }
    }

    /**
     * Is this firing about a DAY that lies before the window the wiper cleared?
     *
     * The first day of a window is reached by a cut-off that fires on it and
     * processes the day before — a day whose rows were deliberately left
     * standing by a windowed wipe, and whose carry-forward has already been
     * rewound to that point. Re-running it would advance the store twice.
     *
     * Month-typed engines are exempt: a monthly firing works the month that has
     * just closed, so the close on the 1st legitimately reaches back before a
     * mid-month window start — and the month in flight is only ever computed by
     * a firing on the 1st of the month after it.
     */
    private function periodPrecedesWindow(EngineDefinition $definition, Carbon $period, Carbon $windowStart): bool
    {
        return $definition->periodType === EnginePeriodType::Date
            && $definition->periodStart($period)->lt($windowStart);
    }

    /**
     * The monthly batch's crediting-completion gate, lifted for a replay.
     *
     * `payout:monthly-run` refuses to build a batch while the month whose
     * credits it sweeps has a crediting engine that has not succeeded — the
     * guarantee that nobody pays a month by hand before it has been looked at.
     * A replay is the opposite situation: it has just rebuilt (or, for a
     * selective replay, deliberately not rebuilt) those very engines seconds
     * earlier inside the same process, and refusing would abort the rebuild
     * after the wipe, leaving no batch at all.
     *
     * Narrow, like the open-month override beside it: only `payout.monthly`, and
     * only from the replay, which is developer-gated and testing-only.
     *
     * @return array<string, bool> `--force` for the batch, empty otherwise
     */
    private function overridesMonthlyPayoutGate(EngineDefinition $definition): array
    {
        return $definition->key === 'payout.monthly' ? ['--force' => true] : [];
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

    /** Identifies one engine run for one period, so it can happen only once. */
    private function invocationKey(EngineDefinition $definition, Carbon $period): string
    {
        return $definition->key.'|'.$definition->formatPeriod($period);
    }

    /**
     * @param  array<string, bool|string>  $extraOptions  guard overrides — see
     *                                                    {@see self::overridesMonthlyPayoutGate()}; empty everywhere else
     */
    private function invoke(EngineDefinition $definition, Carbon $period, Carbon $at, array $extraOptions = []): void
    {
        if (isset($this->invoked[$this->invocationKey($definition, $period)])) {
            return;
        }

        Carbon::setTestNow($at);

        $exit = Artisan::call($definition->commandSignature, [
            $definition->periodOption => $definition->formatPeriod($period),
            ...$extraOptions,
        ]);

        if ($exit !== 0) {
            // Abort rather than limp on: every later day is computed from this
            // one, so a silent failure here produces plausible-looking but
            // wrong numbers for the rest of the window.
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
