<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Orders;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A cancelled or refunded order whose picked stock was never put back (plan
 * §4, Orders). Cancelling a packed order should post a `sale_reversal` for
 * every `sale_out` it made (`OrderFulfilmentService::unpackForCancel()`); if
 * that step was skipped or failed partway, the stock is gone from the shelf
 * but still shown as sold. The critical ceiling reflects that this is a
 * stock-accuracy problem, not a fulfilment SLA — there is no clock.
 *
 * The netting is the exact grouping `unpackForCancel()` uses: per
 * (reference_id, batch), `sale_out` is negative and `sale_reversal` positive,
 * so a group whose sum is still negative has stock outstanding.
 */
final class RestockNotReconciledProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'orders.restock_not_reconciled';
    }

    public function group(): string
    {
        return ActionGroup::ORDERS;
    }

    public function label(): string
    {
        return 'Cancelled orders not restocked';
    }

    public function description(): string
    {
        return 'Cancelled or refunded orders whose picked stock has not been fully reversed. Adjust stock to reconcile.';
    }

    public function permission(): string
    {
        return 'commerce.order.manage';
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
        return 'admin.commerce.orders.show';
    }

    public function count(): int
    {
        return $this->unreconciledOrderIds()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        $ids = $this->unreconciledOrderIds()->take($limit);

        if ($ids->isEmpty()) {
            return collect();
        }

        return Order::query()
            ->whereIn('id', $ids)
            ->orderBy('cancelled_at')
            ->get(['id', 'order_no', 'cancelled_at', 'refunded_at', 'total_paise', 'warehouse_code', 'status'])
            ->sortBy(fn (Order $order) => $ids->search($order->id))
            ->map(function (Order $order): ActionItem {
                $occurredAt = $order->cancelled_at ?? $order->refunded_at ?? now();

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $order->id,
                    title: (string) $order->order_no,
                    subtitle: ucfirst((string) $order->status).' '.$this->ageLabel($occurredAt).' ago, stock not reversed',
                    occurredAt: $occurredAt,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['order' => $order->id]),
                    meta: [
                        'order_no' => (string) $order->order_no,
                        'status' => (string) $order->status,
                        'warehouse_code' => $order->warehouse_code,
                    ],
                );
            })
            ->values();
    }

    /**
     * Order ids, oldest-cancelled-first, whose `sale_out`/`sale_reversal`
     * movements still net negative for at least one (item, batch) group.
     * Excludes snoozed subjects by hand, since the grouping query below
     * cannot be composed with the base `whereNotExists` helper.
     *
     * @return Collection<int, int>
     */
    private function unreconciledOrderIds(): Collection
    {
        $rows = DB::table('stock_movements as sm')
            ->join('order_items as oi', 'oi.id', '=', 'sm.reference_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('sm.reference_type', OrderFulfilmentService::REFERENCE_ORDER_ITEM)
            ->whereIn('sm.type', [StockMovement::TYPE_SALE_OUT, StockMovement::TYPE_SALE_REVERSAL])
            ->whereIn('o.status', [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('action_center_snoozes')
                    ->whereColumn('action_center_snoozes.subject_id', 'o.id')
                    ->where('action_center_snoozes.subject_type', $this->subjectType())
                    ->where('action_center_snoozes.action_key', $this->key())
                    ->where('action_center_snoozes.snoozed_until', '>', now());
            })
            ->selectRaw('o.id as order_id, o.cancelled_at as cancelled_at, sm.reference_id, sm.stock_batch_id, SUM(sm.qty) as net_qty')
            ->groupBy('o.id', 'o.cancelled_at', 'sm.reference_id', 'sm.stock_batch_id')
            ->havingRaw('SUM(sm.qty) < 0')
            ->orderBy('cancelled_at')
            ->get();

        return $rows->pluck('order_id')->unique()->values()->map(fn (mixed $id): int => (int) $id);
    }
}
