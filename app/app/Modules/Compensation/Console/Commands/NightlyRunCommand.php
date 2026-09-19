<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Console\Commands\Concerns\OrchestratesEngineSteps;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\NightlyRunAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The nightly run: the repurchase evaluation for tonight, and the GSB cut-off
 * for every day that is still owed one.
 *
 * Two steps and no more. Until ADR-0016 this one command also closed the month
 * on the 1st, built the Tuesday payout batch and ran the 8th's payout close, so
 * one `engine_runs` row carried three cadences at once: a monthly step that
 * refused — a month whose days were not all cut off, a payout the completion
 * gate held back — marked the night FAILED, although the daily work it names
 * had finished perfectly. The weekly and monthly runs are their own commands
 * now (`compensation:weekly-run`, `compensation:monthly-run`) and they wait on
 * this one — not the other way round.
 *
 * WHAT RUNS ON A GIVEN NIGHT, in this order:
 *
 *   1. `repurchase:evaluate --date=<tonight>`  — every night.
 *   2. `gsb:daily-cutoff --date=<yesterday>`   — every night, preceded by any
 *                                                night that was missed.
 *
 * A PERIOD IS JUDGED ONLY AFTER IT HAS ENDED. Step 1 is dated tonight precisely
 * so it has seen the whole of every day this run is about to cut off, which is
 * what step 2's own guard demands. Nothing here ever prices a period that is
 * still in flight.
 *
 * BACKFILL. A night can be LOST — `withoutOverlapping()` skips the whole entry
 * when the previous night is still running, the container can be down at 00:05,
 * the scheduler can be paused — and a day that is never cut off is a day nobody
 * is credited for, with no record that it happened. So the run works forward
 * from the newest day it can PROVE was cut off, up to {@see MAX_BACKFILL_DAYS}
 * nights back; a longer gap is a decision a human makes, and is recorded
 * through {@see NightlyRunAlert::backfillGap()}.
 *
 * RESUME, NEVER RESTART. Every step freezes economics or moves money, and a
 * re-run skips every step already recorded SUCCEEDED for its period and starts
 * at the first that is not. `--restart` exists for the rare case where a step
 * genuinely has to be recomputed — the night rebuild passes it, because the
 * pre-wipe success still counts until the re-run has finished (D13).
 *
 * The repurchase evaluation is the exception, deliberately: it is dated TONIGHT,
 * and a succeeded run never counts as done while its period is still in flight —
 * that guard is what stops a mid-period recompute from convincing a later close
 * the work was finished. So a re-run fires it again, which is what is wanted:
 * re-evaluating cycles re-reads the state as it stands now.
 *
 * Each step is invoked through Artisan::call, so RecordEngineRun writes that
 * step's own `engine_runs` row from the console events — nested invocations
 * included. This command's own row is written the same way.
 */
final class NightlyRunCommand extends Command
{
    use OrchestratesEngineSteps;

    protected $signature = 'compensation:nightly-run
                            {--date= : The night to run (YYYY-MM-DD, defaults to tonight)}
                            {--force : Run the steps even when the preflight refuses}
                            {--restart : Re-run every step, including ones already recorded as succeeded}';

    protected $description = 'Run tonight\'s repurchase evaluation and the GSB cut-offs the night owes, resuming at the first step that has not succeeded';

    /**
     * How far back a single night may reach to heal missed nights.
     *
     * A month of them is an outage, not a hiccup: replaying thirty-one nights
     * of cut-offs unattended would run the heaviest work of the month in one
     * process, and if it aborted part-way the operator would be reading the
     * wreckage of a decision nobody made. Beyond this the gap is recorded
     * through {@see NightlyRunAlert::backfillGap()} and left to a human.
     */
    public const MAX_BACKFILL_DAYS = 31;

    public function __construct(private readonly EngineStatusService $status)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $night = $this->resolveNight('compensation.nightly-run');

        if ($night === null) {
            return self::FAILURE;
        }

        $this->info("Nightly run — {$night->format('D, d M Y')}");

        $refusal = $this->orchestratorPreflight($night, 'compensation.nightly-run');

        if ($refusal !== null) {
            if (! $this->option('force')) {
                return $this->abortRun($night, 'preflight', $refusal);
            }

            $this->warn("Preflight refused but --force was passed:\n{$refusal}");
        }

        $steps = $this->stepsFor($night);
        $restart = (bool) $this->option('restart');

