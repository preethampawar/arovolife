<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The §8 requalification condition: a distributor being credited a rank for
 * the 2nd-or-later time — and every AO-GO grantee — must, in the bonus month,
 * have personal purchase BV of at least the rank's monthly repurchase
 * obligation (rank_tiers.repurchase_bv_paise). For AO-GO the rank is the one
 * achieved in the month before ({@see passMapByRank()}).
 *
 * There is no repurchase-wallet condition: the user's decision of 2026-10-17
 * retired the client's 2026-09-05 month-end wallet gate. The repurchase wallet
 * is judged only at each cycle's due date, and a failed day reaches the rank
 * check only by forfeiting that day's Genos BV.
 *
 * Two kinds of entry point: {@see passMap()} / {@see passMapByRank()} decide
 * money for a month that has ended, and {@see passesSoFar()} shows a
 * distributor how the month they are in is going.
 */
final class RankRequalificationGateService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * Evaluate the gate for many distributors at once, all against one rank's
     * obligation (one query).
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool> distributor_id → passes
     */
    public function passMap(array $distributorIds, Carbon $month, int $rank): array
    {
        return $this->passMapByRank(array_fill_keys($distributorIds, $rank), $month);
    }

    /**
     * Evaluate the gate for many distributors at once, each against the
     * obligation of their own rank (one query) — AO-GO, where the rank is the
     * one each grantee achieved in the month before.
     *
     * @param  array<int, int>  $rankByDistributor  distributor_id → rank number
     * @return array<int, bool> distributor_id → passes
     */
    public function passMapByRank(array $rankByDistributor, Carbon $month): array
    {
        if ($rankByDistributor === []) {
            return [];
        }

        $bvMap = $this->monthlyPersonalBvMap(array_keys($rankByDistributor), $month);

        $map = [];
        foreach ($rankByDistributor as $id => $rank) {
            $map[$id] = ($bvMap[$id] ?? 0) >= $this->plan->rankRepurchaseBvPaise($rank);
        }

        return $map;
    }

    /**
     * The same condition, as it stands TODAY, for one distributor — the
     * dashboard's requalification checklist and the AO-GO status card.
     * It reads the month's BV so far, so a checklist that says "not yet"
     * mid-month is correct.
     */
    public function passesSoFar(int $distributorId, Carbon $month, int $rank): bool
    {
        return $this->monthlyPersonalBvSoFarPaise($distributorId, $month) >= $this->plan->rankRepurchaseBvPaise($rank);
    }

    /** This month's personal purchase BV so far, for one distributor's checklist. */
    public function monthlyPersonalBvSoFarPaise(int $distributorId, Carbon $month): int
    {
        return $this->monthlyPersonalBvMap([$distributorId], $month)[$distributorId] ?? 0;
    }

    /**
     * This-month personal purchase BV per distributor (same query shape as
     * RankQualificationService::buildMonthlyPersonalBvMap()).
     *
     * @param  int[]  $distributorIds
     * @return array<int, int>
     */
    private function monthlyPersonalBvMap(array $distributorIds, Carbon $month): array
    {
        $rows = DB::table('bv_ledger_entries')
            ->whereIn('distributor_id', $distributorIds)
            ->where('type', 'accrual')
            ->whereBetween('effective_at', [
                $month->copy()->startOfMonth()->startOfDay(),
                $month->copy()->endOfMonth()->endOfDay(),
            ])
            ->select('distributor_id', DB::raw('SUM(bv_paise) as total_bv'))
            ->groupBy('distributor_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = (int) $row->total_bv;
        }

        return $map;
    }
}
