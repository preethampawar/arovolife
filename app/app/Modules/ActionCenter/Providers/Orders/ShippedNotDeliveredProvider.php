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
 * Shipped but sitting with the carrier past the delivery chase window
 * (plan §4, Orders).
 */
final class ShippedNotDeliveredProvider extends AbstractProvider
{
    public function __construct(private readonly ActionCenterSettings $settings) {}

    public function key(): string
    {
        return 'orders.shipped_not_delivered';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Shipped orders not delivered';
    }

    public function description(): string
    {
        return 'Orders shipped but not confirmed delivered within the chase window. Follow up with the carrier.';
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
        return $this->settings->deliveryChaseDays() * 24;
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
            ->orderBy('orders.shipped_at')
            ->limit($limit)
            ->get(['orders.id', 'orders.order_no', 'orders.shipped_at', 'orders.total_paise', 'orders.warehouse_code'])
            ->map(function (Order $order): ActionItem {
                $dueAt = $this->dueAt($order->shipped_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: 'Shipped '.$this->ageLabel($order->shipped_at).' ago, not delivered',
                    occurredAt: $order->shipped_at ?? now(),
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
            ->where('orders.status', Order::STATUS_SHIPPED)
            ->whereNotNull('orders.shipped_at')
            ->where('orders.shipped_at', '<=', $this->slaCutoff());

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