        foreach ($steps as $index => [$key, $period]) {
            $definition = EngineRegistry::get($key);
            $label = sprintf('Step %d/%d — %s', $index + 1, count($steps), $definition->label);

            if (! $restart && $this->status->hasSucceededRun($key, $definition->periodStart($period))) {
                $this->line("{$label}: already succeeded for {$definition->displayPeriod($period)} — resuming past it.");

                continue;
            }

            $this->info("{$label} ({$definition->commandSignature} {$definition->periodOption}={$definition->formatPeriod($period)})");

            $exitCode = $this->runStep($definition, $period);

            if ($exitCode !== 0) {
                return $this->abortRun($night, $key, sprintf(
                    "%s exited %d for %s. The remaining %d step(s) did not run.\n"
                    ."Re-run tonight's nightly run once it is fixed; the steps that already succeeded will be "
                    ."skipped:\n"
                    .'  php artisan compensation:nightly-run --date=%s',
                    $definition->label,
                    $exitCode,
                    $definition->displayPeriod($period),
                    count($steps) - $index - 1,
                    $night->toDateString(),
                ));
            }
        }

        $this->info(sprintf('Nightly run complete — %d step(s) for %s.', count($steps), $night->format('d M Y')));

        return self::SUCCESS;
    }

    protected function auditPrefix(): string
    {
        // Byte-identical to what it was before the split: the triage runbook,
        // the staging history and this command's own tests all read
        // `compensation.nightly_run.aborted`.
        return 'compensation.nightly_run';
    }

    /**
     * The night's steps, in dependency order.
     *
     * @return list<array{0: string, 1: Carbon}>
     */
    private function stepsFor(Carbon $night): array
    {
        // Dated tonight, never yesterday: the evaluation stamps the cycle state
        // as at the date it is given, and a run dated for a past day would
        // stamp tonight's purchases onto an older cycle. Dating it tonight is
        // also exactly what the cut-off's own guard wants — a succeeded
        // evaluation that has seen the whole of the day being cut off, for
        // every day this run is about to cut off.
        $steps = [['repurchase.evaluate', $night->copy()]];

        foreach ($this->cutoffDays($night) as $day) {
            // Never tonight: the cut-off prices a day that has ended.
            $steps[] = ['gsb.daily-cutoff', $day];
        }

        return $steps;
    }

    /**
     * Every day this run owes a cut-off, oldest first.
     *
     * Ordinarily that is yesterday and nothing else. But a night can be LOST:
     * `withoutOverlapping()` skips the whole entry when the previous night is
     * still running, the container can be down at 00:05, the scheduler can be
     * paused. Under the old five-entry schedule a lost night was lost for good
     * — the next night's cut-off was hard-wired to `--date=yesterday`, so the
     * missed day was never computed, and a day that is never cut off is a day
     * nobody is credited for, with no record that it happened.
     *
     * So the run works forward from the newest day it can PROVE was cut off
     * to completion — a succeeded run that started after that day had ended,
     * not merely result rows, which a run that died half way through also
     * leaves behind ({@see EngineStatusService::completedCutoffDatesBetween()}).
     *
     * Yesterday is always included whatever that frontier says: whether
     * yesterday is genuinely finished is the resume check's job, one step down.
     *
     * @return list<Carbon>
     */
    private function cutoffDays(Carbon $night): array
    {
        $yesterday = $night->copy()->subDay();
        $window = $yesterday->copy()->subDays(self::MAX_BACKFILL_DAYS - 1);

        $proven = $this->status->completedCutoffDatesBetween($window, $yesterday);
        $newest = $proven === [] ? null : Carbon::parse($proven[count($proven) - 1])->startOfDay();

        if ($newest === null) {
            // Nothing proven inside the window: either a brand-new environment
            // or an outage longer than the cap. Neither is something to replay
            // unattended at 00:05 — replaying a month of engines in one process
            // is a decision a human makes. Yesterday still runs.
            //
            // The health digest cannot report the rest: it judges each engine on
            // its most recent fire, and that one is about to succeed. So the gap
            // is recorded here or it is recorded nowhere.
            $everProven = $this->status->lastComputedPeriod('gsb.daily-cutoff');

            if ($everProven !== null || $this->status->lastRun('gsb.daily-cutoff') !== null) {
                $this->warn(sprintf(
                    'No cut-off is proven complete anywhere in the last %d days. Only last night will run; the rest '
                    .'needs a decision.',
                    self::MAX_BACKFILL_DAYS,
                ));

                NightlyRunAlert::backfillGap(
                    $night,
                    sprintf(
                        'No GSB cut-off is proven complete in the %d days before %s, so the nightly run healed last '
                        .'night only. Every earlier day is uncomputed: nobody has been credited for it, and no engine '
                        .'reports it as missing because the most recent night succeeded.',
                        self::MAX_BACKFILL_DAYS,
                        $yesterday->toDateString(),
                    ),
                    $everProven?->toDateString(),
                );
            }

            return [$yesterday];
        }

        $days = [];

        for ($day = $newest->copy()->addDay(); $day->lessThan($yesterday); $day->addDay()) {
            $days[] = $day->copy();
        }

        if ($days !== []) {
            $this->warn(sprintf(
                '%d night(s) were missed: backfilling %s to %s before tonight.',
                count($days),
                $days[0]->format('d M Y'),
                $days[count($days) - 1]->format('d M Y'),
            ));
        }

        $days[] = $yesterday;

        return $days;
    }
}
