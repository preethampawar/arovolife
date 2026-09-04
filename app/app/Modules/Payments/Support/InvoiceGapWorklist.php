<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Paid orders that hold no GST invoice.
 *
 * The invoice is issued after the payment confirmation commits, so a failure
 * there cannot roll a captured payment back — which means it can leave a
 * paid order without its consecutive invoice (CGST §31). That must be a
 * worklist item finance can act on, never only a log line.
 */
final class InvoiceGapWorklist
{
    /** @return Builder<Order> */
    public function query(): Builder
    {
        return Order::query()
            ->whereNotNull('paid_at')
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('invoices')->whereColumn('invoices.order_id', 'orders.id'));
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /** @return Collection<int, Order> oldest first */
    public function orders(int $limit = 100): Collection
    {
        return $this->query()->with('customer')->orderBy('paid_at')->limit($limit)->get();
    }
}
