<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\FortuneDistributionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Uniform full-matrix participants: a member at absolute level L earns from
 * the min(9, 9−L) levels below — Σ_{d=1..min(9,9−L)} 3^d × pointsPerDepth(d),
 * with KP's 2026-08-09 depth points 9/8/7/6/5/4/3/2/1 (1L-9P … 9L-1P).
 *
 * @return array<int, array{position: int, matrix_level: int, points: int}>
 */
function fortuneCascadeFullMatrix(): array
{
    $depthPoints = [1 => 9, 2 => 8, 3 => 7, 4 => 6, 5 => 5, 6 => 4, 7 => 3, 8 => 2, 9 => 1];
    $participants = [];
    $position = 1;

    foreach (range(0, 9) as $level) {
        $points = 0;
        for ($depth = 1; $depth <= min(9, 9 - $level); $depth++) {
            $points += (3 ** $depth) * $depthPoints[$depth];
        }

        for ($i = 0; $i < 3 ** $level; $i++) {
            $participants[] = ['position' => $position++, 'matrix_level' => $level, 'points' => $points];
        }
    }

    return $participants;
}

/** @return array<int, array{payout_mode: string, cap_paise: ?int, points_per_member: int}> */
function fortuneCascadeLevelConfigs(): array
{
    $configs = [];
    foreach ([
        0 => ['capped', 3_000_000, 0],
        1 => ['capped', 3_000_000, 9],
        2 => ['capped', 3_000_000, 8],
        3 => ['capped', 3_000_000, 7],
        4 => ['capped', 2_000_000, 6],
        5 => ['capped', 1_000_000, 5],
        6 => ['capped', 500_000, 4],
        7 => ['residual', null, 3],
        8 => ['residual', null, 2],
        9 => ['flat_min', null, 1],
    ] as $level => [$mode, $cap, $points]) {
        $configs[$level] = ['payout_mode' => $mode, 'cap_paise' => $cap, 'points_per_member' => $points];
    }

    return $configs;
}

it('reproduces every number of KP\'s ₹36cr full-matrix worked example', function (): void {
    $participants = fortuneCascadeFullMatrix();
    expect($participants)->toHaveCount(29_524);

    // Per-level point totals straight off KP's sheet.
    $pointTotals = [];
    foreach ($participants as $p) {
        $pointTotals[$p['matrix_level']] = ($pointTotals[$p['matrix_level']] ?? 0) + $p['points'];
    }
    expect($pointTotals)->toBe([
        0 => 44_271,
        1 => 73_764,
        2 => 1_03_194,
        3 => 1_32_435,
        4 => 1_61_109,
        5 => 1_88_082,
        6 => 2_09_952,
        7 => 2_16_513,
        8 => 1_77_147,
        9 => 0,
    ])->and(array_sum($pointTotals))->toBe(13_06_467);

    // ₹36cr turnover × 5% = ₹18cr pool (1 BV = ₹1), in paise.
    $result = (new FortuneDistributionCalculator)->allocate(
        $participants,
        1_80_00_000_00,
        3000,
        fortuneCascadeLevelConfigs(),
    );

    expect($result['is_shortfall'])->toBeFalse()
        ->and($result['shortfall_per_head_paise'])->toBeNull()
        ->and($result['guaranteed_total_paise'])->toBe(29_524 * 3000); // ₹8,85,720

    // Per-level point values: ₹13/13/14/15/16/18/19, shared ₹20 residual.
    $values = array_map(fn (array $l): int => $l['point_value_paise'], $result['levels']);
    expect($values)->toBe([
        0 => 13_00, 1 => 13_00, 2 => 14_00, 3 => 15_00,
        4 => 16_00, 5 => 18_00, 6 => 19_00,
        7 => 20_00, 8 => 20_00, 9 => 0,
    ]);

    // One member per level — the caps bind on levels 0–6.
    $firstPositionAtLevel = [];
    $cumulative = 0;
    foreach (range(0, 9) as $level) {
        $firstPositionAtLevel[$level] = $cumulative + 1;
        $cumulative += 3 ** $level;
    }
    $incomes = $result['incomes'];
    expect($incomes[$firstPositionAtLevel[0]])->toBe(3_000_000)
        ->and($incomes[$firstPositionAtLevel[1]])->toBe(3_000_000)
        ->and($incomes[$firstPositionAtLevel[2]])->toBe(3_000_000)
        ->and($incomes[$firstPositionAtLevel[3]])->toBe(3_000_000)
        ->and($incomes[$firstPositionAtLevel[4]])->toBe(2_000_000)
        ->and($incomes[$firstPositionAtLevel[5]])->toBe(1_000_000)
        ->and($incomes[$firstPositionAtLevel[6]])->toBe(500_000)
        // L7: 99 pts × ₹20 + ₹30 = ₹2,010; L8: 27 pts × ₹20 + ₹30 = ₹570.
        ->and($incomes[$firstPositionAtLevel[7]])->toBe(2010_00)
        ->and($incomes[$firstPositionAtLevel[8]])->toBe(570_00)
        ->and($incomes[$firstPositionAtLevel[9]])->toBe(3000);

    // Per-level paid totals KP spells out for the deep levels.
    expect($result['levels'][7]['paid_paise'])->toBe(43_95_870_00)
        ->and($result['levels'][8]['paid_paise'])->toBe(37_39_770_00)
        ->and($result['levels'][9]['paid_paise'])->toBe(5_90_490_00);

    // The whole pool reconciles: Σ incomes + leftover = pool.
    expect($result['leftover_paise'])->toBe(3_78_870_00)
        ->and(array_sum($incomes) + $result['leftover_paise'])->toBe(1_80_00_000_00);
});

