<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\RazorpayPayoutDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Re-send one failed line item to RazorpayX — from the admin Retry button or
 * from the nightly auto-retry sweep.
 *
 * On the `compensation` queue with tries 1, for the same reason as
 * {@see DispatchRazorpayPayoutsJob}: a re-queued job is a second transfer.
 *
 * `retry_count` is incremented BEFORE the call, not after. It is the attempt
 * number the gateway's idempotency key is derived from, so bumping it first
 * is what makes this attempt distinguishable from the one that failed — and
 * what guarantees a crash between the increment and the response cannot come
 * back on the next run with the previous attempt's key.
 */
final class RetryRazorpayPayoutJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        private readonly int $lineItemId,
        private readonly ?int $actorId = null,
    ) {
        $this->onQueue('compensation');
    }

    public function handle(RazorpayPayoutDispatchService $dispatcher, PayoutGatewaySettings $settings): void
    {
        if (! $settings->isRazorpay()) {
            Log::warning('Payout retry skipped — gateway is no longer Razorpay', [
                'payout_line_item_id' => $this->lineItemId,
                'gateway' => $settings->gateway(),
            ]);

            return;
        }

        $line = PayoutLineItem::find($this->lineItemId);

        if ($line === null) {
            return;
        }

        // Only a failed line is retryable. A held line (no bank account, KYC
        // pending, undecryptable ciphertext) needs ops to fix the underlying
        // record first; a transferred one must never be sent twice.
        if ($line->status !== PayoutLineItem::STATUS_FAILED) {
            return;
        }

        if ($line->razorpay_payout_id !== null && $line->razorpay_payout_id !== '') {
            return;
        }

        if ($line->retry_count >= $settings->maxRetries()) {
            Log::warning('Payout retry skipped — retry limit reached', [
                'payout_line_item_id' => $line->id,
                'retry_count' => $line->retry_count,
                'max_retries' => $settings->maxRetries(),
            ]);

            return;
        }

        $line->forceFill([
            'retry_count' => $line->retry_count + 1,
            'last_retried_at' => now(),
            // Clear the previous reason so a success does not leave a stale
            // failure hanging on the row.
            'failure_reason' => null,
        ])->save();

        $dispatcher->dispatch($line, $this->actorId, RazorpayPayoutDispatchService::AUDIT_RETRY_DISPATCHED);

        $batch = $line->payoutBatch()->first();
        if ($batch !== null) {
            $dispatcher->refreshBatchStatus($batch);
        }
    }
}
