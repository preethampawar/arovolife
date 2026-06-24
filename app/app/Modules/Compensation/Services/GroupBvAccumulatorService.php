<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Propagates one distributor's BV purchase to all their ancestors' group_bv_daily
 * accumulators. For each ancestor A, determines which side (L/R) this distributor
 * falls on by checking which of A's direct children is an ancestor of this distributor.
 */
final class GroupBvAccumulatorService
{
    /** Max BV cap for the power side carry-forward (450,000 BV × 100 paise). */
    public const POWER_CF_CAP_PAISE = 45_000_000;

    public function propagate(int $distributorId, int $bvPaise, Carbon $date): void
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

        $dateStr = $date->toDateString();

        foreach ($pairs as $pair) {
            $leftAdd = $pair->side === 'L' ? $bvPaise : 0;
            $rightAdd = $pair->side === 'R' ? $bvPaise : 0;

            // Use a transaction with select-for-update to atomically upsert the
            // accumulator row. This is compatible with both SQLite (tests) and
            // MySQL (production).
            DB::transaction(function () use ($pair, $dateStr, $leftAdd, $rightAdd): void {
                $existing = DB::table('group_bv_daily')
                    ->where('distributor_id', $pair->ancestor_id)
                    ->where('date', $dateStr)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    DB::table('group_bv_daily')->insert([
                        'distributor_id' => $pair->ancestor_id,
                        'date' => $dateStr,
                        'left_bv_paise' => $leftAdd,
                        'right_bv_paise' => $rightAdd,
                    ]);
                } else {
                    DB::table('group_bv_daily')
                        ->where('distributor_id', $pair->ancestor_id)
                        ->where('date', $dateStr)
                        ->update([
                            'left_bv_paise' => $existing->left_bv_paise + $leftAdd,
                            'right_bv_paise' => $existing->right_bv_paise + $rightAdd,
                        ]);
                }
            });
        }
    }
}
