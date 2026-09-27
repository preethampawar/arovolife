<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\RazorpayPayoutDispatchService;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queue one {@see DispatchRazorpayPayoutLineJob} per payable line of an
 * approved batch. This job only queues; the line job sends.
 *
 * On the `compensation` queue, which runs on exactly one worker with tries 1
 * (ADR-0011): two workers racing the same batch would be two bank transfers
 * per distributor, and an automatic Laravel retry would be the same thing one
 * level up. Recovery is explicit instead — a line whose job was killed is
 * marked `failed` for the retry sweep, and a line whose job never ran is
 * re-queued by `payouts:reconcile`. The dispatch service's fresh-read and
 * payout-id guards make a duplicate queue entry harmless.
 */
final class DispatchRazorpayPayoutsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly int $payoutBatchId,
        private readonly ?int $actorId = null,
    ) {
        $this->onQueue('compensation');
    }

    public function handle(RazorpayPayoutDispatchService $dispatcher, PayoutGatewaySettings $settings): void
    {
        $batch = PayoutBatch::find($this->payoutBatchId);

        if ($batch === null) {
            return;
        }

        // The gateway may have been switched to manual NEFT between approval
        // and this job running. Do nothing rather than move money through a
        // route ops have turned off.
        if (! $settings->isRazorpay()) {
            Log::warning('Payout dispatch skipped — gateway is no longer Razorpay', [
                'payout_batch_id' => $batch->id,
                'gateway' => $settings->gateway(),
            ]);

            return;
        }

        if ($batch->status !== PayoutBatch::STATUS_DISPATCHED) {
            Log::warning('Payout dispatch skipped — batch is not in the dispatched state', [
                'payout_batch_id' => $batch->id,
                'status' => $batch->status,
            ]);

            return;
        }

        $queued = 0;

        PayoutLineItem::where('payout_batch_id', $batch->id)
            ->where('status', PayoutLineItem::STATUS_PENDING)
            ->whereNull('razorpay_payout_id')
            ->where('net_transferred_paise', '>', 0)
            ->chunkById(500, function ($lines) use (&$queued): void {
                foreach ($lines as $line) {
                    DispatchRazorpayPayoutLineJob::dispatch((int) $line->id, $this->actorId);
                    $queued++;
                }
            });

        AuditLog::create([
            'actor_id' => $this->actorId,
            'action' => 'payout.batch.dispatched',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            'details' => [
                'batch_type' => $batch->batch_type,
                'batch_date' => $batch->batch_date->toDateString(),
                'line_items_queued' => $queued,
            ],
            'ip' => null,
        ]);

        // Nothing to send (every line held or below the minimum): settle now,
        // since no line job will run to do it.
        if ($queued === 0) {
            $dispatcher->refreshBatchStatus($batch->refresh());
        }
    }
}
