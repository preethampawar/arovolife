<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Shared\Support\Money;

/**
 * Pure Fortune Bonus cascade allocator (KP 2026-08-09). No DB, no clock —
 * everything it needs arrives as arguments, so KP's full ₹36cr worked example
 * is unit-testable and a frozen month's allocation is exactly reproducible.
 *
 * The minimum commission (₹30 × qualifiers) is reserved off the pool first;
 * every income below already includes it. Then absolute matrix levels pay in
 * three modes:
 *
 *  - capped:   value = floor_rupee(remaining pool ÷ ALL remaining points),
 *              recomputed per level ascending; a member earns
 *              min(points × value, cap − minimum) on top of the minimum.
 *              The cap INCLUDES the minimum ("₹30,000 including their ₹30").
 *  - residual: ONE shared value computed over ALL residual levels' combined
 *              points after the capped levels are settled; no cap.
 *  - flat_min: the minimum only (the bottom of the matrix).
 *
 * When the pool cannot cover the guarantees, everyone gets
 * floor_rupee(pool ÷ qualifiers) and nothing else (user decision 2026-08-09
 * — the pool is never overspent). Flooring remainders stay as leftover.
 */
final class FortuneDistributionCalculator
{
    private const string MODE_RESIDUAL = 'residual';

    private const string MODE_FLAT_MIN = 'flat_min';

    /**
     * @param  array<int, array{position: int, matrix_level: int, points: int}>  $participants
     * @param  array<int, array{payout_mode: string, cap_paise: ?int, points_per_member: int}>  $levelConfigs  keyed by absolute matrix level
     * @return array{
     *   incomes: array<int, int>,
     *   levels: array<int, array{payout_mode: string, cap_paise: ?int, participants: int, points: int, point_value_paise: int, paid_paise: int}>,
     *   guaranteed_total_paise: int,
     *   leftover_paise: int,
     *   is_shortfall: bool,
     *   shortfall_per_head_paise: ?int,
     * }
     */
    public function allocate(array $participants, int $poolPaise, int $minCommissionPaise, array $levelConfigs): array
    {
        $pool = max(0, $poolPaise);
        $count = count($participants);

        if ($count === 0) {
            return [
                'incomes' => [],
                'levels' => [],
                'guaranteed_total_paise' => 0,
                'leftover_paise' => $pool,
                'is_shortfall' => false,
                'shortfall_per_head_paise' => null,
            ];
        }

        $byLevel = $this->groupByLevel($participants);
        $guaranteedTotal = $minCommissionPaise * $count;

        if ($pool < $guaranteedTotal) {
            return $this->allocateShortfall($byLevel, $pool, $count, $guaranteedTotal, $levelConfigs);
        }

        $remaining = $pool - $guaranteedTotal;
        $remainingPoints = 0;
        foreach ($participants as $participant) {
            $remainingPoints += $participant['points'];
        }

        /** @var array<int, int> $incomes */
        $incomes = [];
        $levels = [];
        /** @var list<int> $residualLevels */
        $residualLevels = [];

        // Capped levels settle ascending, each against a value recomputed over
        // ALL points not yet paid (KP's example: 13/13/14/15/16/18/19).
        // Residual and flat levels are only collected here and settle after.
        foreach ($byLevel as $level => $members) {
            $mode = $levelConfigs[$level]['payout_mode'] ?? 'capped';

            if ($mode === self::MODE_RESIDUAL) {
                $residualLevels[] = $level;

                continue;
            }

            if ($mode === self::MODE_FLAT_MIN) {
                $paid = 0;
                $levelPoints = 0;
                foreach ($members as $member) {
                    $incomes[$member['position']] = $minCommissionPaise;
                    $paid += $minCommissionPaise;
                    $levelPoints += $member['points'];
                }
                $remainingPoints -= $levelPoints;
                $levels[$level] = $this->levelRow($mode, null, count($members), $levelPoints, 0, $paid);

                continue;
            }

            $cap = $levelConfigs[$level]['cap_paise'] ?? null;
            $pointEarnCeiling = $cap === null ? PHP_INT_MAX : max(0, $cap - $minCommissionPaise);
            $value = Money::floorRupee($remaining, $remainingPoints);

            $paid = 0;
            $levelPoints = 0;
            foreach ($members as $member) {
                $pointEarn = min($member['points'] * $value, $pointEarnCeiling);
                $incomes[$member['position']] = $minCommissionPaise + $pointEarn;
                $remaining -= $pointEarn;
                $paid += $minCommissionPaise + $pointEarn;
                $levelPoints += $member['points'];
            }
            $remainingPoints -= $levelPoints;
            $levels[$level] = $this->levelRow('capped', $cap, count($members), $levelPoints, $value, $paid);
        }

        // Residual levels share ONE value over their combined points — KP's
        // example prices L7 and L8 together at ₹20, not per level.
        if ($residualLevels !== []) {
            $residualPoints = 0;
            foreach ($residualLevels as $level) {
                foreach ($byLevel[$level] as $member) {
                    $residualPoints += $member['points'];
                }
            }

            $value = Money::floorRupee($remaining, $residualPoints);

            foreach ($residualLevels as $level) {
                $paid = 0;
                $levelPoints = 0;
                foreach ($byLevel[$level] as $member) {
                    $pointEarn = $member['points'] * $value;
                    $incomes[$member['position']] = $minCommissionPaise + $pointEarn;
                    $paid += $minCommissionPaise + $pointEarn;
                    $levelPoints += $member['points'];
                }
                $levels[$level] = $this->levelRow(self::MODE_RESIDUAL, null, count($byLevel[$level]), $levelPoints, $value, $paid);
            }
        }

        ksort($levels);

        return [
            'incomes' => $incomes,
            'levels' => $levels,
            'guaranteed_total_paise' => $guaranteedTotal,
            'leftover_paise' => $pool - array_sum($incomes),
            'is_shortfall' => false,
            'shortfall_per_head_paise' => null,
        ];
    }

