<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\NightlyRunAlert;
use App\Modules\Compensation\Support\WorkerFreshness;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The nightly chain: one process, one lock, every engine the night is due.
 *
 * It replaces five independent `Schedule::command` entries that were sequenced
 * only by clock offsets — repurchase at 00:05, the GSB cut-off at 00:10, the
 * monthly close at 00:20, the weekly payout at Tuesday 03:00, the monthly payout
 * close on the 8th at 04:00. `withoutOverlapping()` is per-command and does NOT
 * serialise across commands, so nothing held that order: an evaluation that
 * overran five minutes, failed, or never started still let the cut-off proceed
 * on yesterday's repurchase verdicts, and a forfeited day credited (or a
 * fulfilled day forfeited) by mistake is never corrected afterwards.
 *
 * The offsets also bought their safety with dead time. The monthly close used to
 * poll for the closed month's last cut-off for up to ten minutes because, as a
 * separate process, it had no way to know the cut-off had finished; in the chain
 * it is the step before, so the question cannot be asked. Every engine now fires
 * the moment its inputs are ready rather than at a minute someone guessed.
 *
 * WHAT RUNS ON A GIVEN NIGHT, in this order:
 *
 *   1. `repurchase:evaluate --date=<tonight>`      — every night.
 *   2. `gsb:daily-cutoff --date=<yesterday>`       — every night, preceded by
 *                                                    any night that was missed.
 *   3. `compensation:monthly-close --month=<the month that just ended>`
 *                                                  — the moment that month's
 *                                                    last day has been cut off.
 *   4. `gsb:weekly-payout --date=<tonight>`        — on Tuesdays, preceded by
 *                                                    any Tuesday with no batch.
 *   5. `compensation:monthly-payout-close --month=<the month before last>`
 *                                                  — on the 8th, and after it
 *                                                    while no batch exists.
 *
 * A PERIOD IS JUDGED ONLY AFTER IT HAS ENDED. Step 1 is dated tonight precisely
 * so it has seen the whole of every day the chain is about to cut off, which is
 * what step 2's own guard demands; steps 3 and 5 are given closed months.
 * Nothing here ever prices a period that is still in flight.
 *
 * RESUME, NEVER RESTART — the same contract as {@see MonthlyCloseCommand}. Every
 * step freezes economics or moves money, and a re-run of the chain skips every
 * step already recorded SUCCEEDED for its period and starts at the first that is
 * not. `--restart` exists for the rare case where a step genuinely has to be
 * recomputed.
 *
 * The repurchase evaluation is the exception, deliberately: it is dated TONIGHT,
 * and a succeeded run never counts as done while its period is still in flight —
 * that guard is what stops a mid-period recompute from convincing a later close
 * the work was finished. So a re-run fires it again, which is what is wanted:
 * re-evaluating cycles re-reads the state as it stands now.
 *
 * Each step is invoked through Artisan::call, so RecordEngineRun writes that
 * step's own `engine_runs` row from the console events — nested invocations
 * included. This command's own row is written the same way, and step 3 nests a
 * second orchestrator inside it.
 */
final class NightlyRunCommand extends Command
{
    protected $signature = 'compensation:nightly-run
                            {--date= : The night to run (YYYY-MM-DD, defaults to tonight)}
                            {--force : Run the steps even when the preflight refuses}
                            {--restart : Re-run every step, including ones already recorded as succeeded}
                            {--with-payouts : Include the payout steps when --date names a night other than tonight}';

    protected $description = 'Run tonight\'s compensation engines in dependency order, resuming at the first step that has not succeeded';

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

    /**
     * How far back to look for a Tuesday whose weekly batch was never built.
     *
     * Earnings are never lost to a missed Tuesday — the weekly sweep takes
     * every unpaid entry earned on or before its window end, so the next batch
     * collects the previous week's too. What is lost is the WEEK: distributors
     * wait until the following Tuesday. Four weeks is far enough to cover the
     * outage the cut-off backfill can heal and no further.
     */
    private const MAX_BACKFILL_WEEKS = 4;

    public function __construct(private readonly EngineStatusService $status)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $night = $this->resolveNight();

        if ($night === null) {
            return self::FAILURE;
        }

        $this->info("Nightly run — {$night->format('D, d M Y')}");

        $refusal = $this->preflight();

