<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Support\SalesScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single owner of "which orders count as a sale, and for how much"
 * (plan D5). The sales register, the profit report and the distributor's own
 * report all read from here; none of them writes its own order query.
 *
 * ## Net sale value is not `orders.total_paise`
 *
 * `total_paise` is cash payable. It is net of redeemed points AND net of a
 * repurchase-wallet credit that is not even stored on the order. Summing it
 * gives you money received, not revenue earned, and the two differ by every
 * discount ever granted.
 *
 * Revenue is `subtotal_paise - gst_paise` — the ex-GST value of the goods,
 * which is exactly what OrderStateMachine posts to `revenue.sales` at ship
 * time. Discounts, points and wallet credit are contra-revenue and are
 * reported separately rather than netted into the top line.
 *
 * ## Why the default date basis is the ship date
 *
 * Cost is stamped onto stock movements when an order is packed, and revenue is
 * recognised in the ledger when it ships. Anchoring a profit report on the
 * order date would put a December sale's revenue in December and its cost
 * wherever the goods happened to leave — the classic way to manufacture a
 * quarter that never existed. Both sides are anchored to the ORDER, on one
 * date, so they can never split across a period boundary.
 */
final class SalesReportService
{
    /**
     * Orders that represent a completed sale.
     *
     * `placed` is absent: it is an order awaiting payment, not a sale.
     * `cancelled` never shipped. The refund statuses are absent from the
     * counted set because a refund is reported as its own negative row in the
     * period it was approved, not by retro-removing the original sale.
     */
    public const COUNTED_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_READY_TO_SHIP,
        Order::STATUS_SHIPPED,
        Order::STATUS_DELIVERED,
        Order::STATUS_CONFIRMED,
    ];

    /** Statuses whose revenue has been reversed. Reported separately, never in the counted set. */
    public const REFUNDED_STATUSES = [
        Order::STATUS_REFUND_APPROVED,
        Order::STATUS_REFUNDED,
    ];

    public const BASIS_SHIPPED = 'shipped';

    public const BASIS_ORDERED = 'ordered';

    public const BASES = [self::BASIS_SHIPPED, self::BASIS_ORDERED];

    /**
     * The one base query every figure in every sales or profit view derives
     * from. Selects nothing and orders nothing, so callers can aggregate or
     * paginate it without the two drifting apart.
     */
    public function countedOrders(
        SalesScope $scope,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        string $basis = self::BASIS_SHIPPED,
    ): Builder {
        $query = DB::table('orders')->whereIn('orders.status', self::COUNTED_STATUSES);

        $scope->apply($query);

        return $this->withinPeriod($query, $this->dateColumn($basis), $from, $to);
    }

    /**
     * Refunds approved in the period, as a positive magnitude — the caller
     * subtracts. Always keyed on `refund_approved_at`: a refund belongs to the
     * period it was granted in, whenever the original sale happened.
     */
    public function refundedOrders(
        SalesScope $scope,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): Builder {
        $query = DB::table('orders')->whereIn('orders.status', self::REFUNDED_STATUSES);

        $scope->apply($query);

        return $this->withinPeriod($query, 'orders.refund_approved_at', $from, $to);
    }

    /**
     * Period totals for the counted set.
     *
     * @return array{orders: int, gross_ex_gst_paise: int, gst_paise: int, discount_paise: int,
     *               points_paise: int, shipping_paise: int, cash_paise: int}
     */
    public function totals(
        SalesScope $scope,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        string $basis = self::BASIS_SHIPPED,
    ): array {
        return $this->totalsFor($this->countedOrders($scope, $from, $to, $basis));
    }

    /**
     * Period totals for refunds, in the same shape as {@see totals()} so the
     * caller can subtract field by field without reshaping anything.
     *
     * @return array{orders: int, gross_ex_gst_paise: int, gst_paise: int, discount_paise: int,
     *               points_paise: int, shipping_paise: int, cash_paise: int}
     */
    public function refundTotals(
        SalesScope $scope,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        return $this->totalsFor($this->refundedOrders($scope, $from, $to));
    }

    /**
     * Sold lines in the period, one row per (order, variant).
     *
     * Deliberately a separate query from {@see totals()} rather than a join
     * onto it: joining `order_items` to `orders` and summing header money
     * multiplies every order total by its line count. That fan-out is the
     * single most common way a sales report overstates revenue, and keeping
     * the two queries apart makes it structurally impossible here.
     */
    public function soldLines(
        SalesScope $scope,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        string $basis = self::BASIS_SHIPPED,
    ): Builder {
        return DB::table('order_items')
            ->joinSub(
                $this->countedOrders($scope, $from, $to, $basis)->select('orders.id'),
                'counted',
                'counted.id',
                '=',
                'order_items.order_id',
            );
    }

    /** The same, for lines whose revenue was reversed in the period. */
    public function refundedLines(
        SalesScope $scope,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): Builder {
        return DB::table('order_items')
            ->joinSub(
                $this->refundedOrders($scope, $from, $to)->select('orders.id'),
                'refunded',
                'refunded.id',
                '=',
                'order_items.order_id',
            );
    }

    public function dateColumn(string $basis): string
    {
        return $basis === self::BASIS_ORDERED ? 'orders.placed_at' : 'orders.shipped_at';
    }

    /**
     * @return array{orders: int, gross_ex_gst_paise: int, gst_paise: int, discount_paise: int,
     *               points_paise: int, shipping_paise: int, cash_paise: int}
     */
    private function totalsFor(Builder $query): array
    {
        $row = $query->selectRaw(
            'COUNT(*) as orders, '.
            // Ex-GST revenue: what the ledger recognises, not what was collected.
            'COALESCE(SUM(orders.subtotal_paise - orders.gst_paise), 0) as gross_ex_gst_paise, '.
            'COALESCE(SUM(orders.gst_paise), 0) as gst_paise, '.
            'COALESCE(SUM(orders.discount_paise), 0) as discount_paise, '.
            'COALESCE(SUM(orders.redeem_points_paise), 0) as points_paise, '.
            'COALESCE(SUM(orders.shipping_paise), 0) as shipping_paise, '.
            'COALESCE(SUM(orders.total_paise), 0) as cash_paise'
        )->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'gross_ex_gst_paise' => (int) ($row->gross_ex_gst_paise ?? 0),
            'gst_paise' => (int) ($row->gst_paise ?? 0),
            'discount_paise' => (int) ($row->discount_paise ?? 0),
            'points_paise' => (int) ($row->points_paise ?? 0),
            'shipping_paise' => (int) ($row->shipping_paise ?? 0),
            'cash_paise' => (int) ($row->cash_paise ?? 0),
        ];
    }

    private function withinPeriod(Builder $query, string $column, ?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        // An order that has not reached the basis date yet has not happened for
        // reporting purposes, so an open-ended range still excludes NULLs.
        $query->whereNotNull($column);

        return $query
            ->when($from !== null, fn (Builder $q): Builder => $q->where($column, '>=', $from))
            ->when($to !== null, fn (Builder $q): Builder => $q->where($column, '<=', $to));
    }
}
