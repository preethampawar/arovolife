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
        7 => ['capped', 250_000, 3],
        8 => ['capped', 150_000, 2],
        9 => ['capped', 3_000, 1],
    ] as $level => [$mode, $cap, $points]) {
        $configs[$level] = ['payout_mode' => $mode, 'cap_paise' => $cap, 'points_per_member' => $points];
    }

    return $configs;
}

/**
 * The 2026-08-09 cascade's level config (levels 7–8 residual, 9 flat), kept
 * only to prove the legacy modes still allocate identically for months frozen
 * before the 2026-09-03 change.
 *
 * @return array<int, array{payout_mode: string, cap_paise: ?int, points_per_member: int}>
 */
function fortuneLegacyLevelConfigs(): array
{
    $configs = fortuneCascadeLevelConfigs();
    $configs[7] = ['payout_mode' => 'residual', 'cap_paise' => null, 'points_per_member' => 3];
    $configs[8] = ['payout_mode' => 'residual', 'cap_paise' => null, 'points_per_member' => 2];
    $configs[9] = ['payout_mode' => 'flat_min', 'cap_paise' => null, 'points_per_member' => 1];

    return $configs;
}

/**
 * The client's September example (notes 2026-09-03): the matrix is full to
 * level 4 — 1 + 3 + 9 + 27 + 81 = 121 qualifiers, nobody below level 4.
 *
 * @return array<int, array{position: int, matrix_level: int, points: int}>
 */
function fortuneSeptemberMatrix(): array
{
    $depthPoints = [1 => 9, 2 => 8, 3 => 7, 4 => 6];
    $participants = [];
    $position = 1;

    foreach (range(0, 4) as $level) {
        $points = 0;
        for ($depth = 1; $depth <= 4 - $level; $depth++) {
            $points += (3 ** $depth) * $depthPoints[$depth];
        }

        for ($i = 0; $i < 3 ** $level; $i++) {
            $participants[] = ['position' => $position++, 'matrix_level' => $level, 'points' => $points];
        }
    }

    return $participants;
}

it('reproduces the client\'s ₹36cr full-matrix example with every level capped (2026-09-03)', function (): void {
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

    // Per-level point values: ₹13/13/14/15/16/18/19 (unchanged from the
    // client's sheet), then — because every level now recomputes its own
    // value — L7 ₹20 and L8 ₹22 (was one shared ₹20 under the 2026-08-09
    // residual rule; the client confirmed the shift on 2026-09-03).
    $values = array_map(fn (array $l): int => $l['point_value_paise'], $result['levels']);
    expect($values)->toBe([
        0 => 13_00, 1 => 13_00, 2 => 14_00, 3 => 15_00,
        4 => 16_00, 5 => 18_00, 6 => 19_00,
        7 => 20_00, 8 => 22_00, 9 => 0,
    ])->and(array_column($result['levels'], 'payout_mode'))->each->toBe('capped');

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
        // L7: 99 pts × ₹20 + ₹30 = ₹2,010 (under its ₹2,500 cap);
        // L8: 27 pts × ₹22 + ₹30 = ₹624 (under its ₹1,500 cap);
        // L9: 0 pts, ₹30 cap = the minimum only.
        ->and($incomes[$firstPositionAtLevel[7]])->toBe(2010_00)
        ->and($incomes[$firstPositionAtLevel[8]])->toBe(624_00)
        ->and($incomes[$firstPositionAtLevel[9]])->toBe(3000);

    // Per-level paid totals for the deep levels.
    expect($result['levels'][7]['paid_paise'])->toBe(43_95_870_00)
        ->and($result['levels'][8]['paid_paise'])->toBe(40_94_064_00)
        ->and($result['levels'][9]['paid_paise'])->toBe(5_90_490_00);

    // The whole pool reconciles: Σ incomes + leftover = pool. Leftover falls
    // from ₹3,78,870 (shared residual) to ₹24,576 (per-level recompute).
    expect($result['leftover_paise'])->toBe(24_576_00)
        ->and(array_sum($incomes))->toBe(1_79_75_424_00)
        ->and(array_sum($incomes) + $result['leftover_paise'])->toBe(1_80_00_000_00);
});

it('still allocates the ₹36cr example on the legacy residual/flat config for months frozen before 2026-09-03', function (): void {
    $result = (new FortuneDistributionCalculator)->allocate(
        fortuneCascadeFullMatrix(),
        1_80_00_000_00,
        3000,
        fortuneLegacyLevelConfigs(),
    );

    $values = array_map(fn (array $l): int => $l['point_value_paise'], $result['levels']);
    expect($values[7])->toBe(20_00)
        ->and($values[8])->toBe(20_00)
        ->and($result['levels'][7]['payout_mode'])->toBe('residual')
        ->and($result['levels'][9]['payout_mode'])->toBe('flat_min')
        ->and($result['levels'][8]['paid_paise'])->toBe(37_39_770_00)
        ->and($result['leftover_paise'])->toBe(3_78_870_00)
        ->and(array_sum($result['incomes']) + $result['leftover_paise'])->toBe(1_80_00_000_00);
});

