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
 * Hand every payable line item of an approved batch to RazorpayX.
 *
 * On the `compensation` queue, which runs on exactly one worker with tries 1
 * (ADR-0011): two workers racing the same batch would be two bank transfers
 * per distributor, and an automatic Laravel retry of a half-finished run
 * would be the same thing one level up. Recovery is explicit instead —
 * the per-item `razorpay_payout_id` guard makes a manual re-dispatch safe.
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

        $lines = PayoutLineItem::where('payout_batch_id', $batch->id)
            ->where('status', PayoutLineItem::STATUS_PENDING)
            ->whereNull('razorpay_payout_id')
            ->where('net_transferred_paise', '>', 0)
            ->orderBy('id')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($lines as $line) {
            // One distributor's failure is recorded on their own line item and
            // swallowed inside the dispatcher, so the rest of the batch is
            // still paid.
            $dispatcher->dispatch($line, $this->actorId, RazorpayPayoutDispatchService::AUDIT_DISPATCHED)
                ? $sent++
                : $failed++;
        }

        AuditLog::create([
            'actor_id' => $this->actorId,
            'action' => 'payout.batch.dispatched',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            'details' => [
                'batch_type' => $batch->batch_type,
                'batch_date' => $batch->batch_date->toDateString(),
                'line_items_attempted' => $lines->count(),
                'sent' => $sent,
                'failed' => $failed,
            ],
            'ip' => null,
        ]);

        // Settles the batch immediately when nothing is left waiting on a
        // webhook (every line failed outright, or the gateway confirmed them
        // synchronously); otherwise it stays `dispatched`.
        $dispatcher->refreshBatchStatus($batch->refresh());
    }
}
