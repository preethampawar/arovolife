<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBuildFailure;
use App\Modules\Compensation\Services\PayoutBuildFailureRecorder;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Support\EngineRunContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Retry one distributor the payout sweep skipped — the Retry button on
 * Payouts → Build failures ({@see PayoutService::retryBuildFailure()}).
 *
 * On the `compensation` queue with tries 1: building a payout line sweeps the
 * ledger, which is never done inside a web request, and a re-queued attempt
 * must be a fresh click, not an automatic second build. The controller claims
 * the row (`retrying`) before dispatching, so one click is one job.
 */
final class RetryPayoutBuildFailureJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** The sweep lock waits up to 120 s, then the build itself. */
    public int $timeout = 300;

    public function __construct(
        private readonly int $failureId,
        private readonly ?int $actorId,
    ) {
        $this->onQueue('compensation');
    }

    public function handle(PayoutService $payouts, PayoutBuildFailureRecorder $recorder, EngineRunContext $context): void
    {
        // Attribute the work to the admin who clicked: the audit rows the
        // retry writes (and the batch's maker, if it had none) name them.
        $context->attribute(EngineRun::TRIGGER_MANUAL, $this->actorId, null);

        try {
            $payouts->retryBuildFailure($this->failureId, $this->actorId);
        } catch (LockTimeoutException) {
            $this->reopen($recorder, 'Not retried: another payout run was holding the batch. Try again in a few minutes.');
        } finally {
            $context->reset();
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->reopen(app(PayoutBuildFailureRecorder::class), 'The retry itself stopped before it finished: '.($e?->getMessage() ?? 'unknown error'));
    }

    private function reopen(PayoutBuildFailureRecorder $recorder, string $note): void
    {
        $failure = PayoutBuildFailure::query()->find($this->failureId);

        if ($failure !== null) {
            $recorder->reopen($failure, $note);
        }
    }
}
