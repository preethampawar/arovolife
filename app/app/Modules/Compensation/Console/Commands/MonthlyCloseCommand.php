<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Compensation\Support\ResolvesMonthOption;
use App\Modules\Compensation\Support\WorkerFreshness;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * The monthly CREDITING close: one process, one lock, seven engines in order.
 *
 * It replaces seven independent `Schedule::command` entries that were sequenced
 * only by 15-minute clock offsets. `withoutOverlapping()` is per-command and
 * does not serialise across commands, so nothing held the order: if
 * `rank:check-qualifications` at 00:15 overran, Rank Bonus fired at 00:30
 * against an empty `rank_qualifications` table, priced the month with no
 * qualifiers, froze it, and never retried. The 1st is also the heaviest night of
 * the month — the GSB cut-off at 00:10 processes the whole of the closed
 * month's last day — so the 15 minutes of slack was the thinnest it ever was
 * exactly when it mattered most.
 *
 * Sequencing them inside one command makes the ordering real: a step runs only
 * after the previous one has exited 0.
 *
 * RESUME, NEVER RESTART. Every one of these engines freezes economics or moves
 * money, and all of them are idempotent re-runners — but "idempotent" is not
 * "free": a re-run reopens pools, re-reads gates and re-emits events. A failure
 * at step 5 must leave steps 1–4 untouched, so a re-run skips every step already
 * recorded SUCCEEDED for the month and starts at the first that is not.
 * `--restart` exists for the rare case where an earlier step genuinely has to be
 * recomputed.
 *
 * Each step is invoked through Artisan::call, so RecordEngineRun writes that
 * step's own `engine_runs` row from the console events — nested invocations
 * included; the listener keeps a stack per signature precisely for this. This
 * command's own row is written the same way.
 */
final class MonthlyCloseCommand extends Command
{
    use ResolvesMonthOption;

    protected $signature = 'compensation:monthly-close
                            {--month= : Month to close (YYYY-MM, defaults to the month that has just ended)}
                            {--force : Run the steps even when the preflight refuses}
                            {--in-flight : Testing only — close a month that has not ended; every freeze is provisional}
                            {--restart : Re-run every step, including ones already recorded as succeeded}';

    protected $description = 'Run the month\'s crediting engines in dependency order, resuming at the first step that has not succeeded';

    /**
     * The crediting sequence. Order is the contract: rank qualifications before
     * everything that reads them, and Fortune enrolment before the Fortune
     * payout that freezes the matrix.
     *
     * @var list<string>
     */
    private const STEPS = MonthlyEngineCompletionGate::ENGINE_KEYS;

    /**
     * How long to wait for the closed month's last daily cut-off before giving
     * up. The close is scheduled at 00:20 and the cut-off starts at 00:10; ten
     * minutes of patience covers a heavy month-end without letting a genuinely
     * stuck cut-off hold the close open all night.
     */
    private const CUTOFF_WAIT_ATTEMPTS = 20;

    private const CUTOFF_WAIT_SECONDS = 30;

    public function __construct(private readonly EngineStatusService $status)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $month = $this->resolveMonth();

        if ($month === null) {
            return self::FAILURE;
        }

        $this->info("Monthly close — {$month->format('F Y')}");

        if ($this->option(OpenMonthGuard::OPTION) && OpenMonthGuard::isOpen($month)) {
            // A provisional freeze of a month still in flight is a plan-state
            // decision, so it gets a retention-guaranteed audit row (R-35), not
            // only a console line.
            $this->warn('Running IN FLIGHT: every freeze this close makes is provisional.');
            AuditLog::create([
                'actor_id' => null,
                'action' => 'compensation.monthly_close.in_flight',
                'subject_type' => 'platform',
                'subject_id' => 0,
                'details' => [
                    'month' => $month->format('Y-m'),
                    'reason' => '--in-flight passed: month closed early on partial BV; figures are provisional',
                ],
            ]);
        }

        $refusal = $this->preflight($month);

        if ($refusal !== null) {
            if (! $this->option('force')) {
                return $this->abort($month, 'preflight', $refusal);
            }

            $this->warn("Preflight refused but --force was passed:\n{$refusal}");
        }

        $restart = (bool) $this->option('restart');

        foreach (self::STEPS as $index => $key) {
            $definition = EngineRegistry::get($key);
            $period = MonthlyEngineCompletionGate::periodFor($definition, $month);
            $label = sprintf('Step %d/%d — %s', $index + 1, count(self::STEPS), $definition->label);

            if (! $restart && $this->status->hasSucceededRun($key, $definition->periodStart($period))) {
                $this->line("{$label}: already succeeded for {$definition->displayPeriod($period)} — resuming past it.");

                continue;
            }

            $this->info("{$label} ({$definition->commandSignature} {$definition->periodOption}={$definition->formatPeriod($period)})");

            $exitCode = $this->runStep($definition, $period);

            if ($exitCode !== 0) {
                return $this->abort($month, $key, sprintf(
                    "%s exited %d for %s. The remaining %d step(s) did not run.\nRe-run this close once it is fixed; the steps that already succeeded will be skipped:\n  php artisan compensation:monthly-close --month=%s",
                    $definition->label,
                    $exitCode,
                    $definition->displayPeriod($period),
                    count(self::STEPS) - $index - 1,
                    $month->format('Y-m'),
                ));
            }
        }