it('reproduces the client\'s September example — 121 qualifiers, nobody below level 4 (2026-09-03)', function (): void {
    $participants = fortuneSeptemberMatrix();
    expect($participants)->toHaveCount(121);

    // Points straight off the notes: L0 774; L1 288 × 3 = 864; L2 99 × 9 = 891;
    // L3 27 × 27 = 729; L4 0 — total 3,258.
    $pointTotals = [];
    foreach ($participants as $p) {
        $pointTotals[$p['matrix_level']] = ($pointTotals[$p['matrix_level']] ?? 0) + $p['points'];
    }
    expect($pointTotals)->toBe([0 => 774, 1 => 864, 2 => 891, 3 => 729, 4 => 0])
        ->and(array_sum($pointTotals))->toBe(3_258);

    // September turnover BV ₹5,32,00,000 × 5% = ₹26,60,000 pool.
    $result = (new FortuneDistributionCalculator)->allocate(
        $participants,
        26_60_000_00,
        3000,
        fortuneCascadeLevelConfigs(),
    );

    // ₹30 × 121 = ₹3,630 reserved → ₹26,56,370 to distribute.
    expect($result['is_shortfall'])->toBeFalse()
        ->and($result['guaranteed_total_paise'])->toBe(3_630_00);

    // Per-level values: 26,56,370 ÷ 3,258 → ₹815; 26,26,400 ÷ 2,484 → ₹1,057;
    // 25,36,490 ÷ 1,620 → ₹1,565; 22,66,760 ÷ 729 → ₹3,109; L4 has no points.
    $values = array_map(fn (array $l): int => $l['point_value_paise'], $result['levels']);
    expect($values)->toBe([0 => 815_00, 1 => 1_057_00, 2 => 1_565_00, 3 => 3_109_00, 4 => 0]);

    // Every point-holder is capped at ₹30,000 (incl. the ₹30); level 4 gets the
    // ₹30 only — "no downline, no income" — and the remainder is leftover.
    $incomes = $result['incomes'];
    expect($incomes[1])->toBe(3_000_000)
        ->and($incomes[2])->toBe(3_000_000)
        ->and($incomes[5])->toBe(3_000_000)
        ->and($incomes[14])->toBe(3_000_000)
        ->and($incomes[41])->toBe(3000)
        ->and($incomes[121])->toBe(3000)
        ->and($result['levels'][0]['paid_paise'])->toBe(30_000_00)
        ->and($result['levels'][1]['paid_paise'])->toBe(90_000_00)
        ->and($result['levels'][2]['paid_paise'])->toBe(2_70_000_00)
        ->and($result['levels'][3]['paid_paise'])->toBe(8_10_000_00)
        ->and($result['levels'][4]['paid_paise'])->toBe(2_430_00)
        ->and(array_sum($incomes))->toBe(12_02_430_00)
        ->and($result['leftover_paise'])->toBe(14_57_570_00)
        ->and(array_sum($incomes) + $result['leftover_paise'])->toBe(26_60_000_00);
});

it('caps levels 7–9 per member and recomputes each level\'s own value', function (): void {
    // One member per level 6–9 with real depth-walk points, and a pool big
    // enough that the deep caps bind: L6 5,000; L7 2,500; L8 1,500; L9 30.
    $participants = [
        ['position' => 1, 'matrix_level' => 6, 'points' => 9 + 8 + 7],
        ['position' => 2, 'matrix_level' => 7, 'points' => 9 + 8],
        ['position' => 3, 'matrix_level' => 8, 'points' => 9],
        ['position' => 4, 'matrix_level' => 9, 'points' => 0],
    ];

    $result = (new FortuneDistributionCalculator)->allocate($participants, 10_00_000_00, 3000, fortuneCascadeLevelConfigs());

    // remaining = 10,00,000 − 120 = 9,99,880; L6 ⌊9,99,880 ÷ 50⌋ = ₹19,997 → cap 5,000;
    // remaining 9,94,910; L7 ⌊÷ 26⌋ = ₹38,265 → cap 2,500; remaining 9,92,440;
    // L8 ⌊÷ 9⌋ = ₹1,10,271 → cap 1,500; L9 0 points → ₹30 (its cap).
    expect($result['incomes'])->toBe([1 => 500_000, 2 => 250_000, 3 => 150_000, 4 => 3000])
        ->and($result['levels'][6]['point_value_paise'])->toBe(19_997_00)
        ->and($result['levels'][7]['point_value_paise'])->toBe(38_265_00)
        ->and($result['levels'][8]['point_value_paise'])->toBe(1_10_271_00)
        ->and($result['levels'][9]['point_value_paise'])->toBe(0)
        ->and($result['levels'][9]['cap_paise'])->toBe(3000)
        ->and($result['leftover_paise'])->toBe(10_00_000_00 - 500_000 - 250_000 - 150_000 - 3000);

    // A level-9 member WITH points (a live matrix cannot produce one, but the
    // config must still hold) is limited to the ₹30 cap = the minimum only.
    $deep = (new FortuneDistributionCalculator)->allocate(
        [['position' => 1, 'matrix_level' => 9, 'points' => 27]],
        1_00_000_00,
        3000,
        fortuneCascadeLevelConfigs(),
    );
    expect($deep['incomes'])->toBe([1 => 3000])
        ->and($deep['leftover_paise'])->toBe(1_00_000_00 - 3000);
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
