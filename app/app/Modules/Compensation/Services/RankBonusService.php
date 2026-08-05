<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly Rank Bonus engine (KP 2026-08-05 spec).
 *
 * Pool per rank = company BV × envelope × pool_pct[rank].
 *
 * "Turnover" here means COMPANY BV — the signed bv_ledger_entries sum for the
 * month (GsbDailyPoolService::companyBvPaiseBetween), not order sales value.
 * The Rank Bonus envelope (comp.rank.envelope_bp, default 2000 bp = 20%) is
 * carved out of that BV first; each rank's `rank_tiers.pool_pct` is then a
 * share OF THE ENVELOPE, not of turnover directly — the nine seeded
 * percentages sum to exactly 20.
 *
 * Worked example (product owner 2026-08-05): 10,00,000 BV in the month →
 * 20% envelope = 2,00,000 → Rank 1's 7% share = ₹14,000.
 *
 * Company BV is a SIGNED sum, so a refund-heavy month can be negative; every
 * pool is floored at 0 so a negative amount can never reach the wallet.
 *
 * Distribution:
 *  - Ranks with rap_points set (seeded: Rank 1 = 10) divide their pool by
 *    points, like the MSB daily pool: point value = floor-to-whole-rupee(
 *    pool ÷ (payable achievers × RAP + Σ AO-GO points)); each participant is
 *    paid own points × point value. AO-GO grantees always share the RANK-1
 *    pool, whatever rank they previously held.
 *  - Ranks with null rap_points (2–9) split their pool equally among payable
 *    achievers: floor(pool / count).
 *
 * Payment follows achievement — the old occurrence >= pyp_required payment
 * filter is retired (pyp_required is the Q-Period promotion gate in
 * RankQualificationService), as is the "1+2 rule" carry-forward (replaced by
 * the AO-GO offer).
 *
 * §8 requalification gate: a 2nd-or-later lifetime qualification of the same
 * rank is credited only if the month's requalification conditions hold
 * (rank's repurchase BV + wallet cleared — RankRequalificationGateService);
 * otherwise the result is recorded as `requalification_held`, excluded from
 * the pool denominator (MSB precedent: only paid participants dilute the
 * pool) and never back-paid.
 *
 * Deductions (admin charge, TDS) are applied at payout time, not credit time.
 * All rates, caps and pool figures are read from
 * CompensationPlanSettingsService (admin-editable), not hardcoded.
 */
final class RankBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
        private readonly AogoOfferService $aogo,
        private readonly RankRequalificationGateService $gate,
        private readonly GsbDailyPoolService $gsbPool,
    ) {}

    /**
     * Run the Rank Bonus for the given calendar month.
     * Idempotent: skips distributors already credited for that month+rank.
     *
     * @return array{
     *     turnover_paise: int,
     *     credited: int,
     *     by_rank: array<int, array{qualifiers: int, held: int, aogo_grants: int, pool_paise: int, total_points: int|null, point_value_paise: int|null, net_total: int}>
     * }
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStartCarbon = $month->copy()->startOfMonth();
        $monthStart = $monthStartCarbon->toDateString();
        $monthEnd = $month->copy()->endOfMonth();

        $turnoverPaise = $this->gsbPool->companyBvPaiseBetween($monthStartCarbon, $monthEnd);

        $credited = 0;
        $byRank = [];

        DB::transaction(function () use (
            $monthStartCarbon, $monthStart, $turnoverPaise, &$credited, &$byRank,
        ): void {
            foreach (range(1, 9) as $rank) {
                $poolPct = $this->plan->rankPoolPct($rank);
                $poolPaise = max(0, (int) round(
                    $turnoverPaise * $this->plan->rankEnvelopeBp() / 10_000 * $poolPct / 100,
                ));
                $rapPoints = $this->plan->rankRapPoints($rank);

                $qualifierIds = RankQualification::where('month_start', $monthStart)
                    ->where('rank_number', $rank)
                    ->where('status', RankQualification::STATUS_QUALIFIED)
                    ->where('is_carry_forward', false)
                    ->distinct()
                    ->pluck('distributor_id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                $heldIds = $this->requalificationHeldIds($qualifierIds, $monthStartCarbon, $rank);
                $payableIds = array_values(array_diff($qualifierIds, $heldIds));

                /** @var Collection<int, RankAogoGrant> $grants */
                $grants = $rank === 1 ? $this->aogo->grantForMonth($monthStartCarbon) : collect();

                $totalPoints = null;
                $pointValuePaise = null;
                if ($rapPoints !== null) {
                    $totalPoints = count($payableIds) * $rapPoints + (int) $grants->sum('points');
                    // Point value floored to the whole rupee; the remainder
                    // stays unspent (KP confirmed 2026-08-05, same as MSB).
                    $pointValuePaise = $totalPoints > 0
                        ? intdiv(intdiv($poolPaise, $totalPoints), 100) * 100
                        : 0;
                    $grossPerQualifier = $rapPoints * $pointValuePaise;
                } else {
                    $grossPerQualifier = $payableIds !== []
                        ? (int) floor($poolPaise / count($payableIds))
                        : 0;
                }

                $byRank[$rank] = [
                    'qualifiers' => count($payableIds),
                    'held' => count($heldIds),
                    'aogo_grants' => $grants->count(),
                    'pool_paise' => $poolPaise,
                    'total_points' => $totalPoints,
                    'point_value_paise' => $pointValuePaise,
                    'net_total' => 0,
                ];

                foreach ($heldIds as $distributorId) {
                    $this->recordHeld($distributorId, $monthStart, $rank, $turnoverPaise, $poolPaise, count($payableIds));
                }

                if ($poolPaise === 0 || ($payableIds === [] && $grants->isEmpty())) {
                    continue;
                }

                foreach ($payableIds as $distributorId) {
                    $alreadyCredited = RankBonusResult::where('distributor_id', $distributorId)
                        ->where('month_start', $monthStart)
                        ->where('rank_number', $rank)
                        ->where('status', RankBonusResult::STATUS_CREDITED)
                        ->exists();

                    if ($alreadyCredited) {
                        continue;
                    }

                    $result = RankBonusResult::updateOrCreate(
                        [
                            'distributor_id' => $distributorId,
                            'month_start' => $monthStart,
                            'rank_number' => $rank,
                        ],
                        [
                            'company_turnover_paise' => $turnoverPaise,
                            'pool_paise' => $poolPaise,
                            'qualifier_count' => count($payableIds),
                            'rap_points' => $rapPoints,
                            'aogo_points' => null,
                            'total_points' => $totalPoints,
                            'point_value_paise' => $pointValuePaise,
                            'gross_paise' => $grossPerQualifier,
                            'admin_charge_paise' => 0,
                            'tds_paise' => 0,
                            'net_paise' => $grossPerQualifier,
                            'status' => RankBonusResult::STATUS_PENDING,
                        ],
                    );

                    if ($grossPerQualifier > 0) {
                        $rankName = $this->plan->rankName($rank);
                        $this->wallet->credit(
                            distributorId: $distributorId,
                            amountPaise: $grossPerQualifier,
                            type: 'rank_credit',
                            referenceId: $result->id,
                            referenceType: 'rank_bonus_result',
                            memo: $rankName.' Bonus '.$monthStart,
                        );

                        $result->update([
                            'status' => RankBonusResult::STATUS_CREDITED,
                            'credited_at' => now(),
                        ]);

                        $byRank[$rank]['net_total'] += $grossPerQualifier;
                        $credited++;
                    }

                    $this->maybeCreateLifetimeAward($distributorId, $rank, $monthStart);
                }

                foreach ($grants as $grant) {
                    $creditedNow = $this->creditAogoGrant(
                        $grant,
                        $monthStart,
                        $turnoverPaise,
                        $poolPaise,
                        count($payableIds),
                        $totalPoints,
                        $pointValuePaise ?? 0,
                    );

                    if ($creditedNow > 0) {
                        $byRank[$rank]['net_total'] += $creditedNow;
                        $credited++;
                    }
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
     * §8: among this month's qualifiers, the 2nd-or-later lifetime achievers of
     * this rank that fail the requalification conditions. First-time achievers
     * are exempt.
     *
     * @param  int[]  $qualifierIds
     * @return int[]
     */
    private function requalificationHeldIds(array $qualifierIds, Carbon $month, int $rank): array
    {
        if ($qualifierIds === []) {
            return [];
        }

        $repeatIds = RankQualification::whereIn('distributor_id', $qualifierIds)
            ->where('rank_number', $rank)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->where('is_carry_forward', false)
            ->where('month_start', '<', $month->toDateString())
            ->distinct()
            ->pluck('distributor_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if ($repeatIds === []) {
            return [];
        }

        $passMap = $this->gate->passMap($repeatIds, $month, $rank);

        return array_values(array_filter(
            $repeatIds,
            fn (int $id): bool => ! ($passMap[$id] ?? false),
        ));
    }

    /** Record a requalification-held result: visible in reports, never credited, not back-paid. */
    private function recordHeld(
        int $distributorId,
        string $monthStart,
        int $rank,
        int $turnoverPaise,
        int $poolPaise,
        int $payableCount,
    ): void {
        $alreadyCredited = RankBonusResult::where('distributor_id', $distributorId)
            ->where('month_start', $monthStart)
            ->where('rank_number', $rank)
            ->where('status', RankBonusResult::STATUS_CREDITED)
            ->exists();

        if ($alreadyCredited) {
            return;
        }

        RankBonusResult::updateOrCreate(
            [
                'distributor_id' => $distributorId,
                'month_start' => $monthStart,
                'rank_number' => $rank,
            ],
            [
                'company_turnover_paise' => $turnoverPaise,
                'pool_paise' => $poolPaise,
                'qualifier_count' => $payableCount,
                'rap_points' => null,
                'aogo_points' => null,
                'total_points' => null,
                'point_value_paise' => null,
                'gross_paise' => 0,
                'admin_charge_paise' => 0,
                'tds_paise' => 0,
                'net_paise' => 0,
                'status' => RankBonusResult::STATUS_REQUALIFICATION_HELD,
            ],
        );
    }

    /**
     * Credit one AO-GO grant from the Rank-1 pool: points × point value.
     * Returns the paise credited (0 when already credited or value is 0).
     */
    private function creditAogoGrant(
        RankAogoGrant $grant,
        string $monthStart,
        int $turnoverPaise,
        int $poolPaise,
        int $payableCount,
        ?int $totalPoints,
        int $pointValuePaise,
    ): int {
        if ($grant->status === RankAogoGrant::STATUS_CREDITED) {
            return 0;
        }

        $grossPaise = $grant->points * $pointValuePaise;

        $result = RankBonusResult::updateOrCreate(
            [
                'distributor_id' => $grant->distributor_id,
                'month_start' => $monthStart,
                'rank_number' => 1,
            ],
            [
                'company_turnover_paise' => $turnoverPaise,
                'pool_paise' => $poolPaise,
                'qualifier_count' => $payableCount,
                'rap_points' => null,
                'aogo_points' => $grant->points,
                'total_points' => $totalPoints,
                'point_value_paise' => $pointValuePaise,
                'gross_paise' => $grossPaise,
                'admin_charge_paise' => 0,
                'tds_paise' => 0,
                'net_paise' => $grossPaise,
                'status' => RankBonusResult::STATUS_PENDING,
            ],
        );

        if ($grossPaise === 0) {
            return 0;
        }

        $this->wallet->credit(
            distributorId: $grant->distributor_id,
            amountPaise: $grossPaise,
            type: 'rank_credit',
            referenceId: $result->id,
            referenceType: 'rank_bonus_result',
            memo: 'AO-GO Offer '.$monthStart,
        );

        $result->update([
            'status' => RankBonusResult::STATUS_CREDITED,
            'credited_at' => now(),
        ]);

        $grant->update([
            'point_value_paise' => $pointValuePaise,
            'income_paise' => $grossPaise,
            'status' => RankAogoGrant::STATUS_CREDITED,
            'credited_at' => now(),
        ]);

        return $grossPaise;
    }

    /**
     * Create or update a LifetimeAwardMilestone on each rank qualification.
     *
     * First qualification: creates the milestone with qualification_count = 1.
     * Subsequent qualifications while still pending: increments qualification_count
     * so the release-threshold gate (D4) can be evaluated.
     * Already delivered/cancelled milestones are left untouched.
     */
    private function maybeCreateLifetimeAward(
        int $distributorId,
        int $rank,
        string $monthStart,
    ): void {
        $existing = LifetimeAwardMilestone::where('distributor_id', $distributorId)
            ->where('rank_number', $rank)
            ->first();

        if ($existing === null) {
            $rankName = $this->plan->rankName($rank);

            LifetimeAwardMilestone::create([
                'distributor_id' => $distributorId,
                'rank_number' => $rank,
                'triggered_month' => $monthStart,
                'qualification_count' => 1,
                'award_description' => $rankName.' — non-cash reward per plan',
                'status' => LifetimeAwardMilestone::STATUS_PENDING,
            ]);

            return;
        }

        // Increment count for pending milestones so the release threshold is tracked.
        if ($existing->status === LifetimeAwardMilestone::STATUS_PENDING) {
            $existing->increment('qualification_count');
        }
    }
}
