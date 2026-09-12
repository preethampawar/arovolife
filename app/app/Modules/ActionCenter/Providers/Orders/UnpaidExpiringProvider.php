<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Orders;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Support\PaymentSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Online orders placed but never paid, closing in on the expiry window that
 * `orders:expire-unpaid` sweeps on its own schedule (plan §4, Orders). This
 * is a backlog view of the same condition the sweeper acts on, not a second
 * clock — it reuses `PaymentSettings::unpaidExpiryMinutes()` so the two never
 * disagree.
 */
final class UnpaidExpiringProvider extends AbstractProvider
{
    public function __construct(private readonly PaymentSettings $settings) {}

    public function key(): string
    {
        return 'orders.unpaid_expiring';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Unpaid orders nearing expiry';
    }

    public function description(): string
    {
        return 'Placed orders nobody has paid for yet, past the payment-expiry window the automatic sweeper will act on next.';
    }

    public function permission(): string
    {
        return 'commerce.order.manage';
    }

    public function severity(): string
    {
        return Severity::INFO;
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
            ->orderBy('orders.placed_at')
            ->limit($limit)
            ->get(['orders.id', 'orders.order_no', 'orders.placed_at', 'orders.total_paise', 'orders.warehouse_code'])
            ->map(function (Order $order): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: 'Placed '.$this->ageLabel($order->placed_at).' ago, still unpaid',
                    occurredAt: $order->placed_at ?? now(),
                    dueAt: null,
                    severity: $this->severity(),
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
        $cutoff = CarbonImmutable::now()->subMinutes($this->settings->unpaidExpiryMinutes());

        $query = Order::query()
            ->where('orders.payment_method', Order::PAYMENT_ONLINE)
            ->where('orders.status', Order::STATUS_PLACED)
            ->whereNull('orders.paid_at')
            ->where('orders.placed_at', '<=', $cutoff);

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
