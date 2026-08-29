<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\DTOs\EngineChainStep;
use App\Modules\Compensation\Services\EngineChainResolver;
use App\Modules\Compensation\Services\EngineRunService;
use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs one admin-requested engine and everything it needs first.
 *
 * Queued rather than synchronous: a Growth Booster run for a month with no
 * cut-offs recorded expands into a daily cut-off per day across every active
 * distributor — minutes of work, far past an HTTP request. Progress is visible
 * by refreshing the Engine Runs page, because RecordEngineRun writes each
 * `running` row before its engine starts working.
 */
final class RunEngineChainJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retries. Half a chain has already moved real money into wallets; an
     * automatic replay would produce a confusing second set of run rows for the
     * same admin click. Re-triggering from the page is the intended recovery,
     * and every engine is idempotent, so nothing is lost by making it manual.
     */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $engineKey,
        public readonly string $period,
        public readonly ?int $actorId,
        public readonly string $chainId,
    ) {
        // Minutes of work. Isolated onto `compensation` so it cannot sit in
        // front of a distributor's OTP or order mail on the shared worker.
        $this->onQueue('compensation');
    }

    public function handle(EngineChainResolver $resolver, EngineRunService $runner): void
    {
        $engine = EngineRegistry::get($this->engineKey);

        // Resolved here, not at dispatch time: by the time the worker picks the
        // job up, the scheduler may have filled some of the gaps itself.
        $plan = $resolver->resolve($this->engineKey, $engine->parsePeriod($this->period));

        Log::info('engine.chain.started', [
            'engine_key' => $this->engineKey,
            'period' => $this->period,
            'chain_id' => $this->chainId,
            'steps' => count($plan->steps),
        ]);

        foreach ($plan->steps as $index => $step) {
            $run = $runner->runOne(
                $step->engine,
                $step->period,
                $step->isTarget() ? EngineRun::TRIGGER_MANUAL : EngineRun::TRIGGER_DEPENDENCY,
                $this->actorId,
                $this->chainId,
            );

            if ($run->status === EngineRun::STATUS_SUCCEEDED) {
                continue;
            }

            // A flag-off prerequisite computes nothing and blocks nothing — the
            // resolver already leaves those out, but if a flag flips between
            // resolution and execution the chain must not abort over it.
            if (
                $run->status === EngineRun::STATUS_SKIPPED
                && ($run->summary['reason'] ?? null) === 'feature_flag_off'
            ) {
                continue;
            }

            // A prerequisite that did not succeed — failed, or skipped because
            // the scheduler holds it — means the target would run on incomplete
            // input. Freezing a month's pool economics against a half-filled
            // month is far worse than doing nothing, so the chain stops here.
            $this->abort($plan->steps, $index, $step, $run, $runner);

            return;
        }

        Log::info('engine.chain.completed', [
            'engine_key' => $this->engineKey,
            'period' => $this->period,
            'chain_id' => $this->chainId,
        ]);
    }

    /**
     * The worker killed the job (timeout) or it crashed outright. The abort is
     * recorded so the run log explains the half-applied chain instead of
     * leaving a stale `running` row as the only trace.
     */
    public function failed(?\Throwable $exception): void
    {
        EngineRun::query()
            ->where('chain_id', $this->chainId)
            ->where('status', EngineRun::STATUS_RUNNING)
            ->update([
                'status' => EngineRun::STATUS_FAILED,
                'error' => 'Chain job died: '.($exception?->getMessage() ?? 'timeout'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        Log::error('engine.chain.crashed', [
            'engine_key' => $this->engineKey,
            'period' => $this->period,
            'chain_id' => $this->chainId,
            'exception' => $exception === null ? null : $exception::class,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Mark every step after the failure as skipped so the run log explains
     * itself, and stop. The job does not throw: a chain that aborted cleanly is
     * a recorded outcome, not an infrastructure failure to retry.
     *
     * @param  list<EngineChainStep>  $steps
     */
    private function abort(
        array $steps,
        int $failedIndex,
        EngineChainStep $failedStep,
        EngineRun $failedRun,
        EngineRunService $runner,
    ): void {
        $reason = $failedRun->status === EngineRun::STATUS_FAILED ? 'upstream_failed' : 'upstream_skipped';

        foreach (array_slice($steps, $failedIndex + 1) as $remaining) {
            $runner->recordSkipped(
                $remaining->engine,
                $remaining->period,
                $remaining->isTarget() ? EngineRun::TRIGGER_MANUAL : EngineRun::TRIGGER_DEPENDENCY,
                $this->actorId,
                $this->chainId,
                [
                    'reason' => $reason,
                    'failed_step' => $failedStep->id(),
                ],
            );
        }

        Log::error('engine.chain.aborted', [
            'engine_key' => $this->engineKey,
            'period' => $this->period,
            'chain_id' => $this->chainId,
            'failed_step' => $failedStep->id(),
            'failed_status' => $failedRun->status,
            'remaining_steps' => count($steps) - $failedIndex - 1,
        ]);
    }
}
