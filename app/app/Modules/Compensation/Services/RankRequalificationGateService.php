<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KP's §8 requalification conditions (confirmed 2026-08-05): a distributor
 * being credited a rank for the 2nd-or-later time — and every AO-GO grantee —
 * must, in the bonus month, have (a) personal purchase BV of at least the
 * rank's monthly repurchase obligation (rank_tiers.repurchase_bv_paise) and
 * (b) a cleared repurchase wallet.
 *
 * "Wallet cleared" is the MONTH-END BALANCE, not the repurchase cycle's verdict
 * (client 2026-09-05, re-confirmed 2026-09-07): a cycle failure forfeits the
 * failed days' group BV and nothing else, while unspent repurchase-wallet money
 * held at the last instant of the month is its own, separate qualification
 * failure. The question is answered in exactly one place,
 * {@see RepurchaseWalletGateService::clearedAtMonthEnd()}, so this gate, GBB
 * and Fortune can never disagree about a month.
 */
final class RankRequalificationGateService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly RepurchaseWalletGateService $walletGate,
    ) {}

    /**
     * Evaluate the gate for many distributors at once (two queries total).
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool> distributor_id → passes
     */
    public function passMap(array $distributorIds, Carbon $month, int $rank): array
    {
        if ($distributorIds === []) {
            return [];
        }

        $requiredBvPaise = $this->plan->rankRepurchaseBvPaise($rank);
        $bvMap = $this->monthlyPersonalBvMap($distributorIds, $month);
        $clearedMap = $this->walletClearedMap($distributorIds, $month);

        $map = [];
        foreach ($distributorIds as $id) {
            $map[$id] = ($bvMap[$id] ?? 0) >= $requiredBvPaise
                && ($clearedMap[$id] ?? true);
        }

        return $map;
    }

    public function passes(int $distributorId, Carbon $month, int $rank): bool
    {
        return $this->passMap([$distributorId], $month, $rank)[$distributorId] ?? false;
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

    /**
     * Whether each distributor's repurchase wallet was clear at the end of the
     * month being evaluated. Delegated — this service does not answer the
     * month-end wallet question itself.
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool>
     */
    private function walletClearedMap(array $distributorIds, Carbon $month): array
    {
        return $this->walletGate->clearedAtMonthEnd($distributorIds, $month);
    }
}
