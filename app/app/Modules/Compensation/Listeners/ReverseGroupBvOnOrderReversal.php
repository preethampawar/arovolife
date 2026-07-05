<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Jobs\ReverseGroupBvJob;
use App\Modules\Returns\Events\OrderRefundApproved;

/**
 * Counterpart of {@see PropagateGroupBvOnOrderPaid}: when an order is
 * cancelled (pre-shipment) or its refund is approved (cooling-off / buyback,
 * ADR-0009), the group BV it propagated up the placement tree is reversed
 * from the distributors it was originally credited to (KP Q8).
 */
final class ReverseGroupBvOnOrderReversal
{
    public function handle(OrderStatusChanged|OrderRefundApproved $event): void
    {
        if ($event instanceof OrderStatusChanged && $event->newStatus !== Order::STATUS_CANCELLED) {
            return;
        }

        ReverseGroupBvJob::dispatch(orderId: $event->orderId);
    }
}
