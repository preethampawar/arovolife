<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRegistry;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Replays the scheduler day by day over a historical window.
 *
 * The loop does not know the schedule: it asks each engine's
 * {@see \App\Modules\Compensation\Support\EngineCadence} whether it would have
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
    public function __construct(private readonly RecomputeProgress $progress) {}

    /**
     * @param  Closure(string): void|null  $progress
     * @return array{days: int, engines: array<string, int>}
     */
    public function replay(Carbon $from, Carbon $to, ?Closure $progress = null): array
    {
        $log = $progress ?? static fn (string $_m): null => null;

        $engineRuns = [];
        $ranPrerequisites = [];
        $days = 0;

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
                $at = $definition->cadence->atOn($day);

                foreach ($this->unscheduledPrerequisites($definition, $period) as $key => $prerequisite) {
                    if (isset($ranPrerequisites[$key])) {
                        continue;
                    }

                    $this->invoke($prerequisite['definition'], $prerequisite['period'], $at, $engineRuns);
                    $ranPrerequisites[$key] = true;
                }

                $this->invoke($definition, $period, $at, $engineRuns);
            }

            $days++;

            $this->progress->dayReplayed(
                $day->toDateString(),
                array_map(static fn (EngineDefinition $d): string => $d->commandSignature, $due),
                $days,
                array_sum($engineRuns),
            );
        }

        Carbon::setTestNow();

        return ['days' => $days, 'engines' => $engineRuns];
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
            if ($definition->cadence->isScheduled() && $definition->cadence->runsOn($day)) {
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

            $prerequisites[$prerequisite->key.'|'.$prerequisitePeriod->toDateString()] = [
                'definition' => $prerequisite,
                'period' => $prerequisitePeriod,
            ];
        }

        return $prerequisites;
    }

    /**
     * @param  array<string, int>  $engineRuns
     */
    private function invoke(EngineDefinition $definition, Carbon $period, Carbon $at, array &$engineRuns): void
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

        $engineRuns[$definition->commandSignature] = ($engineRuns[$definition->commandSignature] ?? 0) + 1;
    }
}
