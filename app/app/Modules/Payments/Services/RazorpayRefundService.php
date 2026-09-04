<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\PaymentEvent;

/**
 * Sending refunds to Razorpay and settling them on the gateway's word.
 * Built out in Chunk 5; for now refund webhooks are stored and acknowledged
 * so a `refund.processed` that arrives early is never lost.
 */
final class RazorpayRefundService
{
    public function applyWebhook(PaymentEvent $event): string
    {
        return 'recorded: '.$event->event_type;
    }

    /**
     * Ask the gateway about every refund sent but not yet confirmed processed.
     *
     * @return array{checked: int, settled: int, failed: int}
     */
    public function reconcileOutstanding(): array
    {
        return ['checked' => 0, 'settled' => 0, 'failed' => 0];
    }
}
