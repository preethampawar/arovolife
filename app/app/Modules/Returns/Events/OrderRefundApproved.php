<?php

declare(strict_types=1);

namespace App\Modules\Returns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after RefundOrder::execute() completes — the refund is approved,
 * ledger reversed, BV reversed, and the order is in `refund_approved`.
 *
 * Phase 3: wire the payment gateway refund settlement listener here.
 * Phase 4: wire a commission clawback listener (ADR-0009 forward dependency).
 */
final class OrderRefundApproved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly int $returnRequestId,
        public readonly string $reason,
        public readonly int $netRefundPaise,
        public readonly string $idempotencyKey,
    ) {}
}
