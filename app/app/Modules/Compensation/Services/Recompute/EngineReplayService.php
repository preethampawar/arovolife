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

        // Read before the first travel. The window now ends today, so the last
        // day's engines are routinely due at an hour that has not arrived yet —
        // 00:10 when it is 00:04 — and a scheduled instant beyond this point
        // would stamp rows into the future.
        $realNow = Carbon::now();

        // When the window runs right up to today, the catch-up pass below owns
        // every period still in flight: it computes them on the real clock, so
        // their frozen rows say when they were actually frozen. The day loop
        // stepping onto one of those periods would freeze it hours early, at
        // the simulated schedule instant, and the catch-up would then skip it
        // as already covered.
        $catchUpWillRun = $to->isSameDay($realNow);

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

                if ($catchUpWillRun && $this->isInFlight($definition, $period, $realNow)) {
                    continue;
                }

                $at = $definition->cadence->atOn($day);
                $at = $at->gt($realNow) ? $realNow->copy() : $at;

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

        if ($to->isSameDay($realNow)) {
            $this->catchUpCurrentPeriods($realNow, $log);
        }

        Carbon::setTestNow();

        return ['days' => $days, 'engines' => $this->engineRuns, 'skipped' => $skipped];
    }

    /**
     * Bring every engine up to the period that is still in flight — testing
     * only, and only when the replay runs right up to today.
     *
     * The day loop fires an engine on the calendar day the scheduler would have
     * fired it, which leaves the current period uncomputed: on a Thursday the
     * weekly payout last ran on Tuesday, and this month's bonuses are not due
     * until next month. That is correct in production — you cannot pay out a
     * period that has not closed, and a frozen result is never recomputed. It is
     * useless in a replay, where the whole point is to see what the plan pays on
     * the data as it stands right now.
     *
     * So each scheduled engine runs once more for the period in flight — today
     * for the date engines, this month for the month engines. The day loop
     * deliberately steps around those periods (see replay()), so this pass owns
     * them outright and stamps their rows with the real clock: a frozen row for
     * an unfinished period must say when it was actually frozen, not the
     * simulated schedule instant the replay was pretending it was. The results
     * are partial by construction and freeze exactly like any other run, which
     * is only safe because this tool wipes and rebuilds every derived row on
     * each use. The
     * scheduler itself is untouched: nothing here changes when or for which
     * period a production run fires.
     *
     * @param  Closure(string): void  $log
     */
    private function catchUpCurrentPeriods(Carbon $now, Closure $log): void
    {
        $pending = [];

        foreach (EngineRegistry::all() as $definition) {
            // Manual-only engines have no period of their own to catch up; they
            // ride along below as prerequisites of the engines that need them.
            if (! $definition->cadence->isScheduled() || ! $this->isSelected($definition)) {
                continue;
            }

            $period = $definition->periodType === EnginePeriodType::Month
                ? $now->copy()->startOfMonth()
                : $now->copy()->startOfDay();

            if (isset($this->invoked[$this->invocationKey($definition, $period)])) {
                continue;
            }

            $pending[] = ['definition' => $definition, 'period' => $period];
        }

        if ($pending === []) {
            return;
        }

        // Ordered as the calendar orders them — the 2nd's engine before the
        // 8th's before the 9th's — because that sequence is the dependency
        // order the month was designed around: rank gate, GBB, rank bonus, ADC,
        // fortune, then the payout batch that settles what they credited.
        usort(
            $pending,
            static fn (array $a, array $b): int => self::monthPosition($a['definition'])
                <=> self::monthPosition($b['definition']),
        );

        $signatures = implode(', ', array_map(
            static fn (array $entry): string => $entry['definition']->commandSignature,
            $pending,
        ));

        $log('  Catching up the period in flight: '.$signatures);
        $this->progress->phase('Catching up the period in flight', $signatures);

        foreach ($pending as $entry) {
            foreach ($this->unscheduledPrerequisites($entry['definition'], $entry['period']) as $key => $prerequisite) {
                if (isset($this->invoked[$key])) {
                    continue;
                }

                $this->invoke($prerequisite['definition'], $prerequisite['period'], $now);
            }

            $this->invoke($entry['definition'], $entry['period'], $now);
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