    /**
     * The pool cannot cover the ₹30 guarantees: every qualifier gets the same
     * whole-rupee share of the pool and nothing else.
     *
     * @param  array<int, list<array{position: int, matrix_level: int, points: int}>>  $byLevel
     * @param  array<int, array{payout_mode: string, cap_paise: ?int, points_per_member: int}>  $levelConfigs
     * @return array{incomes: array<int, int>, levels: array<int, array{payout_mode: string, cap_paise: ?int, participants: int, points: int, point_value_paise: int, paid_paise: int}>, guaranteed_total_paise: int, leftover_paise: int, is_shortfall: bool, shortfall_per_head_paise: ?int}
     */
    private function allocateShortfall(array $byLevel, int $pool, int $count, int $guaranteedTotal, array $levelConfigs): array
    {
        $perHead = Money::floorRupee($pool, $count);

        $incomes = [];
        $levels = [];

        foreach ($byLevel as $level => $members) {
            $paid = 0;
            $levelPoints = 0;
            foreach ($members as $member) {
                $incomes[$member['position']] = $perHead;
                $paid += $perHead;
                $levelPoints += $member['points'];
            }
            $levels[$level] = $this->levelRow(
                $levelConfigs[$level]['payout_mode'] ?? 'capped',
                $levelConfigs[$level]['cap_paise'] ?? null,
                count($members),
                $levelPoints,
                0,
                $paid,
            );
        }

        return [
            'incomes' => $incomes,
            'levels' => $levels,
            'guaranteed_total_paise' => $guaranteedTotal,
            'leftover_paise' => $pool - $perHead * $count,
            'is_shortfall' => true,
            'shortfall_per_head_paise' => $perHead,
        ];
    }

    /**
     * @param  array<int, array{position: int, matrix_level: int, points: int}>  $participants
     * @return array<int, list<array{position: int, matrix_level: int, points: int}>> keyed by matrix level, ascending
     */
    private function groupByLevel(array $participants): array
    {
        $byLevel = [];
        foreach ($participants as $participant) {
            $byLevel[$participant['matrix_level']][] = $participant;
        }
        ksort($byLevel);

        return $byLevel;
    }

    /** @return array{payout_mode: string, cap_paise: ?int, participants: int, points: int, point_value_paise: int, paid_paise: int} */
    private function levelRow(string $mode, ?int $capPaise, int $participants, int $points, int $valuePaise, int $paidPaise): array
    {
        return [
            'payout_mode' => $mode,
            'cap_paise' => $capPaise,
            'participants' => $participants,
            'points' => $points,
            'point_value_paise' => $valuePaise,
            'paid_paise' => $paidPaise,
        ];
    }
}
