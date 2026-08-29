<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Services\GroupBvReversalService;
use App\Modules\Compensation\Services\GsbPersonalBvTopupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reverses a cancelled/refunded order's propagated group BV
 * ({@see GroupBvReversalService}). Queued with the same reliability
 * semantics as PropagateGroupBvJob; the service is idempotent, so a
 * retry after a crash never double-debits.
 */
final class ReverseGroupBvJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly int $orderId,
        private readonly ?int $actorUserId = null,
    ) {
        // Same queue as PropagateGroupBvJob — a reversal must never run
        // concurrently with the propagation it is undoing.
        $this->onQueue('compensation');
    }

    public function handle(GroupBvReversalService $reversal, GsbPersonalBvTopupService $topup): void
    {
        $order = Order::find($this->orderId);
        if ($order === null) {
            return;
        }

        $reversal->reverseForOrder($order, $this->actorUserId);
        $topup->reverseForOrder($this->orderId);
    }

    /**
     * All retries exhausted — the cancelled order's group BV is still sitting
     * in the upline's accumulators and the cut-off will over-pay unless ops
     * replays it. Loud alert, never a quiet failed_jobs row.
     */
    public function failed(?Throwable $exception): void
    {
        Log::critical('gsb.bv_reversal.permanently_failed', [
            'order_id' => $this->orderId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
