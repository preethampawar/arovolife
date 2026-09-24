<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Orders;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use App\Modules\Fulfilment\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shipped orders the courier says are coming back, lost or damaged.
 *
 * The courier's word arrives through the tracking webhook and is re-read from
 * its API before it is recorded (`shipments.courier_status`). Nothing moves
 * the order on its own: whether to re-send, refund or chase the courier is a
 * staff decision, so it is surfaced here the same day.
 */
final class CourierExceptionProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'orders.courier_exception';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Parcels returning, lost or damaged';
    }

    public function description(): string
    {
        return 'The courier reports these parcels as returning to us, lost or damaged. Decide whether to re-send, refund or raise a claim.';
    }

    public function permission(): string
    {
        return 'commerce.order.manage';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function slaHours(): int
    {
        return 24;
    }

    public function subjectType(): string
    {
        return 'order';
    }

    public function targetRoute(): string
    {
        return 'admin.commerce.orders.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->with('shipment:id,order_id,courier_status,status')
            ->orderBy('orders.shipped_at')
            ->limit($limit)
            ->get(['orders.id', 'orders.order_no', 'orders.shipped_at', 'orders.total_paise'])
            ->map(function (Order $order): ActionItem {
                $said = $order->shipment->courier_status ?? 'returned';
                $dueAt = $this->dueAt($order->shipped_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: 'Courier says: '.$said,
                    occurredAt: $order->shipped_at ?? now(),
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['order' => $order->id]),
                    meta: [
                        'order_no' => (string) $order->order_no,
                        'total_paise' => (int) $order->total_paise,
                        'courier_status' => $said,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<Order> */
    private function baseQuery(): Builder
    {
        $query = Order::query()
            ->where('orders.status', Order::STATUS_SHIPPED)
            ->whereHas('shipment', fn (Builder $shipment) => $shipment->where('gateway', '!=', Shipment::GATEWAY_MANUAL)->scopes('withCourierException'));

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
