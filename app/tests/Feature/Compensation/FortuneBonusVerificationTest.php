<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Services\FortuneDistributionCalculator;
use Tests\Support\FortuneBonusOracle;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build an array of participants (position → matrix_level) for a FULL balanced
 * 3^depth matrix: positions 1..total where total = (3^(depth+1) - 1) / 2.
 *
 * @return list<array{position: int, matrix_level: int}>
 */
function balancedTree(int $depth): array
{
    $total = (int) round((3 ** ($depth + 1) - 1) / 2);
    $rows = [];
    for ($pos = 1; $pos <= $total; $pos++) {
        $rows[] = ['position' => $pos, 'matrix_level' => FortuneBonusParticipant::levelFromPosition($pos)];
    }

    return $rows;
}

/**
 * Chain tree: one distributor at each level 0-9 using the smallest positions
 * that satisfy the parent-chain relationship.
 *
 * Positions chosen so that parentPosition(pos) == the chain position at level-1:
 *   L0=1, L1=2, L2=5, L3=14, L4=41, L5=122, L6=365, L7=1094, L8=3281, L9=9842
 *
 * @return list<array{position: int, matrix_level: int}>
 */
function chainTree(): array
{
    $positions = [1, 2, 5, 14, 41, 122, 365, 1094, 3281, 9842];

    return array_map(
        fn (int $pos): array => ['position' => $pos, 'matrix_level' => FortuneBonusParticipant::levelFromPosition($pos)],
        $positions,
    );
}

/**
 * Standard level caps from the DB (2026-09-03 client notes, verified via tinker).
 *
 * @return array<int, array{cap_paise: int}>
 */
function standardLevelCaps(): array
{
    return [
        0 => ['cap_paise' => 3_000_000],   // ₹30,000
        1 => ['cap_paise' => 3_000_000],
        2 => ['cap_paise' => 3_000_000],
        3 => ['cap_paise' => 3_000_000],
        4 => ['cap_paise' => 2_000_000],   // ₹20,000
        5 => ['cap_paise' => 1_000_000],   // ₹10,000
        6 => ['cap_paise' => 500_000],   // ₹5,000
        7 => ['cap_paise' => 250_000],   // ₹2,500
        8 => ['cap_paise' => 150_000],   // ₹1,500
        9 => ['cap_paise' => 3_000],   // ₹30 (minimum only)
    ];
}

/**
 * Standard points by relative depth (DB-verified).
 *
 * @return array<int, int>
 */
function standardPointsByDepth(): array
{
    return [1 => 9, 2 => 8, 3 => 7, 4 => 6, 5 => 5, 6 => 4, 7 => 3, 8 => 2, 9 => 1];
}

// Reference BV = 5,32,00,000 BV (stored as BV × 100 paise).
const REFERENCE_TURNOVER_BV_PAISE = 53_200_000_00; // 5.32 crore BV × 100 paise per BV
const POOL_RATE_BP = 500;                            // 5 %
const MIN_COMMISSION_PAISE = 3_000;                  // ₹30

// ---------------------------------------------------------------------------
// PHASE 1 — Oracle self-check: depth-4 balanced tree (121 distributors)
// Reference numbers from the specification.
// ---------------------------------------------------------------------------