it('recomputes the value per capped level and pays point earnings when below the cap', function (): void {
    // Sparse month: 5 qualifiers on levels 0/1/1/1/2 with real depth-walk
    // points — 35 / 9 / 0 / 0 / 0.
    $participants = [
        ['position' => 1, 'matrix_level' => 0, 'points' => 35],
        ['position' => 2, 'matrix_level' => 1, 'points' => 9],
        ['position' => 3, 'matrix_level' => 1, 'points' => 0],
        ['position' => 4, 'matrix_level' => 1, 'points' => 0],
        ['position' => 5, 'matrix_level' => 2, 'points' => 0],
    ];

    // ₹20,000 pool: nobody hits a cap.
    // remaining = 20,000_00 − 5×30_00 = 19,850_00; L0 value floor_rupee(19,850_00/44) = ₹451
    // p1 = 30_00 + 35×451_00 = 15,815_00; remaining 4,065_00; L1 value floor(4,065_00/9) = ₹451
    // p2 = 30_00 + 9×451_00 = 4,089_00; zero-point members = ₹30 each.
    $result = (new FortuneDistributionCalculator)->allocate($participants, 2_000_000, 3000, fortuneCascadeLevelConfigs());

    expect($result['incomes'])->toBe([1 => 1_581_500, 2 => 408_900, 3 => 3000, 4 => 3000, 5 => 3000])
        ->and($result['levels'][0]['point_value_paise'])->toBe(45_100)
        ->and($result['levels'][1]['point_value_paise'])->toBe(45_100)
        ->and($result['levels'][2]['point_value_paise'])->toBe(0)
        ->and($result['leftover_paise'])->toBe(600)
        ->and(array_sum($result['incomes']) + $result['leftover_paise'])->toBe(2_000_000);

    // ₹1,00,000 pool: the ₹30,000 cap binds on both point earners.
    // L0 value floor_rupee(99,850_00/44) = ₹2,269 → capped at 30,000 (incl ₹30);
    // L1 value floor_rupee(69,880_00/9) = ₹7,764 → 9×7,764+30 caps at 30,000 too.
    $capped = (new FortuneDistributionCalculator)->allocate($participants, 10_000_000, 3000, fortuneCascadeLevelConfigs());

    expect($capped['incomes'][1])->toBe(3_000_000)
        ->and($capped['incomes'][2])->toBe(3_000_000)
        ->and($capped['incomes'][3])->toBe(3000)
        ->and($capped['levels'][0]['point_value_paise'])->toBe(226_900)
        ->and($capped['levels'][1]['point_value_paise'])->toBe(776_400)
        ->and($capped['leftover_paise'])->toBe(3_991_000)
        ->and(array_sum($capped['incomes']) + $capped['leftover_paise'])->toBe(10_000_000);
});

it('pro-rates the minimum when the pool cannot cover ₹30 per qualifier', function (): void {
    $participants = [
        ['position' => 1, 'matrix_level' => 0, 'points' => 12],
        ['position' => 2, 'matrix_level' => 1, 'points' => 0],
        ['position' => 3, 'matrix_level' => 1, 'points' => 0],
    ];

    // Pool ₹50 < ₹90 of guarantees → floor_rupee(50_00 / 3) = ₹16 each.
    $result = (new FortuneDistributionCalculator)->allocate($participants, 5000, 3000, fortuneCascadeLevelConfigs());

    expect($result['is_shortfall'])->toBeTrue()
        ->and($result['shortfall_per_head_paise'])->toBe(1600)
        ->and($result['incomes'])->toBe([1 => 1600, 2 => 1600, 3 => 1600])
        ->and($result['leftover_paise'])->toBe(200)
        ->and($result['levels'][0]['point_value_paise'])->toBe(0);
});

it('pays nothing in a zero-pool month and handles an empty month', function (): void {
    $participants = [
        ['position' => 1, 'matrix_level' => 0, 'points' => 5],
        ['position' => 2, 'matrix_level' => 1, 'points' => 0],
    ];

    $zero = (new FortuneDistributionCalculator)->allocate($participants, 0, 3000, fortuneCascadeLevelConfigs());
    expect($zero['is_shortfall'])->toBeTrue()
        ->and($zero['shortfall_per_head_paise'])->toBe(0)
        ->and($zero['incomes'])->toBe([1 => 0, 2 => 0])
        ->and($zero['leftover_paise'])->toBe(0);

    $empty = (new FortuneDistributionCalculator)->allocate([], 5_000_000, 3000, fortuneCascadeLevelConfigs());
    expect($empty['incomes'])->toBe([])
        ->and($empty['levels'])->toBe([])
        ->and($empty['guaranteed_total_paise'])->toBe(0)
        ->and($empty['is_shortfall'])->toBeFalse()
        ->and($empty['leftover_paise'])->toBe(5_000_000);
});

it('pays only the minimum when no participant has points', function (): void {
    $participants = [
        ['position' => 1, 'matrix_level' => 0, 'points' => 0],
        ['position' => 2, 'matrix_level' => 1, 'points' => 0],
    ];

    $result = (new FortuneDistributionCalculator)->allocate($participants, 1_000_000, 3000, fortuneCascadeLevelConfigs());

    expect($result['incomes'])->toBe([1 => 3000, 2 => 3000])
        ->and($result['levels'][0]['point_value_paise'])->toBe(0)
        ->and($result['leftover_paise'])->toBe(994_000);
});
