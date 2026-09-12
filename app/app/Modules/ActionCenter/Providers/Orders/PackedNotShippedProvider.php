<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Orders;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Packed and ready to ship, but nobody has shipped it (plan §4, Orders).
 */
final class PackedNotShippedProvider extends AbstractProvider
{
    public function __construct(private readonly ActionCenterSettings $settings) {}

    public function key(): string
    {
        return 'orders.packed_not_shipped';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Packed orders not shipped';
    }

    public function description(): string
    {
        return 'Orders packed and ready to ship that have not been handed to the carrier within the ship SLA.';
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
        return $this->settings->shipSlaHours();
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
            ->orderBy('orders.packed_at')
            ->limit($limit)
            ->get(['orders.id', 'orders.order_no', 'orders.packed_at', 'orders.total_paise', 'orders.warehouse_code'])
            ->map(function (Order $order): ActionItem {
                $dueAt = $this->dueAt($order->packed_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: 'Packed '.$this->ageLabel($order->packed_at).' ago, not shipped',
                    occurredAt: $order->packed_at ?? now(),
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['order' => $order->id]),
                    meta: [
                        'order_no' => (string) $order->order_no,
                        'total_paise' => (int) $order->total_paise,
                        'warehouse_code' => $order->warehouse_code,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<Order> */
    private function baseQuery(): Builder
    {
        $query = Order::query()
            ->where('orders.status', Order::STATUS_READY_TO_SHIP)
            ->whereNotNull('orders.packed_at')
            ->where('orders.packed_at', '<=', $this->slaCutoff());

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
