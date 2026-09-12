<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Orders;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Support\InvoiceGapWorklist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A paid order with no GST invoice (plan §4, Orders). Statutory: CGST §31
 * requires the invoice, so a manager cannot silence this one (plan §5).
 * Reuses `InvoiceGapWorklist`'s query rather than re-deriving the gap.
 */
final class InvoiceMissingProvider extends AbstractProvider
{
    public function __construct(private readonly InvoiceGapWorklist $worklist) {}

    public function key(): string
    {
        return 'orders.invoice_missing';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Paid orders missing an invoice';
    }

    public function description(): string
    {
        return 'Paid orders that hold no GST invoice. Generate one from the order screen.';
    }

    public function permission(): string
    {
        return 'commerce.order.manage';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function statutory(): bool
    {
        return true;
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
            ->get(['orders.id', 'orders.order_no', 'orders.paid_at', 'orders.total_paise'])
            ->map(function (Order $order): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: 'Paid '.$this->ageLabel($order->paid_at).' ago, no invoice issued',
                    occurredAt: $order->paid_at ?? now(),
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['order' => $order->id]),
                    meta: [
                        'order_no' => (string) $order->order_no,
                        'total_paise' => (int) $order->total_paise,
                    ],
                );
            })
            ->values();
    }

    /**
     * The worklist's own query (plan §2, reused rather than re-derived), with
     * the base class's snooze exclusion applied on principle (plan §3.1.2) —
     * statutory items are refused a snooze row in the first place (plan §5).
     *
     * @return Builder<Order>
     */
    private function baseQuery(): Builder
    {
        $query = $this->worklist->query();

        $this->excludeSnoozed($query->getQuery(), 'orders.id');

        return $query;
    }
}
