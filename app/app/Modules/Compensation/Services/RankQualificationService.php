<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checks rank qualification for a given calendar month.
 *
 * Run once per month for occurrence 1 (standard qualification).
 * Run up to 2 more times in the same month for PYP (ranks 3-9).
 *
 * Cascade order: ranks 1-2 from raw BV, ranks 3-9 from prior rank qualifiers.
 * The 1+2 rule: qualifying at rank 1 or 2 creates carry-forward records
 * for M+1 and M+2. A rank-2 qualification voids any pending rank-1 carry-forwards.
 */
final class RankQualificationService
{
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

        $rank1Ids = $this->checkRanks1And2(
            rank: 1,
            monthStart: $monthStart,
            monthEnd: $monthEnd,
            occurrenceNumber: $occurrenceNumber,
            personalBvMap: $personalBvMap,
        );
        $counts['rank_1_count'] = count($rank1Ids);

        $rank2Ids = $this->checkRanks1And2(
            rank: 2,
            monthStart: $monthStart,
            monthEnd: $monthEnd,
            occurrenceNumber: $occurrenceNumber,
            personalBvMap: $personalBvMap,
        );
        $counts['rank_2_count'] = count($rank2Ids);

        if ($occurrenceNumber === 1) {
            $this->createCarryForwards($rank1Ids, rank: 1, sourceMonth: $monthStart);
            $this->createCarryForwards($rank2Ids, rank: 2, sourceMonth: $monthStart);
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
     * Check ranks 1 and 2 (monthly group BV + personal BV title).
     *
     * @param  array<int, int>  $personalBvMap
     * @return int[] distributor IDs that newly qualified
     */
    private function checkRanks1And2(
        int $rank,
        string $monthStart,
        string $monthEnd,
        int $occurrenceNumber,
        array $personalBvMap,
    ): array {
        $personalBvRequired = RankQualification::PERSONAL_BV_REQUIRED[$rank];
        $groupBvRequired = RankQualification::GROUP_BV_REQUIRED[$rank];

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
            if ($leftBv < $groupBvRequired || $rightBv < $groupBvRequired) {
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
     * side of the Genos tree for each candidate distributor.
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

        $personalBvRequired = RankQualification::PERSONAL_BV_REQUIRED[$rank];

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

        $qualifiedIds = [];

        foreach ($sideCountMap as $distributorId => $sides) {
            if ($sides['L'] < 2 || $sides['R'] < 2) {
                continue;
            }
            $personalBv = $personalBvMap[$distributorId] ?? 0;
            if ($personalBv < $personalBvRequired) {
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
     * Create carry-forward qualification records for M+1 and M+2.
     * Idempotent: skips if a record already exists for that month.
     *
     * @param  int[]  $distributorIds
     */
    private function createCarryForwards(array $distributorIds, int $rank, string $sourceMonth): void
    {
        if (empty($distributorIds)) {
            return;
        }

        $source = Carbon::parse($sourceMonth);

        foreach ([1, 2] as $offset) {
            $targetMonth = $source->copy()->addMonths($offset)->startOfMonth()->toDateString();

            foreach ($distributorIds as $distributorId) {
                $alreadyExists = RankQualification::where('distributor_id', $distributorId)
                    ->where('rank_number', $rank)
                    ->where('month_start', $targetMonth)
                    ->where('is_carry_forward', true)
                    ->where('carry_forward_from_month', $sourceMonth)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                RankQualification::create([
                    'distributor_id' => $distributorId,
                    'rank_number' => $rank,
                    'month_start' => $targetMonth,
                    'occurrence_in_month' => 1,
                    'is_carry_forward' => true,
                    'carry_forward_from_month' => $sourceMonth,
                    'status' => RankQualification::STATUS_QUALIFIED,
                ]);
            }
        }
    }

    /**
     * When a distributor achieves rank 2, void any pending rank-1 carry-forwards
     * for M+1 and M+2 that originated from an earlier source month.
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
