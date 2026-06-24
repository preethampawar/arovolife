<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Jobs\PropagateGroupBvJob;
use Illuminate\Support\Carbon;

final class PropagateGroupBvOnOrderPaid
{
    public function handle(OrderStatusChanged $event): void
    {
        if ($event->newStatus !== Order::STATUS_PAID) {
            return;
        }

        $order = Order::find($event->orderId);
        if ($order === null || $order->attributed_distributor_id === null) {
            return;
        }

        // Sum the BV accrued for this order from the BV ledger.
        $bvPaise = (int) BvLedgerEntry::where('order_id', $event->orderId)
            ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
            ->sum('bv_paise');

        if ($bvPaise <= 0) {
            return;
        }

        PropagateGroupBvJob::dispatch(
            orderId: $event->orderId,
            distributorId: $order->attributed_distributor_id,
            bvPaise: $bvPaise,
            date: Carbon::now()->toDateString(),
        );
    }
}
