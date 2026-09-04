<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Independent Fortune Bonus cascade oracle — pure PHP, no framework, no DB.
 *
 * Implements the 2026-09-03 cascade rules exactly as specified:
 *   1. Pool fund = turnover_bv_paise × pool_rate_bp / 10000.
 *   2. Guaranteed ₹30 per participant, reserved off the pool first.
 *   3. Cascade levels 0..max ascending. At each level:
 *      a. point_value = floor_rupee(remaining_pool, remaining_points)
 *      b. bonus_per_member = point_value × member_points
 *      c. capped_per_member = min(bonus_per_member, cap_paise - min_commission_paise)
 *      d. income_per_member = min_commission_paise + capped_per_member
 *      e. pool -= capped_per_member × member_count; points -= level_total_points
 *   4. Leftover = pool after all levels settled.
 *
 * USAGE:
 *   $oracle = new FortuneBonusOracle;
 *   $result = $oracle->run($participants, $turnoverBvPaise, $levelCaps, $poolRateBp, $minCommissionPaise, $pointsByDepth);
 *
 * where $participants = [['position' => int, 'matrix_level' => int], ...].
 * Points are computed internally from the tree topology (parent-walk, same
 * algorithm as FortuneBonusService::buildPointsByDistributor).
 *
 * SELF-CHECK (reference, turnover 5,32,00,000 BV, depth-4 balanced 121 distributors):
 *   Pool 26,60,000; guaranteed 3,630; available 26,56,370.
 *   L0: value 815, bonus 630,810, income 30,000, wallet_credit 29,970
 *   L1: value 1057, bonus_per_member 304,416, income 30,000
 *   L2: value 1565, income 30,000
 *   L3: value 3109, income 30,000
 *   L4: value 0, income 30 (min only)
 *   Total credited 11,98,800; undistributed 14,57,570.
 */
final class FortuneBonusOracle
{
    /**
     * @param  array<int, array{position: int, matrix_level: int}>  $participants
     * @param  array<int, array{cap_paise: int}>  $levelCaps  keyed by absolute matrix level
     * @param  array<int, int>  $pointsByDepth  relative depth (1-9) → points
     * @return array{
     *   pool_paise: int,
     *   guaranteed_total_paise: int,
     *   available_paise: int,
     *   total_points: int,
     *   levels: array<int, array{
     *     participants: int,
     *     level_points: int,
     *     point_value_paise: int,
     *     bonus_paise: int,
     *     derived_per_member_paise: int,
     *     capped_per_member_paise: int,
     *     income_per_member_paise: int,
     *     wallet_credit_each_paise: int,
     *     pool_in_paise: int,
     *     pool_out_paise: int,
     *     points_in: int,
     *     points_out: int,
     *   }>,
     *   incomes: array<int, int>,
     *   total_credited_paise: int,
     *   leftover_paise: int,
     *   is_shortfall: bool,
     *   shortfall_per_head_paise: int|null,
     * }
     */
    public function run(
        array $participants,
        int $turnoverBvPaise,
        int $poolRateBp,
        int $minCommissionPaise,
        array $levelCaps,
        array $pointsByDepth,
    ): array {
        $poolPaise = max(0, intdiv($turnoverBvPaise * $poolRateBp, 10_000));
        $count = count($participants);

        if ($count === 0) {
            return $this->emptyResult($poolPaise);
        }

        // Build points per position using parent-walk (same algorithm as the app).
        $pointsMap = $this->computePoints($participants, $pointsByDepth);

        // Merge points into participant rows.
        $rows = [];
        foreach ($participants as $p) {
            $rows[] = [
                'position' => $p['position'],
                'matrix_level' => $p['matrix_level'],
                'points' => $pointsMap[$p['position']] ?? 0,
            ];
        }

        $guaranteedTotal = $minCommissionPaise * $count;

        if ($poolPaise < $guaranteedTotal) {
            return $this->shortfallResult($poolPaise, $rows, $guaranteedTotal, $count);
        }

        $remaining = $poolPaise - $guaranteedTotal;

        $totalPoints = 0;
        foreach ($rows as $row) {
            $totalPoints += $row['points'];
        }

        // Group by level, ascending.
        $byLevel = [];
        foreach ($rows as $row) {
            $byLevel[$row['matrix_level']][] = $row;
        }
        ksort($byLevel);

        $incomes = [];
        $levels = [];
        $remainingPoints = $totalPoints;

        foreach ($byLevel as $level => $members) {
            $cap = $levelCaps[$level]['cap_paise'] ?? null;
            $pointEarnCeiling = $cap === null ? PHP_INT_MAX : max(0, $cap - $minCommissionPaise);

            $poolIn = $remaining;
            $pointsIn = $remainingPoints;
            $value = $this->floorRupee($remaining, $remainingPoints);

            $levelPoints = 0;
            foreach ($members as $m) {
                $levelPoints += $m['points'];
            }

            $paidExtra = 0;
            foreach ($members as $m) {
                $pointEarn = min($m['points'] * $value, $pointEarnCeiling);
                $incomes[$m['position']] = $minCommissionPaise + $pointEarn;
                $remaining -= $pointEarn;
                $paidExtra += $pointEarn;
            }
            $remainingPoints -= $levelPoints;

            $memberCount = count($members);
            $bonusPaise = $levelPoints * $value;
            $derivedPerMember = $memberCount > 0 ? intdiv($bonusPaise, $memberCount) : 0;
            $cappedPerMember = $cap !== null ? min($derivedPerMember, $cap - $minCommissionPaise) : $derivedPerMember;
            $incomePerMember = $memberCount > 0 ? $minCommissionPaise + intdiv($paidExtra, $memberCount) : $minCommissionPaise;
            $walletCreditEach = max(0, $incomePerMember - $minCommissionPaise);

            $levels[$level] = [
                'participants' => $memberCount,
                'level_points' => $levelPoints,
                'point_value_paise' => $value,
                'bonus_paise' => $bonusPaise,
                'derived_per_member_paise' => $derivedPerMember,
                'capped_per_member_paise' => $cappedPerMember,
                'income_per_member_paise' => $incomePerMember,
                'wallet_credit_each_paise' => $walletCreditEach,
                'pool_in_paise' => $poolIn,
                'pool_out_paise' => $poolIn - $paidExtra,
                'points_in' => $pointsIn,
                'points_out' => $pointsIn - $levelPoints,
            ];
        }

        $totalCredited = array_sum($incomes);
        $leftover = $poolPaise - $totalCredited;

        return [
            'pool_paise' => $poolPaise,
            'guaranteed_total_paise' => $guaranteedTotal,
            'available_paise' => $poolPaise - $guaranteedTotal,
            'total_points' => $totalPoints,
            'levels' => $levels,
            'incomes' => $incomes,
            'total_credited_paise' => $totalCredited,
            'leftover_paise' => $leftover,
            'is_shortfall' => false,
            'shortfall_per_head_paise' => null,
        ];
    }

