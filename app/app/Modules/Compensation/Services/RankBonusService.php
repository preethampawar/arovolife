<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly Rank Bonus engine.
 *
 * Pool per rank = company_turnover * pool_pct[rank] / 100.
 * Per-distributor gross = floor(pool / qualifier_count).
 * Admin charge = min(floor(gross * 0.03), 3_000_000).
 * TDS = round(gross * 0.05) — applied to gross, NOT to (gross - admin_charge).
 * Net = gross - admin_charge - tds.
 */
final class RankBonusService
{
    private const float ADMIN_CHARGE_RATE = 0.03;

    private const int ADMIN_CHARGE_CAP_PAISE = 3_000_000; // ₹30,000

    private const float TDS_RATE = 0.05;

    public function __construct(private readonly WalletService $wallet) {}

    /**
     * Run the Rank Bonus for the given calendar month.
     * Idempotent: skips distributors already credited for that month+rank.
     *
     * @return array{
     *     turnover_paise: int,
     *     credited: int,
     *     by_rank: array<int, array{qualifiers: int, pool_paise: int, net_total: int}>
     * }
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthStartCarbon = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $turnoverPaise = $this->companyTurnoverPaise($monthStartCarbon, $monthEnd);

        $credited = 0;
        $byRank = [];

        DB::transaction(function () use (
            $monthStart, $turnoverPaise, &$credited, &$byRank,
        ): void {
            foreach (range(1, 9) as $rank) {
                $poolPct = RankQualification::POOL_PCT[$rank];
                $poolPaise = (int) round($turnoverPaise * $poolPct / 100);
                $pypRequired = RankQualification::PYP_REQUIRED[$rank];

                $qualifierIds = RankQualification::where('month_start', $monthStart)
                    ->where('rank_number', $rank)
                    ->where('status', RankQualification::STATUS_QUALIFIED)
                    ->where('occurrence_in_month', '>=', $pypRequired)
                    ->distinct()
                    ->pluck('distributor_id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                $carryForwardIds = RankQualification::where('month_start', $monthStart)
                    ->where('rank_number', $rank)
                    ->where('status', RankQualification::STATUS_QUALIFIED)
                    ->where('is_carry_forward', true)
                    ->distinct()
                    ->pluck('distributor_id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                $allQualifierIds = array_unique(array_merge($qualifierIds, $carryForwardIds));
                $qualifierCount = count($allQualifierIds);

                $byRank[$rank] = [
                    'qualifiers' => $qualifierCount,
                    'pool_paise' => $poolPaise,
                    'net_total' => 0,
                ];

                if ($qualifierCount === 0 || $poolPaise === 0) {
                    continue;
                }

                $grossPerDistributor = (int) floor($poolPaise / $qualifierCount);

                foreach ($allQualifierIds as $distributorId) {
                    $alreadyCredited = RankBonusResult::where('distributor_id', $distributorId)
                        ->where('month_start', $monthStart)
                        ->where('rank_number', $rank)
                        ->where('status', RankBonusResult::STATUS_CREDITED)
                        ->exists();

                    if ($alreadyCredited) {
                        continue;
                    }

                    $adminCharge = min((int) floor($grossPerDistributor * self::ADMIN_CHARGE_RATE), self::ADMIN_CHARGE_CAP_PAISE);
                    $tds = (int) round($grossPerDistributor * self::TDS_RATE);
                    $net = $grossPerDistributor - $adminCharge - $tds;

                    $result = RankBonusResult::updateOrCreate(
                        [
                            'distributor_id' => $distributorId,
                            'month_start' => $monthStart,
                            'rank_number' => $rank,
                        ],
                        [
                            'company_turnover_paise' => $turnoverPaise,
                            'pool_paise' => $poolPaise,
                            'qualifier_count' => $qualifierCount,
                            'gross_paise' => $grossPerDistributor,
                            'admin_charge_paise' => $adminCharge,
                            'tds_paise' => $tds,
                            'net_paise' => max(0, $net),
                            'status' => RankBonusResult::STATUS_PENDING,
                        ],
                    );

                    if ($net > 0) {
                        $rankName = RankQualification::RANK_NAMES[$rank];
                        $this->wallet->credit(
                            distributorId: $distributorId,
                            amountPaise: $net,
                            type: 'rank_credit',
                            referenceId: $result->id,
                            referenceType: 'rank_bonus_result',
                            memo: $rankName.' Bonus '.$monthStart,
                        );

                        $result->update([
                            'status' => RankBonusResult::STATUS_CREDITED,
                            'credited_at' => now(),
                        ]);

                        $byRank[$rank]['net_total'] += $net;
                        $credited++;
                    }

                    $this->maybeCreateLifetimeAward($distributorId, $rank, $monthStart);
                }
            }
        });

        return [
            'turnover_paise' => $turnoverPaise,
            'credited' => $credited,
            'by_rank' => $byRank,
        ];
    }

    /**
     * Create a LifetimeAwardMilestone if this is the first time the distributor
     * has qualified for this rank. Silently ignores duplicate constraint violations.
     */
    private function maybeCreateLifetimeAward(
        int $distributorId,
        int $rank,
        string $monthStart,
    ): void {
        $alreadyExists = LifetimeAwardMilestone::where('distributor_id', $distributorId)
            ->where('rank_number', $rank)
            ->exists();

        if ($alreadyExists) {
            return;
        }

        $rankName = RankQualification::RANK_NAMES[$rank];

        LifetimeAwardMilestone::create([
            'distributor_id' => $distributorId,
            'rank_number' => $rank,
            'triggered_month' => $monthStart,
            'award_description' => $rankName.' — non-cash reward per plan',
            'status' => LifetimeAwardMilestone::STATUS_PENDING,
        ]);
    }

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
