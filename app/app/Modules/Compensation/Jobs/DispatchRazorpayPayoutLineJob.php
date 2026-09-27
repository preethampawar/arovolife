<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\RazorpayPayoutDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send one line of an approved batch to RazorpayX.
 *
 * One job per line so a killed job strands one line, not the rest of the
 * batch — and that one line is recovered: {@see failed()} marks it `failed`,
 * which the auto-retry sweep and "Send again" pick up. Both are safe because
 * the dispatch service asks Razorpay for the line's reference before every
 * send. A line whose job never ran at all is re-queued by `payouts:reconcile`.
 *
 * On the `compensation` queue with tries 1 (ADR-0011): an automatic Laravel
 * retry of a job that moves money is a second transfer.
 */
final class DispatchRazorpayPayoutLineJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $lineItemId,
        private readonly ?int $actorId = null,
    ) {
        $this->onQueue('compensation');
    }

    public function handle(RazorpayPayoutDispatchService $dispatcher, PayoutGatewaySettings $settings): void
    {
        $line = PayoutLineItem::find($this->lineItemId);
        if ($line === null) {
            return;
        }

        // The gateway may have been switched to manual NEFT since the batch
        // was approved. Move no money through a route ops have turned off.
        if (! $settings->isRazorpay()) {
            Log::warning('Payout line dispatch skipped — gateway is no longer Razorpay', [
                'payout_line_item_id' => $line->id,
                'gateway' => $settings->gateway(),
            ]);

            return;
        }

        $batch = PayoutBatch::find($line->payout_batch_id);
        if ($batch === null || $batch->status !== PayoutBatch::STATUS_DISPATCHED) {
            Log::warning('Payout line dispatch skipped — batch is not in the dispatched state', [
                'payout_line_item_id' => $line->id,
                'payout_batch_id' => $line->payout_batch_id,
                'status' => $batch?->status,
            ]);

            return;
        }

        $dispatcher->dispatch($line, $this->actorId, RazorpayPayoutDispatchService::AUDIT_DISPATCHED);

        // Settles the batch when this was the last line waiting on anything.
        $dispatcher->refreshBatchStatus($batch->refresh());
    }

    public function failed(?Throwable $e): void
    {
        $line = PayoutLineItem::find($this->lineItemId);
        if ($line === null) {
            return;
        }

        $dispatcher = app(RazorpayPayoutDispatchService::class);

        if (! $dispatcher->holdInterrupted($line, $this->actorId)) {
            return;
        }

        Log::critical('RazorpayX payout line job interrupted', [
            'payout_line_item_id' => $line->id,
            'payout_batch_id' => $line->payout_batch_id,
            'error' => $e?->getMessage(),
        ]);

        $batch = PayoutBatch::find($line->payout_batch_id);
        if ($batch !== null) {
            $dispatcher->refreshBatchStatus($batch);
        }
    }
}
