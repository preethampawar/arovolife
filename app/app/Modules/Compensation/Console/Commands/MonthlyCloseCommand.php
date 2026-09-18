<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\FrozenPayoutGuard;
use App\Modules\Compensation\Support\MonthCutoffCoverage;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\ResolvesMonthOption;
use App\Modules\Compensation\Support\WorkerFreshness;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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
 * This close is itself a step: `compensation:monthly-run` invokes it on the
 * first night the month it closes has every one of its days cut off and the
 * ordering prerequisites are green. Nothing in routes/console.php fires it
 * directly. `compensation:rebuild-month` runs it with `--restart` after wiping
 * the month, which is the only path that deliberately recomputes a step this
 * command would otherwise resume past.
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
                            {--restart : Re-run every step, including ones already recorded as succeeded}';

    protected $description = 'Run the month\'s crediting engines in dependency order, resuming at the first step that has not succeeded';

    /**
     * The crediting sequence. Order is the contract: rank qualifications before
     * everything that reads them, and Fortune enrolment before the Fortune
     * payout that freezes the matrix.
     *
     * The first five are the CRITICAL PATH, and they have to stay serial. They
     * all credit through `WalletService::creditWithRepurchaseDeduction()`, and
     * the repurchase cap they share is summed non-atomically by
     * `repurchaseDeductionForMonthPaise()` — two of them crediting the same
     * distributor at once would each read the cap before the other's write and
     * deduct twice from a balance that can only pay once.
     *
     * ADC and Purchase Offers are last because they are NOT on that path. ADC
     * credits centre owners with no repurchase deduction (client decision,
     * 05 Sep 2026) and Purchase Offers moves no cash at all — it grants a
     * half-price entitlement and redeem points. Neither reads what the five
     * before it wrote, and Purchase Offers is the most expensive step of the
     * month, so with them at the end the money is credited before the close
     * spends its time on the work nobody is waiting for. It is also the only
     * shape in which either could later run beside the chain rather than inside
     * it.
     *
     * A separate list from {@see MonthlyEngineCompletionGate::ENGINE_KEYS} on
     * purpose: what the close RUNS and what a month OWES are different
     * questions, and the payout gate on the 8th still requires all seven.
     * MonthlyCloseCommandTest pins the two together.
     *
     * @var list<string>
     */
    private const STEPS = [
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
        'fortune.payout',
        'adc.bonus',
        'offers.monthly',
    ];

    /**
     * Abort stages that are a DECISION rather than a breakage — recorded
     * `skipped`, so neither the Engine Runs page nor the health digest reports
     * them for thirty days as an engine somebody should re-run. Every other
     * stage is an engine key, and an engine that exited non-zero really did
     * fail.
     *
     * @var list<string>
     */
    private const DECIDED_STAGES = ['preflight', 'frozen'];

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

        // Before the preflight, and NOT overridable by --force: once finance
        // has approved the month's payout batch, money has left on these
        // figures. Re-crediting the month would write a credit nothing will
        // ever sweep and a statement that no longer reconciles with what the
        // distributor was paid. `--force` is for an operator who accepts a
        // partial month; there is no operator who can accept paying one twice.
        //
        // creditingRefusal(), not refusal(): a batch that has been built and is
        // waiting for finance is not frozen — it can still be rebuilt — but
        // nothing may be credited into its month either, because the sweep is
        // over and the credit would never be picked up (A10).
        $frozen = FrozenPayoutGuard::creditingRefusal($month);

        if ($frozen !== null) {
            return $this->abort($month, 'frozen', $frozen);
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
            // No in-flight override: this close only ever runs a month that has
            // ended. Closing an unfinished month used to be a testing shortcut
            // (`--in-flight`), and it was the shortcut that made the engines
            // credit each other's repurchase deductions into the month they were
            // judging (staging, 14 Sep 2026). Projecting an unfinished month is
            // now the recompute's job: it fires this close on the 1st of the
            // following month, which is the only instant at which the month's
            // figures are real.
            $exitCode = Artisan::call($definition->commandSignature, [
                $definition->periodOption => $definition->formatPeriod($period),
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
     * Three checks, each once for the whole close rather than once per engine.
     *
     * 1. Stale worker — a process running pre-deploy code credits the wrong
     *    money with no error anywhere (see WorkerFreshness).
     * 2. A daily cut-off in flight. The cut-off commits per distributor, so a
     *    close that starts beside one prices the month while its last day is
     *    still being written.
     * 3. The month's cut-offs, all of them. Every monthly engine reads the
     *    month's cut-off results and then FREEZES what it computed, so a month
     *    closed three days short is a month permanently priced short — a re-run
     *    reuses the frozen pool rather than repairing it. "All of them" means
     *    every day the month OWED one ({@see MonthCutoffCoverage}): days before
     *    the platform had a single distributor have nobody to match and nothing
     *    to carry forward, and demanding them is what would make a launch month
     *    impossible to close for ever.
     *
     * Checks 2 and 3 used to be a single ten-minute POLL: as a separate process
     * fired on a clock offset, this close had no way to observe that the
     * cut-off had finished, so it waited and then aborted.
     * `compensation:nightly-run` made the waiting unnecessary — the cut-off is
     * the step immediately before this close in the same chain — but not the
     * asking. This command is still runnable by hand, and the chain's own
     * "month not closed" message recommends exactly that, so the assertions
     * stay; only the `Sleep` is gone. Two queries, and always satisfied when
     * the chain is the caller.
     *
     * `--force` overrides all three.
     */
    private function preflight(Carbon $month): ?string
    {
        $stale = WorkerFreshness::staleReason();

        if ($stale !== null) {
            return $stale;
        }

        // The cut-off cannot compute anything while GSB is off, so the month
        // owes none and demanding them would deadlock every close.
        if (! Feature::for(null)->active(GenosSalesBonusFeature::class)) {
            return null;
        }

        if ($this->status->hasRunInFlight('gsb.daily-cutoff')) {
            return sprintf(
                'A GSB daily cut-off is running right now. It commits per distributor, so closing the month beside '
                ."it would price %s against a day still being written.\nWait for it to finish — the Engine Runs "
                .'page shows when it does — and run this close again.',
                $month->format('F Y'),
            );
        }

        $coverage = MonthCutoffCoverage::for($month, $this->status);

        return $coverage->isWhole() ? null : $coverage->refusal();
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
        // to credit money from a process that may be running pre-deploy code.
        // Recorded as failed it reads on the Engine Runs page and in the health
        // digest as a broken engine to re-run, when what is owed is a worker
        // restart. A frozen — or built-and-awaiting-approval — month is the
        // same kind of answer, and a stronger one: nothing about it can be
        // re-run at all. A step that actually broke stays a failure, with its
        // message.
        $context = app(EngineRunContext::class);

        if (in_array($stage, self::DECIDED_STAGES, true)) {
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