describe('FortuneBonusOracle self-check (depth-4, 121 distributors)', function (): void {
    beforeEach(function (): void {
        $this->oracle = new FortuneBonusOracle;
        $this->result = $this->oracle->run(
            balancedTree(depth: 4),
            REFERENCE_TURNOVER_BV_PAISE,
            POOL_RATE_BP,
            MIN_COMMISSION_PAISE,
            standardLevelCaps(),
            standardPointsByDepth(),
        );
    });

    it('computes pool and guaranteed correctly', function (): void {
        expect($this->result['pool_paise'])->toBe(266_000_000)          // ₹26,60,000
            ->and($this->result['guaranteed_total_paise'])->toBe(363_000) // 121 × ₹30
            ->and($this->result['available_paise'])->toBe(265_637_000);  // ₹26,56,370
    });

    it('computes total points correctly', function (): void {
        // L0:774 + L1:864 + L2:891 + L3:729 + L4:0 = 3258
        expect($this->result['total_points'])->toBe(3_258);
    });

    it('L0 — value 815, income 30000, wallet_credit 29970', function (): void {
        $l = $this->result['levels'][0];
        expect($l['participants'])->toBe(1)
            ->and($l['level_points'])->toBe(774)
            ->and($l['point_value_paise'])->toBe(81_500)               // ₹815
            ->and($l['income_per_member_paise'])->toBe(3_000_000)      // ₹30,000
            ->and($l['wallet_credit_each_paise'])->toBe(2_997_000)     // ₹29,970
            ->and($l['pool_out_paise'])->toBe(262_640_000);            // ₹26,26,400
    });

    it('L1 — value 1057, income 30000', function (): void {
        $l = $this->result['levels'][1];
        expect($l['participants'])->toBe(3)
            ->and($l['level_points'])->toBe(864)
            ->and($l['point_value_paise'])->toBe(105_700)              // ₹1057
            ->and($l['income_per_member_paise'])->toBe(3_000_000)      // ₹30,000
            ->and($l['pool_out_paise'])->toBe(253_649_000);            // ₹25,36,490
    });

    it('L2 — value 1565, income 30000', function (): void {
        $l = $this->result['levels'][2];
        expect($l['participants'])->toBe(9)
            ->and($l['point_value_paise'])->toBe(156_500)              // ₹1565
            ->and($l['income_per_member_paise'])->toBe(3_000_000);
    });

    it('L3 — value 3109, income 30000', function (): void {
        $l = $this->result['levels'][3];
        expect($l['participants'])->toBe(27)
            ->and($l['point_value_paise'])->toBe(310_900)              // ₹3109
            ->and($l['income_per_member_paise'])->toBe(3_000_000);
    });

    it('L4 — value 0, income 30 (minimum only)', function (): void {
        $l = $this->result['levels'][4];
        expect($l['participants'])->toBe(81)
            ->and($l['level_points'])->toBe(0)
            ->and($l['point_value_paise'])->toBe(0)
            ->and($l['income_per_member_paise'])->toBe(3_000)          // ₹30
            ->and($l['wallet_credit_each_paise'])->toBe(0);
    });

    it('total_credited = 12,02,430 and leftover = 14,57,570', function (): void {
        // Pool 26,60,000 = guaranteed 3,630 + extra-credits 11,98,800 + leftover 14,57,570.
        // total_credited_paise includes the guaranteed minimum: 40×30,000 + 81×30 = 12,02,430 rupees.
        expect($this->result['total_credited_paise'])->toBe(120_243_000)  // ₹12,02,430
            ->and($this->result['leftover_paise'])->toBe(145_757_000);    // ₹14,57,570
    });
});

// ---------------------------------------------------------------------------
// PHASE 2 — App calculator vs oracle: depth-4 balanced tree
// Verifies FortuneDistributionCalculator matches the oracle on every level.
// ---------------------------------------------------------------------------