        $this->info("Monthly close complete for {$month->format('F Y')}. Payment runs on the 8th (compensation:monthly-payout-close).");

        return self::SUCCESS;
    }

    /**
     * Invoke one step. Exceptions are caught rather than allowed to escape:
     * escaping would abort the close before it could record why, and the
     * remaining steps would look as though they had never been reached for no
     * stated reason.
     */
    private function runStep(EngineDefinition $definition, Carbon $period): int
    {
        try {
            $exitCode = Artisan::call($definition->commandSignature, [
                $definition->periodOption => $definition->formatPeriod($period),
                // Only when this close itself was told to run in flight; a
                // closed month never needs it and a step's own guard stays.
                ...($this->option(OpenMonthGuard::OPTION) ? OpenMonthGuard::overrideFor($definition->commandSignature, $period) : []),
            ]);
        } catch (Throwable $e) {
            Log::error('compensation.monthly_close.step_crashed', [
                'engine_key' => $definition->key,
                'period' => $definition->formatPeriod($period),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->error(sprintf('%s threw %s: %s', $definition->label, $e::class, $e->getMessage()));

            return self::FAILURE;
        }

        $output = trim(Artisan::output());

        if ($output !== '') {
            $this->line($output);
        }

        return $exitCode;
    }

    /**
     * Two checks, both once for the whole close rather than once per engine.
     *
     * 1. Stale worker — a process running pre-deploy code credits the wrong
     *    money with no error anywhere (see WorkerFreshness).
     * 2. The closed month's last daily cut-off. Every monthly engine reads the
     *    month's cut-off results; starting before the last day has settled
     *    prices the month against a short month. The cut-off for the 31st runs
     *    at 00:10 on the 1st and this close at 00:20, so it usually costs
     *    nothing — but "usually" is what the old 15-minute offsets assumed too,
     *    which is why this waits and then ABORTS rather than assuming.
     */
    private function preflight(Carbon $month): ?string
    {
        $stale = WorkerFreshness::staleReason();

        if ($stale !== null) {
            return $stale;
        }

        // The cut-off cannot compute anything while GSB is off, so there is
        // nothing to wait for and waiting would only delay the close.
        if (! Feature::for(null)->active(GenosSalesBonusFeature::class)) {
            return null;
        }

        $lastDay = $month->copy()->endOfMonth()->startOfDay();

        for ($attempt = 1; $attempt <= self::CUTOFF_WAIT_ATTEMPTS; $attempt++) {
            if (
                ! $this->status->hasRunInFlight('gsb.daily-cutoff')
                && $this->status->isPeriodComputed('gsb.daily-cutoff', $lastDay)
            ) {
                return null;
            }

            if ($attempt === 1) {
                $this->line("Waiting for the {$lastDay->format('d M Y')} daily cut-off to finish…");
            }

            Sleep::for(self::CUTOFF_WAIT_SECONDS)->seconds();
        }

        return sprintf(
            'The daily cut-off for %s has not finished after %d minutes. Every monthly engine reads the '
            ."month's cut-off results, so closing now would price %s against an incomplete month.\n"
            .'Run: php artisan gsb:daily-cutoff --date=%s, then re-run this close.',
            $lastDay->format('d M Y'),
            (int) round(self::CUTOFF_WAIT_ATTEMPTS * self::CUTOFF_WAIT_SECONDS / 60),
            $month->format('F Y'),
            $lastDay->toDateString(),
        );
    }

    /**
     * A month that stopped part-way must be loud: nothing else reads
     * EngineRun::STATUS_FAILED on its own, and the payout gate a week later will
     * refuse over this without knowing when it happened or who saw it.
     */
    private function abort(Carbon $month, string $stage, string $reason): int
    {
        $this->error($reason);

        // A preflight refusal is a decision, not a breakage: the close declined
        // to price the month against an incomplete last day. Recorded as failed
        // it reads on the Engine Runs page and in the health digest as a broken
        // engine to re-run, when what is owed is the missing cut-off. A step
        // that actually broke stays a failure — with its message.
        $context = app(EngineRunContext::class);

        if ($stage === 'preflight') {
            $context->noteSkipped($reason);
        } else {
            $context->noteFailed($reason);
        }

        Log::error('compensation.monthly_close.aborted', [
            'month' => $month->format('Y-m'),
            'stage' => $stage,
            'reason' => $reason,
        ]);

        try {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'compensation.monthly_close.aborted',
                'subject_type' => 'platform',
                'subject_id' => 0,
                'details' => [
                    'month' => $month->format('Y-m'),
                    'stage' => $stage,
                    'reason' => $reason,
                ],
            ]);
        } catch (Throwable $e) {
            // The audit row is the durable record, but losing it must not turn
            // a reported abort into an unreported crash.
            Log::error('compensation.monthly_close.audit_failed', [
                'month' => $month->format('Y-m'),
                'error' => $e->getMessage(),
            ]);
        }

        return self::FAILURE;
    }
}
