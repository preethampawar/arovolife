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
 * Money is in and the goods have not been picked (plan §4, Orders).
 *
 * The reference implementation every later provider copies: one indexed
 * condition, a `count()` that hydrates nothing, `items()` oldest-first, and a
 * deep link to the admin order screen that already fixes it.
 */
final class PaidNotPackedProvider extends AbstractProvider
{
    public function __construct(private readonly ActionCenterSettings $settings) {}

    public function key(): string
    {
        return 'orders.paid_not_packed';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Paid orders not packed';
    }

    public function description(): string
    {
        return 'Paid orders whose goods have not been picked within the pack SLA. Pack them from the order screen.';
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
        return $this->settings->packSlaHours();
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
            ->orderBy('orders.paid_at')
            ->limit($limit)
            ->get(['orders.id', 'orders.order_no', 'orders.paid_at', 'orders.total_paise', 'orders.warehouse_code'])
            ->map(function (Order $order): ActionItem {
                $dueAt = $this->dueAt($order->paid_at);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: 'Paid '.$this->ageLabel($order->paid_at).' ago, not packed',
                    occurredAt: $order->paid_at ?? now(),
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
            ->where('orders.status', Order::STATUS_PAID)
            ->whereNull('orders.packed_at')
            ->whereNotNull('orders.paid_at')
            ->where('orders.paid_at', '<=', $this->slaCutoff());

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