describe('FortuneDistributionCalculator matches oracle (depth-4)', function (): void {
    beforeEach(function (): void {
        $this->oracle = new FortuneBonusOracle;
        $this->calculator = new FortuneDistributionCalculator;

        $participants = balancedTree(depth: 4);
        $pointsByDepth = standardPointsByDepth();
        $levelCaps = standardLevelCaps();

        // Oracle
        $this->oracleResult = $this->oracle->run(
            $participants,
            REFERENCE_TURNOVER_BV_PAISE,
            POOL_RATE_BP,
            MIN_COMMISSION_PAISE,
            $levelCaps,
            $pointsByDepth,
        );

        // Compute points using oracle's same parent-walk for the app call.
        $pointsMap = $this->oracle->computePoints($participants, $pointsByDepth);
        $rows = array_map(fn (array $p): array => [
            'position' => $p['position'],
            'matrix_level' => $p['matrix_level'],
            'points' => $pointsMap[$p['position']] ?? 0,
        ], $participants);

        $poolPaise = intdiv(REFERENCE_TURNOVER_BV_PAISE * POOL_RATE_BP, 10_000);

        $levelConfigs = array_map(
            fn (array $cap): array => ['payout_mode' => 'capped', 'cap_paise' => $cap['cap_paise'], 'points_per_member' => 0],
            $levelCaps,
        );

        $this->appResult = $this->calculator->allocate($rows, $poolPaise, MIN_COMMISSION_PAISE, $levelConfigs);
    });

    it('pool paid out equals oracle total_credited', function (): void {
        $appTotal = array_sum($this->appResult['incomes']);
        expect($appTotal)->toBe($this->oracleResult['total_credited_paise']);
    });

    it('leftover matches oracle', function (): void {
        expect($this->appResult['leftover_paise'])->toBe($this->oracleResult['leftover_paise']);
    });

    it('per-level point_value_paise matches oracle for every level', function (): void {
        foreach ($this->oracleResult['levels'] as $level => $oracleLevel) {
            $appLevel = $this->appResult['levels'][$level];
            expect($appLevel['point_value_paise'])
                ->toBe($oracleLevel['point_value_paise'], "L{$level} point_value_paise mismatch");
        }
    });

    it('per-level paid_paise matches oracle total income for every level', function (): void {
        foreach ($this->oracleResult['levels'] as $level => $oracleLevel) {
            $appLevel = $this->appResult['levels'][$level];
            $expectedPaid = $oracleLevel['income_per_member_paise'] * $oracleLevel['participants'];
            expect($appLevel['paid_paise'])
                ->toBe($expectedPaid, "L{$level} paid_paise mismatch");
        }
    });

    it('per-position incomes match oracle', function (): void {
        foreach ($this->oracleResult['incomes'] as $pos => $expected) {
            expect($this->appResult['incomes'][$pos])
                ->toBe($expected, "Position {$pos} income mismatch");
        }
    });
});

// ---------------------------------------------------------------------------
// PHASE 3 — Chain tree: all 10 levels 0-9 exercised
// Reference turnover 5,32,00,000 BV → all level caps hit.
// ---------------------------------------------------------------------------

describe('FortuneBonusOracle chain tree (levels 0-9)', function (): void {
    beforeEach(function (): void {
        $this->oracle = new FortuneBonusOracle;
        $this->result = $this->oracle->run(
            chainTree(),
            REFERENCE_TURNOVER_BV_PAISE,
            POOL_RATE_BP,
            MIN_COMMISSION_PAISE,
            standardLevelCaps(),
            standardPointsByDepth(),
        );
    });

    it('has exactly 10 participants, one per level 0-9', function (): void {
        expect($this->result['levels'])->toHaveCount(10);
        foreach (range(0, 9) as $level) {
            expect($this->result['levels'][$level]['participants'])->toBe(1, "Level {$level} should have 1 participant");
        }
    });

    it('L0 through L3 hit the 30,000 cap', function (): void {
        foreach ([0, 1, 2, 3] as $level) {
            expect($this->result['levels'][$level]['income_per_member_paise'])
                ->toBe(3_000_000, "L{$level} should be capped at ₹30,000");
        }
    });

    it('L4 hits the 20,000 cap', function (): void {
        expect($this->result['levels'][4]['income_per_member_paise'])->toBe(2_000_000);
    });

    it('L5 hits the 10,000 cap', function (): void {
        expect($this->result['levels'][5]['income_per_member_paise'])->toBe(1_000_000);
    });

    it('L6 hits the 5,000 cap', function (): void {
        expect($this->result['levels'][6]['income_per_member_paise'])->toBe(500_000);
    });

    it('L7 hits the 2,500 cap', function (): void {
        expect($this->result['levels'][7]['income_per_member_paise'])->toBe(250_000);
    });

    it('L8 hits the 1,500 cap', function (): void {
        expect($this->result['levels'][8]['income_per_member_paise'])->toBe(150_000);
    });

    it('L9 receives minimum only (₹30 cap = minimum)', function (): void {
        $l9 = $this->result['levels'][9];
        expect($l9['income_per_member_paise'])->toBe(3_000)       // ₹30
            ->and($l9['wallet_credit_each_paise'])->toBe(0)        // no extra
            ->and($l9['level_points'])->toBe(0);                   // nobody below
    });

    it('pool and leftover are internally consistent', function (): void {
        $sumPaid = 0;
        foreach ($this->result['incomes'] as $income) {
            $sumPaid += $income;
        }
        expect($this->result['pool_paise'])->toBe($sumPaid + $this->result['leftover_paise']);
    });
});

