<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use App\Modules\Compensation\Support\EngineRunContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one period rebuild off the request, on the `compensation` queue.
 *
 * The developer's confirm on the Engine Runs page dispatches this rather than
 * rebuilding inline: a month's wipe plus its seven crediting engines is minutes
 * of work, and it must never sit in front of a distributor's OTP or order mail
 * on the shared worker (ADR-0011).
 *
 * `tries = 1`, as for every other engine job: half a rebuild has already deleted
 * rows, and an automatic replay would start a second wipe beside the first. The
 * recovery is the developer pressing rebuild again, which is safe by
 * construction — every attempt wipes the previous attempt's rows before it
 * re-runs.
 */
final class RebuildPeriodJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** {@see class docblock} — a rebuild is never replayed automatically. */
    public int $tries = 1;

    /**
     * An hour for a night or a batch; two for a month, whose re-run is the
     * seven crediting engines end to end.
     *
     * The database queue driver's `retry_after` must exceed this on every
     * environment (config/queue.php defaults to 90 seconds), or the driver
     * releases the job back mid-rebuild and a second worker starts wiping.
     */
    public int $timeout;

    public function __construct(
        public readonly string $kind,
        public readonly string $period,
        public readonly int $actorId,
        public readonly string $chainId,
    ) {
        $this->onQueue('compensation');
        $this->timeout = $this->kind === RebuildKind::Month->value ? 7200 : 3600;
    }

    public function handle(): void
    {
        $kind = RebuildKind::from($this->kind);

        Log::info('compensation.rebuild.job_started', [
            'kind' => $this->kind,
            'period' => $this->period,
            'actor_id' => $this->actorId,
            'chain_id' => $this->chainId,
        ]);

        // ATTRIBUTE THE RUN BEFORE IT STARTS. Without this every `engine_runs`
        // row the rebuild writes is stamped `console` with a null actor, because
        // that is the default in a queue worker where Auth::id() is null — and a
        // run that deleted derived money rows would be recorded as a cron job.
        $context = app(EngineRunContext::class);
        $context->attribute(EngineRun::TRIGGER_MANUAL, $this->actorId, $this->chainId);

        try {
            $exitCode = Artisan::call($kind->signature(), [
                $kind->periodOption() => $this->period,
                '--actor' => (string) $this->actorId,
                // Nobody is at a prompt on a queue worker; the confirm was given
                // on the page, against a fingerprint the controller re-checked.
                '--yes' => true,
            ]);
        } finally {
            // Defensive, not load-bearing on the queue: Laravel flushes `scoped`
            // container instances between jobs. It DOES matter on the `sync`
            // driver and in tests, where an attributed context would outlive
            // this call.
            $context->reset();
        }

        $logContext = [
            'kind' => $this->kind,
            'period' => $this->period,
            'actor_id' => $this->actorId,
            'chain_id' => $this->chainId,
            'exit_code' => $exitCode,
            'output' => Str::limit(trim(Artisan::output()), 4000),
        ];

        if ($exitCode === 0) {
            Log::info('compensation.rebuild.job_succeeded', $logContext);

            return;
        }

        Log::error('compensation.rebuild.job_failed', $logContext);
    }

    /**
     * A killed rebuild must not leave its runs reading `running` for ever.
     *
     * `tries = 1` and a long timeout mean this fires on a genuine kill — OOM,
     * the worker restarted mid-month — and the rows the rebuild had open would
     * otherwise stay `running` with nothing closing them, which reads to the
     * next preflight as a rebuild still in flight and refuses every later one.
     */
    public function failed(?Throwable $exception): void
    {
        EngineRun::query()
            ->where('chain_id', $this->chainId)
            ->where('status', EngineRun::STATUS_RUNNING)
            ->update([
                'status' => EngineRun::STATUS_FAILED,
                'error' => 'Rebuild job died: '.($exception?->getMessage() ?? 'timeout'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        Log::error('compensation.rebuild.job_crashed', [
            'kind' => $this->kind,
            'period' => $this->period,
            'actor_id' => $this->actorId,
            'chain_id' => $this->chainId,
            'exception' => $exception === null ? null : $exception::class,
            'error' => $exception?->getMessage(),
        ]);
    }
}