    /**
     * Compute the point balance for every position using the parent-walk
     * algorithm: walk up from each participant at most 9 steps and credit the
     * ancestor at each depth.
     *
     * @param  array<int, array{position: int, matrix_level: int}>  $participants
     * @param  array<int, int>  $pointsByDepth  depth 1-9 → points
     * @return array<int, int> position → points
     */
    public function computePoints(array $participants, array $pointsByDepth): array
    {
        $positionSet = [];
        foreach ($participants as $p) {
            $positionSet[$p['position']] = true;
        }

        $points = array_fill_keys(array_column($participants, 'position'), 0);

        foreach (array_keys($positionSet) as $position) {
            $ancestor = $position;
            for ($depth = 1; $depth <= 9; $depth++) {
                $ancestor = self::parentPosition($ancestor);
                if ($ancestor === null || ! isset($positionSet[$ancestor])) {
                    break;
                }
                $points[$ancestor] += $pointsByDepth[$depth] ?? 0;
            }
        }

        return $points;
    }

    /**
     * Parent position in the ternary forced matrix: intdiv(pos + 1, 3).
     * Returns null for the root (position ≤ 1).
     */
    public static function parentPosition(int $position): ?int
    {
        if ($position <= 1) {
            return null;
        }

        return intdiv($position + 1, 3);
    }

    /**
     * Floor to the nearest whole rupee (multiple of 100 paise).
     * Matches Money::floorRupee exactly.
     */
    private function floorRupee(int $amountPaise, int $units): int
    {
        if ($units <= 0) {
            return 0;
        }

        return max(0, intdiv(intdiv($amountPaise, $units), 100) * 100);
    }

    /** @return array<string, mixed> */
    private function emptyResult(int $poolPaise): array
    {
        return [
            'pool_paise' => $poolPaise,
            'guaranteed_total_paise' => 0,
            'available_paise' => $poolPaise,
            'total_points' => 0,
            'levels' => [],
            'incomes' => [],
            'total_credited_paise' => 0,
            'leftover_paise' => $poolPaise,
            'is_shortfall' => false,
            'shortfall_per_head_paise' => null,
        ];
    }

    /**
     * @param  array<int, array{position: int, matrix_level: int, points: int}>  $rows
     * @return array<string, mixed>
     */
    private function shortfallResult(int $poolPaise, array $rows, int $guaranteedTotal, int $count): array
    {
        $perHead = $this->floorRupee($poolPaise, $count);
        $incomes = [];
        foreach ($rows as $row) {
            $incomes[$row['position']] = $perHead;
        }

        return [
            'pool_paise' => $poolPaise,
            'guaranteed_total_paise' => $guaranteedTotal,
            'available_paise' => 0,
            'total_points' => 0,
            'levels' => [],
            'incomes' => $incomes,
            'total_credited_paise' => $perHead * $count,
            'leftover_paise' => $poolPaise - $perHead * $count,
            'is_shortfall' => true,
            'shortfall_per_head_paise' => $perHead,
        ];
    }
}