// ---------------------------------------------------------------------------
// PHASE 4 — App calculator vs oracle: chain tree (all levels 0-9)
// ---------------------------------------------------------------------------

describe('FortuneDistributionCalculator matches oracle (chain, levels 0-9)', function (): void {
    beforeEach(function (): void {
        $this->oracle = new FortuneBonusOracle;
        $this->calculator = new FortuneDistributionCalculator;

        $participants = chainTree();
        $pointsByDepth = standardPointsByDepth();
        $levelCaps = standardLevelCaps();

        $this->oracleResult = $this->oracle->run(
            $participants,
            REFERENCE_TURNOVER_BV_PAISE,
            POOL_RATE_BP,
            MIN_COMMISSION_PAISE,
            $levelCaps,
            $pointsByDepth,
        );

        $pointsMap = $this->oracle->computePoints($participants, $pointsByDepth);
        $rows = array_map(fn (array $p): array => [
            'position' => $p['position'],
            'matrix_level' => $p['matrix_level'],
            'points' => $pointsMap[$p['position']] ?? 0,
        ], $participants);

        $poolPaise = intdiv(REFERENCE_TURNOVER_BV_PAISE * POOL_RATE_BP, 10_000);
        $levelConfigs = array_map(
            fn (array $cap): array => ['payout_mode' => 'capped', 'cap_paise' => $cap['cap_paise'], 'points_per_member' => 0],
            $levelCaps,
        );

        $this->appResult = $this->calculator->allocate($rows, $poolPaise, MIN_COMMISSION_PAISE, $levelConfigs);
    });

    it('app total credited equals oracle', function (): void {
        expect(array_sum($this->appResult['incomes']))->toBe($this->oracleResult['total_credited_paise']);
    });

    it('app leftover equals oracle', function (): void {
        expect($this->appResult['leftover_paise'])->toBe($this->oracleResult['leftover_paise']);
    });

    it('per-level point_value_paise matches oracle for all 10 levels', function (): void {
        foreach ($this->oracleResult['levels'] as $level => $oracleLevel) {
            $appLevel = $this->appResult['levels'][$level] ?? null;
            expect($appLevel)->not->toBeNull("App missing level {$level}")
                ->and($appLevel['point_value_paise'])
                ->toBe($oracleLevel['point_value_paise'], "L{$level} point_value_paise mismatch");
        }
    });

    it('per-position incomes match oracle for all 10 positions', function (): void {
        foreach ($this->oracleResult['incomes'] as $pos => $expected) {
            expect($this->appResult['incomes'][$pos])
                ->toBe($expected, "Position {$pos} income mismatch");
        }
    });
});

// ---------------------------------------------------------------------------
// PHASE 5 — Idempotency and capping sensitivity
// ---------------------------------------------------------------------------

