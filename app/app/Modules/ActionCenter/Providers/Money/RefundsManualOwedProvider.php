<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Support\RefundWorklist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A refund owed on an order with no gateway payment behind it — cash on
 * delivery, or a payment recorded outside the platform (plan §4, Money) —
 * either approved on a return or arising from cancelling a paid order.
 * Built on `RefundWorklist::manualRefundsQuery()`, so it matches
 * `manualRefunds()` exactly: the obligation is in the ledger and the only
 * discharge is finance recording the NEFT it made.
 */
final class RefundsManualOwedProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'refunds.manual_owed';
    }

    public function group(): string
    {
        return ActionGroup::MONEY;
    }

    public function label(): string
    {
        return 'Manual refunds owed';
    }

    public function description(): string
    {
        return 'Refunds approved, or owed on cancelled paid orders, with no gateway intent — cash on delivery or a manual NEFT. Record the settlement from the refunds screen.';
    }

    public function permission(): string
    {
        return 'finance.record';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function subjectType(): string
    {
        return 'order';
    }

    public function targetRoute(): string
    {
        return 'admin.payments.refunds';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderByRaw('COALESCE(orders.refund_approved_at, orders.cancelled_at, orders.updated_at)')
            ->limit($limit)
            ->get(['orders.id', 'orders.order_no', 'orders.status', 'orders.refund_approved_at', 'orders.cancelled_at', 'orders.total_paise'])
            ->map(function (Order $order): ActionItem {
                $cancelled = $order->status === Order::STATUS_CANCELLED;
                $since = $cancelled ? $order->cancelled_at : $order->refund_approved_at;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: ($cancelled ? 'Cancelled after payment ' : 'Approved ').$this->ageLabel($since).' ago, no gateway refund — settle manually',
                    occurredAt: $since ?? now(),
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute()),
                    meta: [
                        'order_no' => (string) $order->order_no,
                        'total_paise' => (int) $order->total_paise,
                    ],
                );
            })
            ->values();
    }

    /** @return Builder<Order> */
    private function baseQuery(): Builder
    {
        $query = RefundWorklist::manualRefundsQuery();

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
