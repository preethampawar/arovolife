<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Services\RazorpayRefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Send one refund intent to Razorpay. Real money leaves here, so it is
 * dispatched afterCommit only, retried with a long backoff on transport
 * failure, and idempotent at the gateway through X-Refund-Idempotency —
 * a retry after a timeout cannot create a second refund. A permanent
 * failure lands the intent on the unsettled-refunds worklist with the
 * gateway's reason; it is never silently dropped.
 */
final class SendRazorpayRefundJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(private readonly int $refundIntentId)
    {
        $this->onQueue('default');
    }

    public function handle(RazorpayRefundService $refunds): void
    {
        $refund = RefundIntent::find($this->refundIntentId);
        if ($refund === null) {
            return;
        }

        $refunds->send($refund);
    }

    public function failed(?Throwable $e): void
    {
        $refund = RefundIntent::find($this->refundIntentId);
        if ($refund === null) {
            return;
        }

        app(RazorpayRefundService::class)->markFailed(
            $refund,
            'send_failed',
            $e?->getMessage() ?? 'unknown',
        );
    }
}