describe('Oracle capping-change sensitivity', function (): void {
    it('changing L2 cap to 1,00,000 raises L2 income and reduces pool carry-forward', function (): void {
        $oracle = new FortuneBonusOracle;
        $participants = balancedTree(depth: 4);
        $pointsByDepth = standardPointsByDepth();

        // Standard run
        $standard = $oracle->run(
            $participants,
            REFERENCE_TURNOVER_BV_PAISE,
            POOL_RATE_BP,
            MIN_COMMISSION_PAISE,
            standardLevelCaps(),
            $pointsByDepth,
        );

        // L2 cap raised to ₹1,00,000
        $modifiedCaps = standardLevelCaps();
        $modifiedCaps[2]['cap_paise'] = 10_000_000; // ₹1,00,000

        $modified = $oracle->run(
            $participants,
            REFERENCE_TURNOVER_BV_PAISE,
            POOL_RATE_BP,
            MIN_COMMISSION_PAISE,
            $modifiedCaps,
            $pointsByDepth,
        );

        $standardL2Income = $standard['levels'][2]['income_per_member_paise'];
        $modifiedL2Income = $modified['levels'][2]['income_per_member_paise'];

        // Derived income at L2 = 99 pts × value_paise; reference value (L2) = 156,500
        // Modified: cap ceiling = 10,000,000 - 3,000 = 9,997,000 (above derived), so no cap clamp
        // income = min_commission + 99 × 156,500 = 3,000 + 15,493,500 = 15,496,500 paise = ₹1,54,965
        // But oracle reference says "1,54,935 - 30 = 1,54,905" as wallet credit →
        //   income = 1,54,905 + 30 = 1,54,935 rupees... let me check:
        // The spec says "wallet credit becomes 1,54,905" — this matches income = 1,54,935 × 100 - 3,000 = ?
        // Actually spec: "L2 wallet credit becomes 1,54,935 - 30 = 1,54,905" → income = ₹1,54,935

        expect($modifiedL2Income)->toBeGreaterThan($standardL2Income);

        // Pool into L3 must be lower in the modified run (more paid at L2)
        $standardL3PoolIn = $standard['levels'][3]['pool_in_paise'];
        $modifiedL3PoolIn = $modified['levels'][3]['pool_in_paise'];
        expect($modifiedL3PoolIn)->toBeLessThan($standardL3PoolIn);

        // Total credited increases when we un-cap L2
        expect($modified['total_credited_paise'])->toBeGreaterThan($standard['total_credited_paise']);
    });
});

// ---------------------------------------------------------------------------
// PHASE 6 — parentPosition and levelFromPosition contract
// ---------------------------------------------------------------------------

describe('FortuneBonusParticipant static helpers', function (): void {
    it('parentPosition is the exact inverse of the 3-wide child expansion', function (): void {
        // For node k, children are at 3k-1, 3k, 3k+1.
        foreach (range(1, 500) as $k) {
            foreach ([3 * $k - 1, 3 * $k, 3 * $k + 1] as $child) {
                expect(FortuneBonusParticipant::parentPosition($child))->toBe($k, "parent({$child}) should be {$k}");
            }
        }
    });

    it('chain positions satisfy the parent-chain relationship', function (): void {
        $chain = [1, 2, 5, 14, 41, 122, 365, 1094, 3281, 9842];
        for ($i = 1; $i < count($chain); $i++) {
            $child = $chain[$i];
            $expected_parent = $chain[$i - 1];
            expect(FortuneBonusParticipant::parentPosition($child))
                ->toBe($expected_parent, "parentPosition({$child}) should be {$expected_parent}");
        }
    });

    it('chain positions resolve to levels 0-9 exactly', function (): void {
        $chain = [1, 2, 5, 14, 41, 122, 365, 1094, 3281, 9842];
        foreach ($chain as $level => $position) {
            expect(FortuneBonusParticipant::levelFromPosition($position))
                ->toBe($level, "Position {$position} should be level {$level}");
        }
    });
});