        if ($refusal !== null) {
            if (! $this->option('force')) {
                return $this->abort($night, 'preflight', $refusal);
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
                return $this->abort($night, $key, sprintf(
                    "%s exited %d for %s. The remaining %d step(s) did not run.\n"
                    ."Re-run tonight's chain once it is fixed; the steps that already succeeded will be skipped:\n"
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

    /**
     * Two checks, once for the whole chain rather than once per engine.
     *
     * 1. The recompute gate. The scheduler entry already carries
     *    `->when($compensationEnginesMayRun)`, but this command is also typed by
     *    hand — it is what the abort message tells an operator to run — and on a
     *    dev or staging environment holding a projection the derived state is
     *    already ahead of the scheduler. It fails closed, exactly as the
     *    scheduler filter does, and is inert in production where the recompute
     *    gate is shut.
     * 2. Stale worker — a process running pre-deploy code credits the wrong
     *    money with no error anywhere (see WorkerFreshness).
     */
    private function preflight(): ?string
    {
        if (! app(RecomputeState::class)->schedulerEnginesAllowed()) {
            return 'A recompute projection is standing (or a replay is in flight) on this environment, so the '
                ."engines are paused: tonight's figures would be computed on top of rows that belong to a clock "
                ."that has not arrived.\nWait for the 23:30 reset, or run "
                .'php artisan compensation:recompute-all --horizon=now to return this environment to '
                .'production-faithful first.';
        }

        return WorkerFreshness::staleReason();
    }

    /**
     * The night's chain, in dependency order.
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
        // every day this chain is about to cut off.
        $steps = [['repurchase.evaluate', $night->copy()]];

        foreach ($this->cutoffDays($night) as $day) {
            // Never tonight: the cut-off prices a day that has ended.
            $steps[] = ['gsb.daily-cutoff', $day];

            // The month ends the instant its last day is cut off — no clock
            // offset, nothing to poll for. On a backfilled night that is not
            // necessarily last night.
            if ($day->isLastOfMonth() && $this->monthIsWhole($night, $day, $steps)) {
                $steps[] = ['compensation.monthly-close', $day->copy()->startOfMonth()];
            }
        }

        if (! $this->payoutsAllowed($night)) {
            return $steps;
        }

        foreach ($this->weeklyPayoutDays($night) as $batchDay) {
            $steps[] = ['gsb.weekly-payout', $batchDay];
        }

        foreach ($this->monthlyPayoutMonths($night) as $creditingMonth) {
            $steps[] = ['compensation.monthly-payout-close', $creditingMonth];
        }

        return $steps;
    }

    /**
     * May this run build payout batches?
     *
     * Tonight's chain always may. A chain asked to replay a PAST night is a
     * different thing: it is what an operator types to catch up missed
     * cut-offs, and under the old schedule that meant typing `gsb:daily-cutoff`,
     * from which a payout batch was unreachable. Building one for a date that
     * has gone by is a decision, so it takes `--with-payouts` to say so. The
     * batch would still land `pending` and still need a second person to
     * approve it — this keeps the surprise out, not the money.
     */
    private function payoutsAllowed(Carbon $night): bool
    {
        return $night->isToday() || (bool) $this->option('with-payouts');
    }

    /**
     * Every day this chain owes a cut-off, oldest first.
     *
     * Ordinarily that is yesterday and nothing else. But a night can be LOST:
     * `withoutOverlapping()` skips the whole entry when the previous night is
     * still running, the container can be down at 00:05, the scheduler can be
     * paused. Under the old five-entry schedule a lost night was lost for good
     * — the next night's cut-off was hard-wired to `--date=yesterday`, so the
     * missed day was never computed, and a day that is never cut off is a day
     * nobody is credited for, with no record that it happened.
     *
     * So the chain works forward from the newest day it can PROVE was cut off
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
                        'No GSB cut-off is proven complete in the %d days before %s, so the chain healed last night '
                        .'only. Every earlier day is uncomputed: nobody has been credited for it, and no engine '
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

    /**
     * The crediting months this chain owes a monthly payout batch, oldest first.
     *
     * The 8th pays out the month that closed on the 1st: a week in which a bad
     * month can still be caught before it reaches a bank. Two things can leave
     * that batch unbuilt — the chain did not run on the 8th, or it did and the
     * crediting month was incomplete — and under the old fixed
     * `monthlyOn(8, '04:00')` entry either one pushed the payment to the
     * following month.
     *
     * So: every night from the 8th onward re-queues the current month's batch
     * while none exists, and a PREVIOUS month whose batch was never built is
     * retried too — but only once {@see MonthlyEngineCompletionGate} says it
     * would actually be paid. Queuing a month the gate refuses would abort the
     * chain and file a failed run every night for a month nobody can fix
     * tonight; the missing crediting engines are already reported as missing in
     * their own right.
     *
     * Earnings are not at stake either way: the monthly sweep takes every
     * unpaid credit earned in that month or before, so a later batch collects
     * an older month's too. What is at stake is the month distributors are paid
     * in.
     *
     * @return list<Carbon>
     */
    private function monthlyPayoutMonths(Carbon $night): array
    {
        $months = [];
        $thisBatchMonth = $night->copy()->startOfMonth();
        $lastBatchMonth = $thisBatchMonth->copy()->subMonthNoOverflow();

        if (! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $lastBatchMonth)) {
            $olderCrediting = $lastBatchMonth->copy()->subMonthNoOverflow();

            if (MonthlyEngineCompletionGate::blockingFailure($olderCrediting) === null) {
                $this->warn(sprintf(
                    "The %s payout batch was never built. Building it tonight rather than leaving %s's credits to "
                    .'next month.',
                    $lastBatchMonth->format('F Y'),
                    $olderCrediting->format('F Y'),
                ));

                $months[] = $olderCrediting;
            }
        }

        if ($night->day >= 8 && ! $this->status->payoutBatchExists(PayoutBatch::TYPE_MONTHLY, $thisBatchMonth)) {
            $months[] = $thisBatchMonth->copy()->subMonthNoOverflow();
        }

        return $months;
    }

    /**
     * The Tuesdays this chain owes a weekly batch, oldest first.
     *
     * NOT only on a Tuesday. A batch missed on its own night — the chain was
     * skipped, the night aborted, the container was down — is retried the very
     * next night, still DATED that Tuesday, so the earning week it pays is
     * unchanged: Wednesday through that Tuesday, exactly as
     * {@see PayoutBatch::weeklyEarningWindow()} defines it. Waiting for the
     * following Tuesday would cost distributors a week for an outage that had
     * nothing to do with them. (Their earnings are never lost either way — the
     * weekly sweep takes every unpaid entry earned on or before its window end,
     * so a later batch would collect the missed week too. What is at stake is
     * when they are paid, not whether.)
     *
     * Proven from the batch itself, not from the run log: a batch is the thing
     * that exists. It also keeps the chain from re-invoking a batch finance has
     * already APPROVED — the runner returns such a batch untouched and the
     * command then reports FAILURE, which would abort the whole chain over a
     * batch that is not merely fine but signed off.
     *
     * @return list<Carbon>
     */
    private function weeklyPayoutDays(Carbon $night): array
    {
        $latest = $night->dayOfWeekIso === Carbon::TUESDAY
            ? $night->copy()
            : $night->copy()->previous(Carbon::TUESDAY);

        $frontier = null;

        // Newest first: the first Tuesday that HAS a batch is the frontier.
        // Before it is history the chain does not reopen, and on an environment
        // that has never built a weekly batch there is no frontier and nothing
        // to backfill — a fresh install must not invent a month of batches.
        for ($weeksBack = 0; $weeksBack <= self::MAX_BACKFILL_WEEKS; $weeksBack++) {
            $tuesday = $latest->copy()->subWeeks($weeksBack);

            if ($this->status->payoutBatchExists(PayoutBatch::TYPE_WEEKLY, $tuesday)) {
                $frontier = $tuesday;

                break;
            }
        }

        if ($frontier === null) {
            return $night->dayOfWeekIso === Carbon::TUESDAY ? [$night->copy()] : [];
        }

        $days = [];

        for ($tuesday = $frontier->copy()->addWeek(); $tuesday->lessThanOrEqualTo($latest); $tuesday->addWeek()) {
            if (! $this->status->payoutBatchExists(PayoutBatch::TYPE_WEEKLY, $tuesday)) {
                $days[] = $tuesday->copy();
            }
        }

        if ($days !== [] && $night->dayOfWeekIso !== Carbon::TUESDAY) {
            $this->warn(sprintf(
                '%d Tuesday batch(es) were never built: %s. Building them tonight rather than waiting for the next '
                .'Tuesday.',
                count($days),
                implode(', ', array_map(static fn (Carbon $day): string => $day->toDateString(), $days)),
            ));
        }

        return $days;
    }

    /**
     * Is every day of this month's cut-off accounted for — already proven
     * complete, or queued earlier in tonight's own chain?
     *
     * A month may not be closed on a partial month. Every monthly engine prices
     * the month from its cut-off results and then FREEZES what it computed, so
     * a month closed with three days missing is a month permanently priced
     * short, and re-running the close afterwards reuses the frozen pool rather
     * than repairing it. A backfill that starts mid-month is exactly the shape
     * that produces this.
     *
     * Refusing is the safe half. The loud half matters just as much: nobody
     * receives Growth Booster, Rank Bonus, Fortune or ADC for a month that is
     * never closed, so the refusal is recorded rather than printed.
     *
     * @param  list<array{0: string, 1: Carbon}>  $steps  The chain so far.
     */
    private function monthIsWhole(Carbon $night, Carbon $lastDay, array $steps): bool
    {
        $monthStart = $lastDay->copy()->startOfMonth();

        $covered = $this->status->completedCutoffDatesBetween($monthStart, $lastDay);

        foreach ($steps as [$key, $period]) {
            if ($key === 'gsb.daily-cutoff' && $period->betweenIncluded($monthStart, $lastDay)) {
                $covered[] = $period->toDateString();
            }
        }

        $missing = $lastDay->day - count(array_unique($covered));

        if ($missing <= 0) {
            return true;
        }

        $reason = sprintf(
            '%s was not closed: %d of its %d days have no completed cut-off, and every monthly engine freezes what '
            ."it computes — a month closed short stays short.\nCut the missing days off first "
            .'(php artisan gsb:daily-cutoff --date=<day>), then run '
            .'php artisan compensation:monthly-close --month=%s',
            $lastDay->format('F Y'),
            $missing,
            $lastDay->day,
            $lastDay->format('Y-m'),
        );

        $this->warn($reason);

        NightlyRunAlert::monthCloseDeferred($night, $monthStart, $missing, $reason);

        return false;
    }

    /**
     * Invoke one step. Exceptions are caught rather than allowed to escape:
     * escaping would abort the chain before it could record why, and the
     * remaining steps would look as though they had never been reached for no
     * stated reason.
     */
    private function runStep(EngineDefinition $definition, Carbon $period): int
    {
        try {
            $exitCode = Artisan::call($definition->commandSignature, [
                $definition->periodOption => $definition->formatPeriod($period),
            ]);
        } catch (Throwable $e) {
            Log::error('compensation.nightly_run.step_crashed', [
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
     * Tonight, or the explicit `--date`. A future night is refused: every step
     * judges a period that has ended, and there is no such period ahead of the
     * clock.
     *
     * IST explicitly, as the five entries this replaced each did in their own
     * `--date` argument. The scheduler pins `->timezone('Asia/Kolkata')`, and an
     * APP_TIMEZONE that drifted from it would move the whole chain by a day with
     * no error anywhere — the shape of F125.
     */
    private function resolveNight(): ?Carbon
    {
        $raw = $this->option('date');

        if (! is_string($raw) || trim($raw) === '') {
            return Carbon::today('Asia/Kolkata');
        }

        try {
            $night = EngineRegistry::get('compensation.nightly-run')->parsePeriod($raw);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }

        if ($night->greaterThan(Carbon::today('Asia/Kolkata'))) {
            $this->error("The chain cannot run for {$night->toDateString()}: that night has not arrived.");

            return null;
        }

        return $night;
    }

    /**
     * A night that stopped part-way must be loud: nothing else reads
     * EngineRun::STATUS_FAILED on its own, and the steps that did not run are
     * invisible unless the chain says so.
     */
    private function abort(Carbon $night, string $stage, string $reason): int
    {
        $this->error($reason);

        // A preflight refusal is a decision, not a breakage — the chain declined
        // to credit money from a paused environment or a process that may be
        // running pre-deploy code. Recorded as failed it reads on the Engine
        // Runs page and in the health digest as a broken engine to re-run, when
        // what is owed is a worker restart or a reset. A step that actually
        // broke stays a failure, with its message.
        $context = app(EngineRunContext::class);

        if ($stage === 'preflight') {
            $context->noteSkipped($reason);
        } else {
            $context->noteFailed($reason);
        }

        Log::error('compensation.nightly_run.aborted', [
            'date' => $night->toDateString(),
            'stage' => $stage,
            'reason' => $reason,
        ]);

        try {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'compensation.nightly_run.aborted',
                'subject_type' => 'platform',
                'subject_id' => 0,
                'details' => [
                    'date' => $night->toDateString(),
                    'stage' => $stage,
                    'reason' => $reason,
                ],
            ]);
        } catch (Throwable $e) {
            // The audit row is the durable record, but losing it must not turn
            // a reported abort into an unreported crash.
            Log::error('compensation.nightly_run.audit_failed', [
                'date' => $night->toDateString(),
                'error' => $e->getMessage(),
            ]);
        }

        return self::FAILURE;
    }
}
