<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRunContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes exactly one engine for one period, with the attribution and the
 * overlap protection an admin-triggered run needs.
 *
 * Racing the scheduler is guarded in three layers:
 *   1. every engine is an idempotent re-runner, so a true race cannot
 *      double-credit anybody — this is the guarantee that actually matters;
 *   2. a cache lock serialises manual and chained runs against each other;
 *   3. a live `running` row (written by RecordEngineRun before the engine does
 *      any work) makes a manual run stand down when cron got there first — cron
 *      does not take our lock, so the lock alone would not see it.
 * Cron-versus-cron stays covered by the scheduler's own withoutOverlapping.
 */
final class EngineRunService
{
    private const LOCK_SECONDS = 7200;

    private const OUTPUT_LIMIT = 4000;

    public function __construct(
        private readonly EngineRunContext $context,
        private readonly EngineStatusService $status,
    ) {}

    public function runOne(
        EngineDefinition $engine,
        Carbon $period,
        string $trigger,
        ?int $actorId,
        string $chainId,
    ): EngineRun {
        $periodStart = $engine->periodStart($period);
        $lock = Cache::lock('compensation:engine:'.$engine->key, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->recordSkipped($engine, $periodStart, $trigger, $actorId, $chainId, [
                'reason' => 'already_running',
                'detail' => 'Another run of this engine holds the lock.',
            ]);
        }

        try {
            if ($this->status->hasRunInFlight($engine->key)) {
                return $this->recordSkipped($engine, $periodStart, $trigger, $actorId, $chainId, [
                    'reason' => 'already_running',
                    'detail' => 'The scheduler is already running this engine.',
                ]);
            }

            return $this->invoke($engine, $periodStart, $trigger, $actorId, $chainId);
        } finally {
            $this->context->reset();
            $lock->release();
        }
    }

    /**
     * Record a step that was decided against without invoking anything.
     *
     * @param  array<string, mixed>  $summary
     */
    public function recordSkipped(
        EngineDefinition $engine,
        Carbon $period,
        string $trigger,
        ?int $actorId,
        string $chainId,
        array $summary,
    ): EngineRun {
        return EngineRun::create([
            'engine_key' => $engine->key,
            'period_start' => $engine->periodStart($period),
            'status' => EngineRun::STATUS_SKIPPED,
            'trigger' => $trigger,
            'actor_id' => $actorId,
            'chain_id' => $chainId,
            'summary' => $summary,
            'started_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
        ]);
    }

    private function invoke(
        EngineDefinition $engine,
        Carbon $periodStart,
        string $trigger,
        ?int $actorId,
        string $chainId,
    ): EngineRun {
        // Bound so RecordEngineRun can stamp the row it is about to write with
        // the admin, the chain and the trigger.
        $this->context->attribute($trigger, $actorId, $chainId);

        $error = null;
        $exitCode = 1;
        $output = '';

        try {
            // The period is always passed explicitly: relying on the command's
            // own default would silently run a different period than the row
            // the listener recorded.
            $exitCode = Artisan::call($engine->commandSignature, [
                $engine->periodOption => $engine->formatPeriod($periodStart),
            ]);
            $output = Artisan::output();
        } catch (Throwable $e) {
            $error = $e->getMessage();

            Log::error('engine.run.failed', [
                'engine_key' => $engine->key,
                'period' => $engine->formatPeriod($periodStart),
                'trigger' => $trigger,
                'chain_id' => $chainId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->finalise($engine, $periodStart, $trigger, $actorId, $chainId, $exitCode, $output, $error);
    }

    /**
     * Attach the captured console output to the row the listener created — or
     * write the row outright if it did not (console events are not always
     * bridged, and bookkeeping failures must never lose a run entirely).
     */
    private function finalise(
        EngineDefinition $engine,
        Carbon $periodStart,
        string $trigger,
        ?int $actorId,
        string $chainId,
        int $exitCode,
        string $output,
        ?string $error,
    ): EngineRun {
        $succeeded = $error === null && $exitCode === 0;

        $summary = [
            'exit_code' => $exitCode,
            'output' => Str::limit(trim($output), self::OUTPUT_LIMIT),
        ];

        $runId = $this->context->runId();
        $run = $runId === null ? null : EngineRun::find($runId);

        if ($run === null) {
            return EngineRun::create([
                'engine_key' => $engine->key,
                'period_start' => $periodStart,
                'status' => $succeeded ? EngineRun::STATUS_SUCCEEDED : EngineRun::STATUS_FAILED,
                'trigger' => $trigger,
                'actor_id' => $actorId,
                'chain_id' => $chainId,
                'summary' => $summary,
                'error' => $error,
                'started_at' => Carbon::now(),
                'finished_at' => Carbon::now(),
            ]);
        }

        // A run the listener already closed as skipped (feature flag off) stays
        // skipped — it must never read as succeeded to the dependency resolver.
        $status = $run->status === EngineRun::STATUS_SKIPPED
            ? EngineRun::STATUS_SKIPPED
            : ($succeeded ? EngineRun::STATUS_SUCCEEDED : EngineRun::STATUS_FAILED);

        $run->forceFill([
            'status' => $status,
            'summary' => array_merge($run->summary ?? [], $summary),
            'error' => $error,
            'finished_at' => $run->finished_at ?? Carbon::now(),
        ])->save();

        return $run;
    }
}
