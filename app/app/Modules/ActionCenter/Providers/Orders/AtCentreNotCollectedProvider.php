<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Orders;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A parcel waiting at an Arete Development Centre longer than the published
 * dwell limit (`fulfilment.max_dwell_days`, D4). Staff arrange its return with
 * the centre; nothing here changes the order on its own.
 */
final class AtCentreNotCollectedProvider extends AbstractProvider
{
    public function __construct(private readonly FulfilmentSettings $fulfilment) {}

    public function key(): string
    {
        return 'orders.at_centre_not_collected';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Parcels not collected from a centre';
    }

    public function description(): string
    {
        return 'Collection orders waiting at a centre past the dwell limit. Arrange the return with the centre.';
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
        return $this->fulfilment->maxDwellDays() * 24;
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
            ->join('shipments', 'shipments.order_id', '=', 'orders.id')
            ->orderBy('shipments.at_centre_at')
            ->limit($limit)
            ->get(['orders.id', 'orders.order_no', 'orders.arete_center_id', 'shipments.at_centre_at'])
            ->map(function (Order $order): ActionItem {
                $arrivedAt = Carbon::parse((string) $order->getAttribute('at_centre_at'));
                $dueAt = $this->dueAt($arrivedAt);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: 'At the centre '.$this->ageLabel($arrivedAt).', not collected. Arrange the return',
                    occurredAt: $arrivedAt,
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['order' => $order->id]),
                    meta: [
                        'order_no' => (string) $order->order_no,
                        'arete_center_id' => $order->arete_center_id,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<Order> */
    private function baseQuery(): Builder
    {
        $query = Order::query()
            ->where('orders.status', Order::STATUS_AWAITING_COLLECTION)
            ->whereHas('shipment', fn (Builder $shipment) => $shipment
                ->whereNotNull('at_centre_at')
                ->where('at_centre_at', '<=', $this->slaCutoff()));

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
