<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Console\Commands\Concerns\OrchestratesEngineSteps;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\NightlyRunAlert;
use App\Modules\Compensation\Support\RunPrerequisites;
use App\Modules\Compensation\Support\WeeklyRunPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The weekly run: the Tuesday payout batch, and any Tuesday whose batch was
 * never built.
 *
 * Fired at 03:00 every night, but only STARTED on a night that owes a batch —
 * the scheduler asks {@see WeeklyRunPlanner::isDue()} the same question this
 * command then asks, so a night with nothing owed starts no process at all.
 *
 * 03:00 is the position `gsb.weekly-payout` has always declared, and it owes
 * nothing to tonight's figures: the batch dated Tuesday T sweeps only entries
 * earned on or before T−7 ({@see PayoutBatch::weeklyEarningWindow()}), while
 * tonight's cut-off writes `earned_on = T−1`. The sweep lock
 * (`PayoutService::withSweepLock()`) is what serialises it against the monthly
 * payout an hour later, so the ₹50L income cap is never read by two sweeps at
 * once.
 *
 * WHY IT WAITS FOR THE NIGHTLY RUN ANYWAY. The client's rule (2026-09-18) is an
 * ORDERING rule — daily closing first, then the Tuesday payout, then the month
 * — not a data dependency. So a deferral here costs a day and never a figure:
 * the batch is still dated that Tuesday when it is built, so the week it pays
 * is unchanged. It is recorded as a `skipped` run and a
 * {@see NightlyRunAlert::weeklyDeferred()} alert, never a failed night (D3).
 * `--force` is the operator's hand on that rule.
 *
 * A batch built from a shell has no maker: `payout_batches.created_by` is what
 * bars whoever made a batch from approving it, and a command typed at 3 a.m. by
 * nobody in particular records no one. A second person must approve such a
 * batch (R-81).
 */
final class WeeklyRunCommand extends Command
{
    use OrchestratesEngineSteps;

    private const STEP_KEY = 'gsb.weekly-payout';

    protected $signature = 'compensation:weekly-run
                            {--date= : The night to run (YYYY-MM-DD, defaults to tonight)}
                            {--force : Run even when the preflight or the nightly-run prerequisite refuses}';

    protected $description = 'Build the weekly payout batch for every Tuesday that is still owed one';

    public function __construct(
        private readonly WeeklyRunPlanner $planner,
        private readonly RunPrerequisites $prerequisites,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $night = $this->resolveNight('compensation.weekly-run');

        if ($night === null) {
            return self::FAILURE;
        }

        $this->info("Weekly run — {$night->format('D, d M Y')}");

        $refusal = $this->orchestratorPreflight($night, 'compensation.weekly-run');

        if ($refusal !== null) {
            if (! $this->option('force')) {
                return $this->abortRun($night, 'preflight', $refusal);
            }

            $this->warn("Preflight refused but --force was passed:\n{$refusal}");
        }

        $tuesdays = $this->planner->owedTuesdays($night);

        // Asked BEFORE the prerequisite: a night that owes nothing has nothing
        // to defer, and an alert naming no Tuesday would be a warning about a
        // debt that does not exist.
        if ($tuesdays === []) {
            $reason = 'No Tuesday batch is owed tonight.';

            $this->info($reason);
            app(EngineRunContext::class)->noteSkipped($reason);

            return self::SUCCESS;
        }

        $deferral = $this->prerequisiteDeferral($night, $tuesdays);

        if ($deferral !== null) {
            $this->warn($deferral);

            NightlyRunAlert::weeklyDeferred($night, $tuesdays, $deferral);
            app(EngineRunContext::class)->noteSkipped($deferral);

            return self::SUCCESS;
        }

        $definition = EngineRegistry::get(self::STEP_KEY);

        foreach ($tuesdays as $index => $tuesday) {
            $this->info(sprintf(
                'Step %d/%d — %s (%s %s=%s)',
                $index + 1,
                count($tuesdays),
                $definition->label,
                $definition->commandSignature,
                $definition->periodOption,
                $definition->formatPeriod($tuesday),
            ));

            $exitCode = $this->runStep($definition, $tuesday);

            if ($exitCode !== 0) {
                return $this->abortRun($night, self::STEP_KEY, sprintf(
                    "%s exited %d for %s. The remaining %d Tuesday(s) were not built.\n"
                    ."Re-run tonight's weekly run once it is fixed; a Tuesday whose batch exists is left alone:\n"
                    .'  php artisan compensation:weekly-run --date=%s',
                    $definition->label,
                    $exitCode,
                    $definition->displayPeriod($tuesday),
                    count($tuesdays) - $index - 1,
                    $night->toDateString(),
                ));
            }
        }

        $this->info(sprintf(
            'Weekly run complete — %d Tuesday batch(es) for %s.',
            count($tuesdays),
            $night->format('d M Y'),
        ));

        return self::SUCCESS;
    }

    protected function auditPrefix(): string
    {
        return 'compensation.weekly_run';
    }

    /**
     * The message to defer on, or null when tonight's nightly run is green (or
     * `--force` says to go anyway).
     *
     * The refusal fragment comes from {@see RunPrerequisites}, which is the one
     * place that decides what "tonight's nightly run succeeded" means; this only
     * says what it costs and what happens next.
     *
     * @param  list<Carbon>  $tuesdays
     */
    private function prerequisiteDeferral(Carbon $night, array $tuesdays): ?string
    {
        if ($this->option('force')) {
            return null;
        }

        $refusal = $this->prerequisites->nightlyRunRefusal($night);

        if ($refusal === null) {
            return null;
        }

        return sprintf(
            '%s, so the %s weekly batch was not built. It is built on the first night after the nightly run is '
            .'green — or run php artisan compensation:weekly-run --date=%s once it is.',
            // The fragment already opens with "tonight's nightly run … has not
            // succeeded"; wrapping it in a sentence that says so again is how a
            // real message reads like boilerplate.
            ucfirst($refusal),
            implode(', ', array_map(
                static fn (Carbon $tuesday): string => $tuesday->toDateString(),
                $tuesdays,
            )),
            $night->toDateString(),
        );
    }
}
