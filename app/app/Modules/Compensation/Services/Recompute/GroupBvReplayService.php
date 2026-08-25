<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Jobs\PropagateGroupBvJob;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Rebuilds the group-BV projection (group_bv_daily, group_bv_credits and the
 * propagation log) from the orders that produced it.
 *
 * Why this step has to exist at all: group_bv_daily is only ever written by
 * PropagateGroupBvJob, dispatched from an order-paid event that has long since
 * fired. Nothing in the codebase re-derives it, so once the wiper truncates the
 * table the only way back is to re-dispatch the same job per order. It is also
 * mutated later by GsbPersonalBvTopupService during the cut-off, which is
 * precisely why the whole projection must be rebuilt rather than preserved —
 * a table carrying last run's top-ups would double-count them.
 *
 * The dispatch conditions mirror PropagateGroupBvOnOrderPaid exactly: paid
 * orders only, an attributed distributor, and a positive accrual sum. Jobs run
 * synchronously and strictly in paid_at order — the accumulator is additive,
 * so ordering decides which day each contribution lands on.
 */
final class GroupBvReplayService
{
    /**
     * Orders per chunk. Doubles as the progress-bar resolution — see the
     * comment on the chunkById() call below before raising it.
     */
    private const PROGRESS_CHUNK = 25;

    public function __construct(private readonly RecomputeProgress $progress) {}

    /**
     * @param  Closure(string): void|null  $progress
     * @param  Carbon|null  $from  windowed replay: only orders paid on or after
     *                             this date, because the projection rows before
     *                             it were never deleted
     * @return int orders propagated
     */
    public function replay(?Closure $progress = null, ?Carbon $from = null): int
    {
        $log = $progress ?? static fn (string $_m): null => null;

        $propagated = 0;

        $eligible = $this->eligibleOrders($from)->count();

        $this->progress->ordersTotal($eligible);
        $this->progress->ordersProgressed(0);

        $this->eligibleOrders($from)
            ->orderBy('paid_at')
            ->orderBy('id')
            // Small chunks on purpose: progress is published once per chunk, so
            // the chunk size *is* the resolution of the progress bar. At 200 a
            // 330-order replay reports twice and looks frozen for minutes; at 25
            // it ticks often enough that a genuine stall is visible as a stall.
            ->chunkById(self::PROGRESS_CHUNK, function ($orders) use (&$propagated, $log): void {
                foreach ($orders as $order) {
                    $bvPaise = (int) BvLedgerEntry::where('order_id', $order->id)
                        ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
                        ->sum('bv_paise');

                    if ($bvPaise <= 0) {
                        continue;
                    }

                    $paidAt = Carbon::parse($order->getAttribute('paid_at'));

                    // Travel the clock so created_at on the rebuilt rows tells
                    // the truth. The job takes the date explicitly, so the
                    // accumulation lands on the right day either way — this is
                    // only about the audit timestamps.
                    Carbon::setTestNow($paidAt);

                    PropagateGroupBvJob::dispatchSync(
                        orderId: (int) $order->id,
                        distributorId: (int) $order->attributed_distributor_id,
                        bvPaise: $bvPaise,
                        date: $paidAt->toDateString(),
                    );

                    $propagated++;
                }

                $this->progress->ordersProgressed($propagated);
                $log(sprintf('  %d order(s) propagated', $propagated));
            });

        Carbon::setTestNow();

        return $propagated;
    }

    /**
     * The orders whose BV this replay is responsible for re-propagating.
     *
     * @return Builder<Order>
     */
    private function eligibleOrders(?Carbon $from): Builder
    {
        return Order::query()
            ->where('status', Order::STATUS_PAID)
            ->whereNotNull('attributed_distributor_id')
            ->whereNotNull('paid_at')
            ->when($from !== null, fn ($q) => $q->where('paid_at', '>=', $from->copy()->startOfDay()));
    }
}
