<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * KP's §8 requalification conditions (confirmed 2026-08-05): a distributor
 * being credited a rank for the 2nd-or-later time — and every AO-GO grantee —
 * must, in the bonus month, have (a) personal purchase BV of at least the
 * rank's monthly repurchase obligation (rank_tiers.repurchase_bv_paise) and
 * (b) a cleared repurchase wallet.
 *
 * "Wallet cleared" reads the repurchase engine's latest cycle: grace or
 * suspended means the obligation was missed, so the wallet is NOT cleared.
 * When the {@see RepurchaseEngineFeature} flag is off (or the distributor has
 * no cycle yet) the check fails open — same convention as
 * {@see IncomeEligibilityService}.
 */
final class RankRequalificationGateService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
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
        $clearedMap = $this->walletClearedMap($distributorIds);

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
     * Whether each distributor's repurchase wallet counts as cleared. Missing
     * key = no cycle yet (fail-open, handled by the caller's `?? true`).
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool>
     */
    private function walletClearedMap(array $distributorIds): array
    {
        if (! Feature::for(null)->active(RepurchaseEngineFeature::class)) {
            return [];
        }

        $latest = RepurchaseCycle::query()
            ->whereIn('distributor_id', $distributorIds)
            ->orderByDesc('cycle_start_date')
            ->get()
            ->groupBy('distributor_id');

        $map = [];
        foreach ($latest as $distributorId => $cycles) {
            $status = $cycles->first()?->status;
            $map[(int) $distributorId] = ! in_array($status, [
                RepurchaseCycle::STATUS_GRACE,
                RepurchaseCycle::STATUS_SUSPENDED,
            ], true);
        }

        return $map;
    }
}
