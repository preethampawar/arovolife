<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Money;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * A refund approved for an order with no gateway payment behind it — cash on
 * delivery, or a payment recorded outside the platform (plan §4, Money).
 * Mirrors `RefundWorklist::manualRefunds()` exactly: the obligation is in the
 * ledger and the only discharge is finance recording the NEFT it made.
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
        return 'Refunds approved with no gateway intent — cash on delivery or a manual NEFT. Record the settlement from the refunds screen.';
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
            ->orderBy('orders.refund_approved_at')
            ->limit($limit)
            ->get(['orders.id', 'orders.order_no', 'orders.refund_approved_at', 'orders.total_paise'])
            ->map(function (Order $order): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: 'Approved '.$this->ageLabel($order->refund_approved_at).' ago, no gateway refund — settle manually',
                    occurredAt: $order->refund_approved_at ?? now(),
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
        $query = Order::query()
            ->where('orders.status', Order::STATUS_REFUND_APPROVED)
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('refund_intents')->whereColumn('refund_intents.order_id', 'orders.id'));

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
