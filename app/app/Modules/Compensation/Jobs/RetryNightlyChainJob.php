<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Console\Commands\NightlyRunCommand;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Re-runs one night of the compensation chain, from the step that failed.
 *
 * Not a second recovery mechanism — the same command the scheduler fires, on
 * the same night, resuming as it would have. It is not `--restart`, which an
 * operator can still reach from the CLI when a step genuinely has to be
 * recomputed.
 *
 * WHAT MAKES REPEAT CLICKS SAFE is worth stating precisely, because the obvious
 * answer is only half of it. The chain's resume — skip a step already recorded
 * SUCCEEDED for the period — does the work for the MONTHLY close, whose steps
 * are dated a month that has ended. It does nothing for the nightly steps:
 * `hasSucceededRun()` requires `started_at >= periodEndsAt()`, which a step
 * dated tonight can never satisfy, so those are re-invoked every time. They are
 * safe because the ENGINES are idempotent — the cut-off skips a distributor who
 * already has a row for the date, the evaluation re-derives the same verdict —
 * not because the chain skipped them. Anything that later trusts the resume to
 * be the guard would be removing the wrong safety net.
 *
 * Nothing is validated here beyond the date. Every reason a retry should be
 * refused — a standing recompute projection, a worker running pre-deploy code —
 * is already decided by {@see NightlyRunCommand::preflight()}, which refuses
 * with a message written for an operator. Duplicating those checks here would
 * mean two places to keep true and a second, worse wording of each.
 */
final class RetryNightlyChainJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retries, for the reason RunEngineChainJob gives: half a chain has
     * already credited real wallets, and an automatic replay would write a
     * second confusing set of run rows for one admin click. Clicking again is
     * the intended recovery — the chain resumes, so nothing is paid twice.
     */
    public int $tries = 1;

    /** A full night, backfill included, on the heaviest date of the month. */
    public int $timeout = 3600;

    public function __construct(
        public readonly string $night,
        public readonly ?int $actorId,
        public readonly string $chainId,
    ) {
        // Minutes of work, and it must never sit in front of a distributor's
        // OTP or order mail on the shared worker (ADR-0011).
        $this->onQueue('compensation');
    }

    public function handle(): void
    {
        Log::info('compensation.nightly_chain.retry_started', [
            'night' => $this->night,
            'actor_id' => $this->actorId,
        ]);

        // The registry owns the signature, as it does for every other engine —
        // the string lives in one place and EngineRegistryTest pins it.
        $signature = EngineRegistry::get(EngineStatusService::CHAIN_KEY)->commandSignature;

        // ATTRIBUTE THE RUN BEFORE IT STARTS. Without this every `engine_runs`
        // row the retry writes is stamped `console` with a null actor, because
        // that is the default in a queue worker where Auth::id() is null — an
        // admin-initiated run that credits wallets would be recorded as a cron
        // job. The run log is the durable record of who moved money, and a row
        // that names nobody is the one thing it must never contain.
        $runContext = app(EngineRunContext::class);
        $runContext->attribute(EngineRun::TRIGGER_MANUAL, $this->actorId, $this->chainId);

        try {
            // `--without-payouts`: never a payout batch from this path. The
            // payout engines are scheduler-only because `finance.record` both
            // fires them and approves the batch they create, and a retry of a
            // night that IS today would otherwise reach them through the
            // command's `isToday()` rule — making one admin maker and checker
            // for a real payout. The crediting engines still run, so the income
            // still lands in wallets; only the sweep stays with the scheduler,
            // which builds it on the next night still dated its own Tuesday.
            $exitCode = Artisan::call($signature, [
                '--date' => $this->night,
                '--without-payouts' => true,
            ]);
        } finally {
            // Defensive, not load-bearing on the queue: Laravel flushes
            // `scoped` container instances between jobs, so a leak into the
            // next job cannot happen on the database driver. It DOES matter on
            // the `sync` driver and in tests, where the container is not
            // flushed and an attributed context would outlive this call.
            $runContext->reset();
        }

        // Logged at the level the outcome deserves: a retry that fails again is
        // the second failure of the same night and the operator is now waiting
        // on a developer, not on another click.
        $logContext = [
            'night' => $this->night,
            'actor_id' => $this->actorId,
            'chain_id' => $this->chainId,
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
        ];

        if ($exitCode === 0) {
            Log::info('compensation.nightly_chain.retry_succeeded', $logContext);

            return;
        }

        Log::error('compensation.nightly_chain.retry_failed', $logContext);
    }
}
