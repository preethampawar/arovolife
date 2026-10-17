<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GsbCutoffResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Propagates one distributor's BV purchase to all their ancestors' group_bv_daily
 * accumulators. For each ancestor A, determines which side (L/R) this distributor
 * falls on by checking which of A's direct children is an ancestor of this distributor.
 *
 * Cancelled-order reversal support (KP Q8):
 * - Each ancestor credit is snapshotted into group_bv_credits (same transaction)
 *   so a later reversal debits exactly who was originally credited.
 * - Before crediting, any outstanding reversal debt on that ancestor's SAME
 *   side is consumed first — cancelled BV is deducted from subsequent
 *   purchases in that group ({@see GroupBvReversalService}).
 */
final class GroupBvAccumulatorService
{
    public function propagate(int $distributorId, int $bvPaise, Carbon $date, ?int $orderId = null): void
    {
        // For each ancestor A of D (at any depth), find the direct child of A on
        // the path to D. That child's placement_side relative to A = the side D is on.
        $pairs = DB::table('genealogy_closure as gc_anc')
            ->join('genealogy_closure as gc_child', function ($join) {
                $join->on('gc_child.descendant_id', '=', 'gc_anc.descendant_id')
                    ->whereRaw('gc_child.depth = gc_anc.depth - 1');
            })
            ->join('distributors as dc', function ($join) {
                $join->on('dc.id', '=', 'gc_child.ancestor_id')
                    ->on('dc.placement_parent_id', '=', 'gc_anc.ancestor_id');
            })
            ->where('gc_anc.descendant_id', $distributorId)
            ->where('gc_anc.depth', '>', 0)
            ->whereIn('dc.placement_side', ['L', 'R'])
            ->select('gc_anc.ancestor_id', 'dc.placement_side as side')
            ->get();

        if ($pairs->isEmpty()) {
            return;
        }

        $dateStr = $date->toDateString();
        $targetDates = $this->targetDates($pairs->pluck('ancestor_id')->map(fn ($id): int => (int) $id)->all(), $dateStr);

        $redirected = array_filter($targetDates, fn (string $target): bool => $target !== $dateStr);
        if ($redirected !== []) {
            Log::warning('gsb.bv_propagation.landed_after_cutoff', [
                'order_id' => $orderId,
                'distributor_id' => $distributorId,
                'bv_paise' => $bvPaise,
                'paid_on' => $dateStr,
                'moved_to' => Carbon::today()->toDateString(),
                'ancestors_moved' => count($redirected),
            ]);
        }

        // Wrap the entire ancestor loop in a single outer transaction so that if the
        // worker dies mid-loop the DB server rolls back the whole batch. On retry all
        // ancestors are processed from scratch with no double-counting.
        // Inner upsertAccumulator() calls use DB::transaction() too; on MySQL those
        // become savepoints within this outer transaction automatically.
        DB::transaction(function () use ($pairs, $bvPaise, $targetDates, $orderId, $redirected): void {
            // The marker carries the day the BV landed, not the day it was
            // paid: a windowed recompute deletes markers by that date together
            // with the credits, and replays an order whose marker it deleted
            // ({@see GroupBvReplayService}). Left on the paid day, the marker
            // would survive the wipe that took the credits, and the order's
            // group BV would vanish from the rebuilt window.
            if ($redirected !== [] && $orderId !== null) {
                DB::table('bv_propagation_log')->where('order_id', $orderId)->update(['date' => Carbon::today()->toDateString()]);
            }

            foreach ($pairs as $pair) {
                $ancestorId = (int) $pair->ancestor_id;
                $dateStr = $targetDates[$ancestorId];

                // Outstanding reversal debt on this side eats the BV first;
                // only the remainder reaches the accumulator (KP Q8: cancelled
                // BV is deducted from subsequent purchases on the same leg).
                $consumed = $this->consumeDebt($ancestorId, $pair->side, $bvPaise);
                $credit = $bvPaise - $consumed;

                if ($credit > 0) {
                    $leftAdd = $pair->side === 'L' ? $credit : 0;
                    $rightAdd = $pair->side === 'R' ? $credit : 0;
                    $this->upsertAccumulator($ancestorId, $dateStr, $leftAdd, $rightAdd);
                }

                // Snapshot who was credited (and how much debt this order paid
                // down) so a reversal targets the original recipients only.
                if ($orderId !== null) {
                    DB::table('group_bv_credits')->insert([
                        'order_id' => $orderId,
                        'ancestor_id' => $ancestorId,
                        'side' => $pair->side,
                        'bv_paise' => $credit,
                        'debt_consumed_paise' => $consumed,
                        'date' => $dateStr,
                    ]);
                }
            }
        });
    }

