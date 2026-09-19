<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands\Concerns;

use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\RunPrerequisites;
use App\Modules\Compensation\Support\WorkerFreshness;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The four things every date-typed orchestrator does identically: resolve the
 * night, refuse to start on a paused environment or a stale worker, invoke one
 * step, and record an abort.
 *
 * Extracted when the nightly chain became three runs (ADR-0016). Three copies
 * of a preflight is three places for one of them to stop refusing a standing
 * projection — and the run that kept the old copy would be the one crediting
 * money on top of rows that belong to a clock that has not arrived.
 *
 * {@see auditPrefix()} is what each run keeps of its own: the nightly run's
 * audit action stays `compensation.nightly_run.aborted` to the byte, because
 * the runbook and its tests read that string.
 */
trait OrchestratesEngineSteps
{
    /**
     * The prefix for this run's log lines and audit actions — e.g.
     * `compensation.weekly_run`, giving `compensation.weekly_run.aborted`.
     */
    abstract protected function auditPrefix(): string;

    /**
     * Tonight, or the explicit `--date`. A future night is refused: every step
     * judges a period that has ended, and there is no such period ahead of the
     * clock.
     *
     * IST explicitly. The scheduler pins `->timezone('Asia/Kolkata')`, and an
     * APP_TIMEZONE that drifted from it would move the whole run by a day with
     * no error anywhere — the shape of F125.
     */
    protected function resolveNight(string $registryKey): ?Carbon
    {
        $definition = EngineRegistry::get($registryKey);
        $raw = $this->option('date');

        if (! is_string($raw) || trim($raw) === '') {
            return Carbon::today('Asia/Kolkata');
        }

        try {
            $night = $definition->parsePeriod($raw);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }

        if ($night->greaterThan(Carbon::today('Asia/Kolkata'))) {
            $this->error(sprintf(
                'The %s cannot run for %s: that night has not arrived.',
                $definition->label,
                $night->toDateString(),
            ));

            return null;
        }

        return $night;
    }

    /**
     * Three checks, once for the whole run rather than once per engine, and all
     * of them BEFORE the run touches an engine or writes anything derived.
     *
     * 1. The recompute gate. These commands are also typed by hand — they are
     *    what an abort message tells an operator to run — and on a dev or
     *    staging environment holding a projection the derived state is already
     *    ahead of the scheduler. It fails closed and is inert in production,
     *    where the recompute gate is shut.
     * 2. Stale worker — a process running pre-deploy code credits the wrong
     *    money with no error anywhere (see WorkerFreshness).
     * 3. A developer rebuild in flight. It writes the same rolling per-
     *    distributor stores this run does, and no mutex covers both
     *    ({@see RunPrerequisites::rebuildInFlightRefusal()}).
     *
     * Each refusal becomes a `skipped` run row carrying the reason, through
     * {@see abortRun()} — never a silent non-start.
     */
    protected function orchestratorPreflight(Carbon $night, string $registryKey): ?string
    {
        if (! app(RecomputeState::class)->schedulerEnginesAllowed()) {
            return 'A recompute projection is standing (or a replay is in flight) on this environment, so the '
                ."engines are paused: tonight's figures would be computed on top of rows that belong to a clock "
                ."that has not arrived.\nWait for the 23:30 reset, or run "
                .'php artisan compensation:recompute-all --horizon=now to return this environment to '
                .'production-faithful first.';
        }

        if (($stale = WorkerFreshness::staleReason()) !== null) {
            return $stale;
        }

        return app(RunPrerequisites::class)->rebuildInFlightRefusal($night, $registryKey);
    }

    /**
     * Invoke one step. Exceptions are caught rather than allowed to escape:
     * escaping would abort the run before it could record why, and the
     * remaining steps would look as though they had never been reached for no
     * stated reason.
     *
     * @param  array<string, bool|string>  $extraOptions
     */
    protected function runStep(EngineDefinition $definition, Carbon $period, array $extraOptions = []): int
    {
        try {
            $exitCode = Artisan::call($definition->commandSignature, [
                $definition->periodOption => $definition->formatPeriod($period),
                ...$extraOptions,
            ]);
        } catch (Throwable $e) {
            Log::error($this->auditPrefix().'.step_crashed', [
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
     * A run that stopped part-way must be loud: nothing else reads
     * EngineRun::STATUS_FAILED on its own, and the steps that did not run are
     * invisible unless the run says so.
     *
     * A preflight refusal is a DECISION, not a breakage — the run declined to
     * credit money from a paused environment or a process that may be running
     * pre-deploy code. Recorded as failed it reads on the Engine Runs page and
     * in the health digest as a broken engine to re-run, when what is owed is a
     * worker restart or a reset. A step that actually broke stays a failure,
     * with its message.
     */
    protected function abortRun(Carbon $night, string $stage, string $reason): int
    {
        $this->error($reason);

        $context = app(EngineRunContext::class);

        if ($stage === 'preflight') {
            $context->noteSkipped($reason);
        } else {
            $context->noteFailed($reason);
        }

        Log::error($this->auditPrefix().'.aborted', [
            'date' => $night->toDateString(),
            'stage' => $stage,
            'reason' => $reason,
        ]);

        try {
            AuditLog::create([
                'actor_id' => null,
                'action' => $this->auditPrefix().'.aborted',
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
            Log::error($this->auditPrefix().'.audit_failed', [
                'date' => $night->toDateString(),
                'error' => $e->getMessage(),
            ]);
        }

        return self::FAILURE;
    }
}
