<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Console\Commands\Concerns\OrchestratesEngineSteps;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\MonthlyRunPlanner;
use App\Modules\Compensation\Support\NightlyRunAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The monthly run: the crediting close for the month that has just ended, and
 * the payout batch that pays it a week later.
 *
 * Two phases, always in this order, and the payout phase is PLANNED after the
 * close phase has run — not merely executed after it. The payout gate reads the
 * crediting engines' own run rows, so a payout planned first would be judged
 * against the state as it stood before tonight's close and would refuse a month
 * the same night's close has just completed.
 *
 * The phases wait on different things. The close waits on tonight's nightly run
 * — and on tonight's weekly run when a Tuesday batch is owed — because the
 * client's ordering rule (2026-09-18) is daily, then weekly, then monthly. The
 * payout phase waits on neither: the 8th pays what the 1st credited, and
 * nothing tonight's runs write can change a figure it sweeps.
 *
 * A MONTH IT CANNOT CLOSE OR PAY IS A DEFERRAL, NEVER A FAILED NIGHT (D3): the
 * run warns, writes the matching {@see NightlyRunAlert}, records its own row as
 * `skipped` and exits 0. It is re-attempted every night it is due, which is why
 * there is no button anywhere for it — waiting is the remedy, and the alert
 * says what is being waited for.
 *
 * `--force` skips the prerequisites: it is the operator's hand on the ordering
 * rule, not on the coverage rule. A month whose days are not all cut off is
 * still deferred, because every monthly engine freezes what it computes and a
 * month closed short stays short.
 *
 * Each step is invoked through Artisan::call, so RecordEngineRun writes that
 * step's own `engine_runs` row from the console events — and the close nests a
 * second orchestrator inside this one.
 */
final class MonthlyRunCommand extends Command
{
    use OrchestratesEngineSteps;

    private const CLOSE_KEY = 'compensation.monthly-close';

    private const PAYOUT_KEY = 'compensation.monthly-payout-close';

    protected $signature = 'compensation:monthly-run
                            {--date= : The night to run (YYYY-MM-DD, defaults to tonight)}
                            {--force : Run even when the preflight or the ordering prerequisites refuse}';

    protected $description = 'Close the month that has ended and, from the 8th, build its payout batch';

    public function __construct(private readonly MonthlyRunPlanner $planner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $night = $this->resolveNight('compensation.monthly-run');

        if ($night === null) {
            return self::FAILURE;
        }

        $this->info("Monthly run — {$night->format('D, d M Y')}");

        $refusal = $this->orchestratorPreflight();

        if ($refusal !== null) {
            if (! $this->option('force')) {
                return $this->abortRun($night, 'preflight', $refusal);
            }

            $this->warn("Preflight refused but --force was passed:\n{$refusal}");
        }

        $reasons = [];
        $stepped = 0;

        $close = $this->planner->closePhase($night, (bool) $this->option('force'));

        foreach ($close->deferrals as $deferral) {
            $this->warn($deferral->reason);
            $reasons[] = $deferral->reason;

            NightlyRunAlert::monthCloseDeferred(
                $night,
                $deferral->month,
                $deferral->missingDays,
                // The DTO's cause is nullable because a PAYOUT deferral carries
                // none; every close deferral the planner makes names one of the
                // three. The coalesce is unreachable and is here so that a
                // causeless close deferral would be FILED — under the cause
                // whose remedy an operator would look into anyway — rather than
                // dropped or recorded under an empty string.
                $deferral->cause ?? 'coverage',
                $deferral->reason,
            );
        }

        foreach ($close->months as $month) {
            $exit = $this->step(self::CLOSE_KEY, $month, $night);

            if ($exit !== self::SUCCESS) {
                return $exit;
            }

            $stepped++;
        }

        // AFTER the close, never beside it: see the class docblock.
        $payout = $this->planner->payoutPhase($night);

        foreach ($payout->deferrals as $deferral) {
            $this->warn($deferral->reason);
            $reasons[] = $deferral->reason;

            NightlyRunAlert::payoutDeferred($night, $deferral->month, $deferral->engineKey, $deferral->reason);
        }

        foreach ($payout->months as $creditingMonth) {
            $exit = $this->step(self::PAYOUT_KEY, $creditingMonth, $night);

            if ($exit !== self::SUCCESS) {
                return $exit;
            }

            $stepped++;
        }

        if ($stepped === 0) {
            // A night that stepped nothing says WHY in its own row: "nothing
            // owed" and "owed but deferred" look identical on the Engine Runs
            // page otherwise, and only one of them is somebody waiting to be
            // paid.
            $summary = $reasons === [] ? 'Nothing owed tonight.' : implode("\n\n", $reasons);

            $this->info($reasons === [] ? $summary : 'Nothing was run tonight — see the deferrals above.');
            app(EngineRunContext::class)->noteSkipped($summary);

            return self::SUCCESS;
        }

        $this->info(sprintf('Monthly run complete — %d step(s) for %s.', $stepped, $night->format('d M Y')));

        return self::SUCCESS;
    }

    protected function auditPrefix(): string
    {
        return 'compensation.monthly_run';
    }

    /**
     * Invoke one phase step, returning SUCCESS or the abort's FAILURE.
     *
     * No resume check here, unlike the nightly run: both steps are themselves
     * orchestrators that resume internally, and the planner has already ruled
     * out a month that is closed or already paid.
     */
    private function step(string $key, Carbon $period, Carbon $night): int
    {
        $definition = EngineRegistry::get($key);

        $this->info(sprintf(
            '%s (%s %s=%s)',
            $definition->label,
            $definition->commandSignature,
            $definition->periodOption,
            $definition->formatPeriod($period),
        ));

        $exitCode = $this->runStep($definition, $period);

        if ($exitCode === 0) {
            return self::SUCCESS;
        }

        return $this->abortRun($night, $key, sprintf(
            "%s exited %d for %s.\nRe-run tonight's monthly run once it is fixed; a month that has already closed "
            ."or been paid is left alone:\n"
            .'  php artisan compensation:monthly-run --date=%s',
            $definition->label,
            $exitCode,
            $definition->displayPeriod($period),
            $night->toDateString(),
        ));
    }
}