    /**
     * The day each ancestor's share of this BV lands on.
     *
     * Normally the day the order was paid. But the compensation worker can
     * reach a job after that day's cut-off has read the ancestor's row — a
     * backed-up or restarted worker, or a failed job retried next morning —
     * and the cut-off never reads a day twice: the out-of-order guard forbids
     * it. BV written there would never match and never carry forward, so the
     * distributor would be under-paid with nothing to show it. Once the
     * cut-off for the paid day has begun, the share lands on today's open
     * accumulator instead, the way a reversal on a settled day absorbs from
     * today ({@see GroupBvReversalService}).
     *
     * Three outcomes keep the paid day. A below-600-BV day and a
     * repurchase-forfeited day discard that day's group BV outright, and BV
     * that arrived on time would have been discarded with it — moving it to
     * today would hand the ancestor BV the plan forfeits (and should the
     * forfeited day be re-run as eligible, it reads the BV there). A failed
     * run will be re-run, and reads the paid day.
     *
     * @param  array<int, int>  $ancestorIds
     * @return array<int, string> ancestor id => Y-m-d
     */
    private function targetDates(array $ancestorIds, string $paidOn): array
    {
        $today = Carbon::today()->toDateString();

        if ($paidOn >= $today) {
            return array_fill_keys($ancestorIds, $paidOn);
        }

        /** @var array<int, string> $statuses */
        $statuses = DB::table('gsb_cutoff_results')
            ->where('cutoff_date', $paidOn)
            ->whereIn('distributor_id', $ancestorIds)
            ->pluck('status', 'distributor_id')
            ->mapWithKeys(fn ($status, $id): array => [(int) $id => (string) $status])
            ->all();

        $cutoffBegun = $statuses !== []
            || DB::table('gsb_cutoff_results')->where('cutoff_date', $paidOn)->exists()
            || EngineRun::query()
                ->where('engine_key', 'gsb.daily-cutoff')
                ->where('status', EngineRun::STATUS_RUNNING)
                ->whereDate('period_start', $paidOn)
                ->where('started_at', '>=', Carbon::now()->subMinutes(EngineRun::STALE_AFTER_MINUTES))
                ->exists();

        $targets = [];
        foreach ($ancestorIds as $ancestorId) {
            $status = $statuses[$ancestorId] ?? null;

            $targets[$ancestorId] = match (true) {
                in_array($status, [GsbCutoffResult::STATUS_BELOW_600BV, GsbCutoffResult::STATUS_REPURCHASE_FORFEITED], true) => $paidOn,
                // A failed run is re-run for this ancestor (the retry deletes
                // the row first), and reads the paid day when it does.
                $status === GsbCutoffResult::STATUS_FAILED => $paidOn,
                $status !== null => $today,
                $cutoffBegun => $today,
                default => $paidOn,
            };
        }

        return $targets;
    }

    /**
     * Consume outstanding reversal debt for (distributor, side), up to the
     * incoming BV. Locks the debt row so concurrent propagations serialise;
     * returns the amount consumed (0 when no debt is open).
     */
    private function consumeDebt(int $distributorId, string $side, int $bvPaise): int
    {
        $debt = DB::table('group_bv_debts')
            ->where('distributor_id', $distributorId)
            ->where('side', $side)
            ->where('bv_paise', '>', 0)
            ->lockForUpdate()
            ->first();

        if ($debt === null) {
            return 0;
        }

        $consumed = min($bvPaise, (int) $debt->bv_paise);

        DB::table('group_bv_debts')
            ->where('id', $debt->id)
            ->update([
                'bv_paise' => (int) $debt->bv_paise - $consumed,
                'updated_at' => Carbon::now(),
            ]);

        return $consumed;
    }

    /**
     * Atomically add left/right BV to a single group_bv_daily row.
     *
     * Strategy: attempt an optimistic SELECT-for-update + update inside a
     * transaction. If no row exists, INSERT — and catch the unique-constraint
     * violation that occurs when two concurrent workers race to insert the
     * same (distributor_id, date) pair, then retry as an update.
     *
     * On MySQL the lockForUpdate() call is a real row-level lock, so concurrent
     * updates on an existing row are serialised. On SQLite (test environment),
     * lockForUpdate() is a no-op — but tests are single-process and sequential,
     * so no race can occur there.
     */
    private function upsertAccumulator(
        int $ancestorId,
        string $dateStr,
        int $leftAdd,
        int $rightAdd,
    ): void {
        DB::transaction(function () use ($ancestorId, $dateStr, $leftAdd, $rightAdd): void {
            $existing = DB::table('group_bv_daily')
                ->where('distributor_id', $ancestorId)
                ->where('date', $dateStr)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                DB::table('group_bv_daily')
                    ->where('distributor_id', $ancestorId)
                    ->where('date', $dateStr)
                    ->update([
                        'left_bv_paise' => $existing->left_bv_paise + $leftAdd,
                        'right_bv_paise' => $existing->right_bv_paise + $rightAdd,
                    ]);

                return;
            }

            // Row does not exist — attempt insert. A concurrent worker may have
            // inserted the same row between our SELECT and this INSERT (the gap
            // is not covered by lockForUpdate on a non-existent row). Catch the
            // unique-constraint violation and fall back to an increment update.
            try {
                DB::table('group_bv_daily')->insert([
                    'distributor_id' => $ancestorId,
                    'date' => $dateStr,
                    'left_bv_paise' => $leftAdd,
                    'right_bv_paise' => $rightAdd,
                ]);
            } catch (QueryException $e) {
                // Unique constraint violation (SQLSTATE 23000 / 23505).
                // Another worker won the insert race; add our delta instead.
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }

                DB::table('group_bv_daily')
                    ->where('distributor_id', $ancestorId)
                    ->where('date', $dateStr)
                    ->update([
                        'left_bv_paise' => DB::raw('left_bv_paise + '.(int) $leftAdd),
                        'right_bv_paise' => DB::raw('right_bv_paise + '.(int) $rightAdd),
                    ]);
            }
        });
    }
}
