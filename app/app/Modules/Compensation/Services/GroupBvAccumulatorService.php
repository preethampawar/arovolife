<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        // Wrap the entire ancestor loop in a single outer transaction so that if the
        // worker dies mid-loop the DB server rolls back the whole batch. On retry all
        // ancestors are processed from scratch with no double-counting.
        // Inner upsertAccumulator() calls use DB::transaction() too; on MySQL those
        // become savepoints within this outer transaction automatically.
        DB::transaction(function () use ($pairs, $bvPaise, $dateStr, $orderId): void {
            foreach ($pairs as $pair) {
                $ancestorId = (int) $pair->ancestor_id;

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
