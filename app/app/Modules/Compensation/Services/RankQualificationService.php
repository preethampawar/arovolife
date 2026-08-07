<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checks rank qualification for a given calendar month.
 *
 * Run once per month for occurrence 1 (standard qualification); extra runs in
 * the same month record further occurrences.
 *
 * Cascade order: ranks 1-2 from raw BV, ranks 3-9 from prior rank qualifiers
 * per Genos side PLUS the candidate's own Q-Period promotion gate (KP
 * 2026-08-05): rank r opens only once the candidate has achieved rank r-1 at
 * least pyp_required[r-1] times, counted over lifetime occurrences
 * (Option C, confirmed — see qPeriodCounts()).
 *
 * The "1+2 rule" carry-forward is RETIRED (KP 2026-08-05, replaced by the
 * AO-GO offer): no carry-forward qualification is ever created any more and the
 * rank_tiers column that configured it is gone. Historical
 * rank_qualifications.is_carry_forward rows are retained and still read (the
 * Q-Period count excludes them, the Rank Bonus does not pay them), and a rank-2
 * qualification still voids any pending rank-1 carry left in the table.
 */
final class RankQualificationService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * Run qualification checks for the given month and occurrence number.
     *
     * @return array{rank_1_count: int, rank_2_count: int, rank_3_count: int,
     *               rank_4_count: int, rank_5_count: int, rank_6_count: int,
     *               rank_7_count: int, rank_8_count: int, rank_9_count: int,
     *               total_qualifications: int}
     */
    public function checkForMonth(Carbon $month, int $occurrenceNumber = 1): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $counts = array_fill_keys(
            ['rank_1_count', 'rank_2_count', 'rank_3_count', 'rank_4_count',
                'rank_5_count', 'rank_6_count', 'rank_7_count', 'rank_8_count',
                'rank_9_count', 'total_qualifications'],
            0,
        );

        $personalBvMap = $this->buildPersonalBvMap();
        // This-month personal purchase BV feeds the Ranks 1 & 2 weaker-leg top-up.
        $monthlyPersonalBvMap = $this->buildMonthlyPersonalBvMap($monthStart, $monthEnd);

        $rank1Ids = $this->checkRanks1And2(
            rank: 1,
            monthStart: $monthStart,
            monthEnd: $monthEnd,
            occurrenceNumber: $occurrenceNumber,
            personalBvMap: $personalBvMap,
            monthlyPersonalBvMap: $monthlyPersonalBvMap,
        );
        $counts['rank_1_count'] = count($rank1Ids);

        $rank2Ids = $this->checkRanks1And2(
            rank: 2,
            monthStart: $monthStart,
            monthEnd: $monthEnd,
            occurrenceNumber: $occurrenceNumber,
            personalBvMap: $personalBvMap,
            monthlyPersonalBvMap: $monthlyPersonalBvMap,
        );
        $counts['rank_2_count'] = count($rank2Ids);

        if ($occurrenceNumber === 1) {
            // No carry-forward is created: the "1+2 rule" is retired (KP
            // 2026-08-05, replaced by AO-GO). Reaching Rank 2 still voids any
            // pending Rank-1 carry surviving from before the retirement.
            $this->voidRank1CarryForwardsForRank2Qualifiers($rank2Ids, $monthStart);
        }

        $cascadeMap = [
            3 => 2,
            4 => 3,
            5 => 4,
            6 => 5,
            7 => 6,
            8 => 7,
            9 => 8,
        ];

        $rankQualifierIds = [1 => $rank1Ids, 2 => $rank2Ids];

        foreach (range(3, 9) as $rank) {
            $requiredLowerRank = $cascadeMap[$rank];
            $lowerRankQualifierIds = $rankQualifierIds[$requiredLowerRank] ?? [];

            if (empty($lowerRankQualifierIds)) {
                $rankQualifierIds[$rank] = [];

                continue;
            }

            $newIds = $this->checkHigherRank(
                rank: $rank,
                lowerRankQualifierIds: $lowerRankQualifierIds,
                monthStart: $monthStart,
                occurrenceNumber: $occurrenceNumber,
                personalBvMap: $personalBvMap,
            );

            $rankQualifierIds[$rank] = $newIds;
            $counts['rank_'.$rank.'_count'] = count($newIds);
        }

        $counts['total_qualifications'] = array_sum(array_filter(
            $counts,
            fn (string $key) => str_ends_with($key, '_count') && $key !== 'total_qualifications',
            ARRAY_FILTER_USE_KEY,
        ));

        return $counts;
    }

    /**
     * Build lifetime personal BV map: distributor_id => sum(bv_paise) for type='accrual'.
     *
     * @return array<int, int>
     */
    private function buildPersonalBvMap(): array
    {
        $rows = DB::table('bv_ledger_entries')
            ->where('type', 'accrual')
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
     * Build this-month personal BV map: distributor_id => sum(bv_paise) for
     * type='accrual' entries with effective_at inside the calendar month. Feeds
     * the Ranks 1 & 2 weaker-leg top-up (the top-up uses personal purchases made
     * "during that month", per KP's 2026-06-28 example).
     *
     * @return array<int, int>
     */
    private function buildMonthlyPersonalBvMap(string $monthStart, string $monthEnd): array
    {
        $rows = DB::table('bv_ledger_entries')
            ->where('type', 'accrual')
            ->whereBetween('effective_at', [
                Carbon::parse($monthStart)->startOfDay(),
                Carbon::parse($monthEnd)->endOfDay(),
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
     * Check ranks 1 and 2 (monthly group BV + personal BV title).
     *
     * @param  array<int, int>  $personalBvMap  lifetime personal BV (title gate)
     * @param  array<int, int>  $monthlyPersonalBvMap  this-month personal BV (weaker-leg top-up)
     * @return int[] distributor IDs that newly qualified
     */
    private function checkRanks1And2(
        int $rank,
        string $monthStart,
        string $monthEnd,
        int $occurrenceNumber,
        array $personalBvMap,
        array $monthlyPersonalBvMap,
    ): array {
        $personalBvRequired = $this->plan->rankPersonalBvRequired($rank);
        // Ranks 1-2 always have a group-BV gate; fall back to an impossible
        // threshold if it is ever unset so nobody qualifies by accident.
        $groupBvRequired = $this->plan->rankGroupBvRequired($rank) ?? PHP_INT_MAX;
        // Weaker-leg top-up cap (paise): R1 15,000 BV, R2 30,000 BV, else 0.
        $topupCap = $this->plan->rankWeakerLegTopupBvPaise($rank);

        $groupBvRows = DB::table('group_bv_daily')
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->select(
                'distributor_id',
                DB::raw('SUM(left_bv_paise) as left_bv'),
                DB::raw('SUM(right_bv_paise) as right_bv'),
            )
            ->groupBy('distributor_id')
            ->get();

        $qualifiedIds = [];

        foreach ($groupBvRows as $row) {
            $distributorId = (int) $row->distributor_id;
            $leftBv = (int) $row->left_bv;
            $rightBv = (int) $row->right_bv;
            $personalBv = $personalBvMap[$distributorId] ?? 0;

            if ($personalBv < $personalBvRequired) {
                continue;
            }

            // Weaker-leg personal-BV top-up (KP 2026-06-28, Ranks 1 & 2 only):
            // up to $topupCap of this month's personal purchase BV supplements
            // the weaker leg toward the match. The recorded left/right BV below
            // stays the raw group BV — this only aids the qualification test.
            $topup = min($monthlyPersonalBvMap[$distributorId] ?? 0, $topupCap);
            $effectiveLeft = $leftBv + ($leftBv <= $rightBv ? $topup : 0);
            $effectiveRight = $rightBv + ($leftBv <= $rightBv ? 0 : $topup);

            if ($effectiveLeft < $groupBvRequired || $effectiveRight < $groupBvRequired) {
                continue;
            }

            RankQualification::updateOrCreate(
                [
                    'distributor_id' => $distributorId,
                    'rank_number' => $rank,
                    'month_start' => $monthStart,
                    'occurrence_in_month' => $occurrenceNumber,
                ],
                [
                    'left_genos_bv_paise' => $leftBv,
                    'right_genos_bv_paise' => $rightBv,
                    'is_carry_forward' => false,
                    'status' => RankQualification::STATUS_QUALIFIED,
                ],
            );

            $qualifiedIds[] = $distributorId;
        }

        return $qualifiedIds;
    }

    /**
     * Check higher ranks (3-9) by counting prerequisite-rank qualifiers on each
     * side of the Genos tree for each candidate distributor, then applying the
     * candidate's OWN Q-Period promotion gate (KP 2026-08-05): two qualified
     * partners per side are not enough — the candidate must personally have
     * achieved rank r-1 the required number of times.
     *
     * Uses the same side-detection query as GroupBvAccumulatorService.
     *
     * @param  int[]  $lowerRankQualifierIds
     * @param  array<int, int>  $personalBvMap
     * @return int[]
     */
    private function checkHigherRank(
        int $rank,
        array $lowerRankQualifierIds,
        string $monthStart,
        int $occurrenceNumber,
        array $personalBvMap,
    ): array {
        if (empty($lowerRankQualifierIds)) {
            return [];
        }

        $personalBvRequired = $this->plan->rankPersonalBvRequired($rank);
        $qualifiersPerSide = $this->plan->rankStructuralQualifiersPerSide($rank);

        $rows = DB::table('genealogy_closure as gc_anc')
            ->join('genealogy_closure as gc_child', function ($join): void {
                $join->on('gc_child.descendant_id', '=', 'gc_anc.descendant_id')
                    ->whereRaw('gc_child.depth = gc_anc.depth - 1');
            })
            ->join('distributors as dc', function ($join): void {
                $join->on('dc.id', '=', 'gc_child.ancestor_id')
                    ->on('dc.placement_parent_id', '=', 'gc_anc.ancestor_id');
            })
            ->whereIn('gc_anc.descendant_id', $lowerRankQualifierIds)
            ->where('gc_anc.depth', '>', 0)
            ->whereIn('dc.placement_side', ['L', 'R'])
            ->select('gc_anc.ancestor_id', 'gc_anc.descendant_id', 'dc.placement_side as side')
            ->get();

        /** @var array<int, array{L: int, R: int}> $sideCountMap */
        $sideCountMap = [];
        foreach ($rows as $row) {
            $ancestorId = (int) $row->ancestor_id;
            $side = $row->side;
            $sideCountMap[$ancestorId] ??= ['L' => 0, 'R' => 0];
            $sideCountMap[$ancestorId][$side]++;
        }

        // The candidate's own prior-rank Q-Period: lower-rank rows for THIS
        // month were already written by the cascade, so the current month
        // counts toward the gate.
        $qPeriodRequired = $this->plan->rankPypRequired($rank - 1);
        $qPeriodCounts = $this->qPeriodCounts(array_keys($sideCountMap), $rank - 1, $monthStart);

        $qualifiedIds = [];

        foreach ($sideCountMap as $distributorId => $sides) {
            if ($sides['L'] < $qualifiersPerSide || $sides['R'] < $qualifiersPerSide) {
                continue;
            }
            $personalBv = $personalBvMap[$distributorId] ?? 0;
            if ($personalBv < $personalBvRequired) {
                continue;
            }
            if (($qPeriodCounts[$distributorId] ?? 0) < $qPeriodRequired) {
                continue;
            }

            RankQualification::updateOrCreate(
                [
                    'distributor_id' => $distributorId,
                    'rank_number' => $rank,
                    'month_start' => $monthStart,
                    'occurrence_in_month' => $occurrenceNumber,
                ],
                [
                    'left_genos_bv_paise' => null,
                    'right_genos_bv_paise' => null,
                    'is_carry_forward' => false,
                    'status' => RankQualification::STATUS_QUALIFIED,
                ],
            );

            $qualifiedIds[] = $distributorId;
        }

        return $qualifiedIds;
    }

    /**
     * Q-Period (a.k.a. PYP / qualified count) per distributor for a rank:
     * qualified, non-carry-forward occurrences up to and including
     * $uptoMonthStart.
     *
     * Counting window = Option C, KP confirmed 2026-08-07 ("lifetime total is
     * 100% correct"): every qualified occurrence counts, whenever it happened
     * and however many fell in one month; once the count is reached the next
     * rank opens permanently. (Supersedes the tentative Option B shipped
     * 2026-08-05, which counted distinct months.)
     *
     * @param  int[]  $distributorIds
     * @return array<int, int>
     */
    private function qPeriodCounts(array $distributorIds, int $rank, string $uptoMonthStart): array
    {
        if ($distributorIds === []) {
            return [];
        }

        $rows = DB::table('rank_qualifications')
            ->whereIn('distributor_id', $distributorIds)
            ->where('rank_number', $rank)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->where('is_carry_forward', false)
            ->where('month_start', '<=', $uptoMonthStart)
            ->groupBy('distributor_id')
            ->selectRaw('distributor_id, COUNT(*) as achieved_months')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = (int) $row->achieved_months;
        }

        return $map;
    }

    /**
     * When a distributor achieves rank 2, void any pending rank-1 carry-forwards
     * for M+1 and M+2 that originated from an earlier source month.
     *
     * No new carry-forwards are created since the 1+2 rule was retired, so this
     * only ever touches pre-retirement rows.
     *
     * @param  int[]  $rank2DistributorIds
     */
    private function voidRank1CarryForwardsForRank2Qualifiers(
        array $rank2DistributorIds,
        string $currentMonth,
    ): void {
        if (empty($rank2DistributorIds)) {
            return;
        }

        $source = Carbon::parse($currentMonth);
        $futureMonths = [
            $source->copy()->addMonth()->startOfMonth()->toDateString(),
            $source->copy()->addMonths(2)->startOfMonth()->toDateString(),
        ];

        RankQualification::whereIn('distributor_id', $rank2DistributorIds)
            ->where('rank_number', 1)
            ->whereIn('month_start', $futureMonths)
            ->where('is_carry_forward', true)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->update(['status' => RankQualification::STATUS_VOIDED]);
    }
}
