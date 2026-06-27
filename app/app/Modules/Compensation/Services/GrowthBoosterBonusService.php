<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Growth Booster Bonus engine.
 *
 * Runs once per calendar month. Pool = 5% of company monthly turnover.
 * Distributed proportionally via Arovolife Growth Points (AGP):
 *   1st GSB slab → 12 AGP, 2nd → 5 AGP, 3rd → 2 AGP, 4th–7th → 0.
 * Each distributor is capped at 120 AGP.
 *
 * Eligibility for Phase 4: all active distributors with at least 1 AGP.
 * Phase 5 will gate this on "no rank held in prior month" once the rank
 * engine exists.
 *
 * Pool rate, AGP cap, admin charge and TDS are all admin-editable via
 * CompensationPlanSettingsService. KP's 2026-06-26 amendment brought GBB into
 * the admin-charge scope, so net = gross − admin charge − TDS.
 */
final class GrowthBoosterBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
        private readonly BonusDeductionService $deductions,
    ) {}

    /**
     * Run the GBB calculation for the given calendar month.
     * Idempotent: skips distributors that already have a credited result for the month.
     *
     * @return array{pool_paise: int, total_agp: int, credited: int, skipped_no_agp: int}
     */
    public function runForMonth(Carbon $month): array
    {
        $yearMonth = $month->copy()->startOfMonth()->toDateString();
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $turnoverPaise = $this->companyTurnoverPaise($monthStart, $monthEnd);
        $poolPaise = (int) round($turnoverPaise * $this->plan->gbbPoolRateBp() / 10_000);

        // Compute AGP per eligible distributor for the month.
        $agpMap = $this->buildAgpMap($monthStart, $monthEnd);

        if ($agpMap->isEmpty() || $poolPaise === 0) {
            return [
                'pool_paise' => $poolPaise,
                'total_agp' => 0,
                'credited' => 0,
                'skipped_no_agp' => 0,
            ];
        }

        $totalAgp = $agpMap->sum();
        // Point value as integer paise (truncate; residual stays in pool, not distributed).
        $pointValuePaise = (int) ($poolPaise / $totalAgp);

        $credited = 0;
        $skippedNoAgp = 0;

        DB::transaction(function () use (
            $agpMap, $yearMonth, $turnoverPaise, $poolPaise, $totalAgp,
            $pointValuePaise, &$credited, &$skippedNoAgp,
        ): void {
            foreach ($agpMap as $distributorId => $agp) {
                if ($agp === 0) {
                    $skippedNoAgp++;

                    continue;
                }

                // Idempotent: skip if already credited.
                $existing = GbbMonthlyResult::where('distributor_id', $distributorId)
                    ->where('year_month', $yearMonth)
                    ->where('status', GbbMonthlyResult::STATUS_CREDITED)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $gross = $pointValuePaise * $agp;
                $deduction = $this->deductions->for(BonusType::GrowthBooster, $gross);
                $adminCharge = $deduction->adminChargePaise;
                $tds = $deduction->tdsPaise;
                $net = $deduction->netPaise;

                $result = GbbMonthlyResult::updateOrCreate(
                    ['distributor_id' => $distributorId, 'year_month' => $yearMonth],
                    [
                        'agp_earned' => $agp,
                        'company_turnover_paise' => $turnoverPaise,
                        'pool_paise' => $poolPaise,
                        'total_pool_agp' => $totalAgp,
                        'gbb_gross_paise' => $gross,
                        'admin_charge_paise' => $adminCharge,
                        'tds_paise' => $tds,
                        'gbb_net_paise' => $net,
                        'status' => GbbMonthlyResult::STATUS_PENDING,
                    ],
                );

                if ($net > 0) {
                    $this->wallet->credit(
                        distributorId: $distributorId,
                        amountPaise: $net,
                        type: 'gbb_credit',
                        referenceId: $result->id,
                        referenceType: 'gbb_monthly_result',
                        memo: 'Growth Booster Bonus '.$yearMonth,
                    );
                }

                $result->update([
                    'status' => GbbMonthlyResult::STATUS_CREDITED,
                    'credited_at' => now(),
                ]);

                $credited++;
            }
        });

        return [
            'pool_paise' => $poolPaise,
            'total_agp' => $totalAgp,
            'credited' => $credited,
            'skipped_no_agp' => $skippedNoAgp,
        ];
    }

    /**
     * Build a map of distributor_id → capped AGP for the month.
     * Only includes active distributors who earned at least 1 AGP from slabs 1–3.
     *
     * Phase 4 stub: all active distributors are eligible (no prior-month rank check).
     * Phase 5 will add: exclude distributors who held a rank in the prior month.
     *
     * @return Collection<int, int>
     */
    private function buildAgpMap(Carbon $monthStart, Carbon $monthEnd): Collection
    {
        $rows = GsbCutoffResult::query()
            ->where('status', GsbCutoffResult::STATUS_CREDITED)
            ->whereIn('slab', [1, 2, 3])
            ->whereBetween('cutoff_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->select('distributor_id', 'slab', DB::raw('COUNT(*) as occurrences'))
            ->groupBy('distributor_id', 'slab')
            ->get();

        /** @var Collection<int, int> $agpMap distributor_id → raw (pre-cap) AGP */
        $agpMap = collect();

        $agpBySlab = $this->plan->agpBySlab();

        foreach ($rows as $row) {
            $distributorId = (int) $row->distributor_id;
            $agpPerOccurrence = $agpBySlab[(int) $row->slab] ?? 0;
            $agpMap[$distributorId] = ($agpMap[$distributorId] ?? 0) + ($agpPerOccurrence * (int) $row->occurrences);
        }

        // Apply per-distributor cap.
        $cap = $this->plan->gbbAgpCap();

        return $agpMap->map(fn (int $agp) => min($agp, $cap));
    }

    /**
     * Sum of total_paise for all paid (non-cancelled, non-refunded) orders in the month.
     */
    private function companyTurnoverPaise(Carbon $monthStart, Carbon $monthEnd): int
    {
        return (int) Order::whereBetween('created_at', [$monthStart, $monthEnd->endOfDay()])
            ->whereNotIn('status', [
                Order::STATUS_DRAFT,
                Order::STATUS_PLACED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REFUND_REQUESTED,
                Order::STATUS_REFUND_INSPECTION,
                Order::STATUS_REFUND_APPROVED,
                Order::STATUS_REFUNDED,
            ])
            ->sum('total_paise');
    }
}
