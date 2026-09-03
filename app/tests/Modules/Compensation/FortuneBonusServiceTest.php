<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\FortuneBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function seedGsbCredit(int $distributorId, string $date, int $slab = 1): void
{
    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 30_000_000,
        'right_bv_paise' => 30_000_000,
        'weaker_bv_paise' => 30_000_000,
        'slab' => $slab,
        'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 3_000,
        'tds_paise' => 5_000,
        'net_gsb_paise' => 92_000,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 0,
        'power_side_after' => null,
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
        'failure_reason' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function seedPersonalBvForFortune(int $distributorId, int $bvPaise, string $date = '2026-06-10'): void
{
    static $fakeOrderId = 800000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Lift a distributor over the lowest personal-purchase title (Retailer, 3,000
 * BV lifetime) with BV dated OUTSIDE the run month, so only the title gate —
 * never the monthly BV gate — is affected.
 */
function seedTitleBvForFortune(int $distributorId, string $date = '2026-01-10'): void
{
    seedPersonalBvForFortune($distributorId, 300_000, $date);
}

/** Company-wide BV that funds the month's Fortune pool, attributed to nobody in particular. */
function seedCompanyBvForFortunePool(int $bvPaise, string $date = '2026-06-15'): void
{
    static $fakeDistributorId = 990000;
    seedPersonalBvForFortune($fakeDistributorId++, $bvPaise, $date);
}

/** A BV reversal (cancelled or refunded order) — the negative half of the ledger. */
function seedBvReversalForFortune(int $distributorId, int $bvPaise, string $date = '2026-05-20'): void
{
    static $fakeOrderId = 700000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => -abs($bvPaise),
        'type' => 'reversal',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function seedRankQualForFortune(int $distributorId, int $rank, string $monthStart): void
{
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'carry_forward_from_month' => null,
        'status' => 'qualified',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/** Place a distributor at an explicit matrix position, bypassing enrolment. */
function placeFortuneParticipant(int $distributorId, int $position, string $monthStart = '2026-06-01'): FortuneBonusParticipant
{
    return FortuneBonusParticipant::create([
        'distributor_id' => $distributorId,
        'month_start' => $monthStart,
        'position' => $position,
        'matrix_level' => FortuneBonusParticipant::levelFromPosition($position),
        'eligibility_tier' => 'non_ranked',
        'first_gsb_date' => '2026-06-05',
        'enrolled_at' => now(),
    ]);
}

/**
 * Overwrite the admin-editable per-depth FB points for the whole matrix.
 *
 * @param  array<int, int>  $pointsByDepth
 */
function setFortunePointsPerDepth(array $pointsByDepth): void
{
    foreach ($pointsByDepth as $depth => $points) {
        DB::table('fortune_bonus_levels')->where('level', $depth)->update(['points_per_member' => $points]);
    }
}

function registerDistributorForFortune(string $effectiveDate): Distributor
{
    $distributor = Distributor::factory()->create();
    $distributor->forceFill(['effective_date' => $effectiveDate.' 10:00:00'])->save();

    return $distributor;
}

// ── Matrix geometry ─────────────────────────────────────────────────────────

it('exposes the 2026-09-03 cascade config — depth points, every level capped, ₹30 minimum', function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    // Depth points 1L-9P … 9L-1P.
    foreach ([1 => 9, 2 => 8, 3 => 7, 4 => 6, 5 => 5, 6 => 4, 7 => 3, 8 => 2, 9 => 1] as $depth => $points) {
        expect($plan->fortunePointsForDepth($depth))->toBe($points);
    }
    expect($plan->fortunePointsForDepth(0))->toBe(0)
        ->and($plan->fortunePointsForDepth(10))->toBe(0);

    $configs = $plan->fortuneLevelConfigs();
    expect($configs[0])->toBe(['payout_mode' => 'capped', 'cap_paise' => 3_000_000, 'points_per_member' => 0])
        ->and($configs[3]['cap_paise'])->toBe(3_000_000)
        ->and($configs[4]['cap_paise'])->toBe(2_000_000)
        ->and($configs[5]['cap_paise'])->toBe(1_000_000)
        ->and($configs[6]['cap_paise'])->toBe(500_000)
        // Client notes 2026-09-03: levels 7–9 are ordinary capped levels
        // (₹2,500 / ₹1,500 / ₹30) — no residual sharing, no flat mode.
        ->and($configs[7])->toBe(['payout_mode' => 'capped', 'cap_paise' => 250_000, 'points_per_member' => 3])
        ->and($configs[8])->toBe(['payout_mode' => 'capped', 'cap_paise' => 150_000, 'points_per_member' => 2])
        ->and($configs[9])->toBe(['payout_mode' => 'capped', 'cap_paise' => 3_000, 'points_per_member' => 1]);

    expect($plan->fortuneMinCommissionPaise())->toBe(3000);
});

it('levelFromPosition returns correct matrix level', function (): void {
    expect(FortuneBonusParticipant::levelFromPosition(1))->toBe(0);
    expect(FortuneBonusParticipant::levelFromPosition(2))->toBe(1);
    expect(FortuneBonusParticipant::levelFromPosition(4))->toBe(1);
    expect(FortuneBonusParticipant::levelFromPosition(5))->toBe(2);
    expect(FortuneBonusParticipant::levelFromPosition(13))->toBe(2);
    expect(FortuneBonusParticipant::levelFromPosition(14))->toBe(3);
    expect(FortuneBonusParticipant::levelFromPosition(40))->toBe(3);
    expect(FortuneBonusParticipant::levelFromPosition(41))->toBe(4);
    expect(FortuneBonusParticipant::levelFromPosition(121))->toBe(4);
    expect(FortuneBonusParticipant::levelFromPosition(122))->toBe(5);
    expect(FortuneBonusParticipant::levelFromPosition(364))->toBe(5);
    expect(FortuneBonusParticipant::levelFromPosition(365))->toBe(6);
    expect(FortuneBonusParticipant::levelFromPosition(1094))->toBe(7);
    expect(FortuneBonusParticipant::levelFromPosition(3281))->toBe(8);
    expect(FortuneBonusParticipant::levelFromPosition(9842))->toBe(9);
});

it('parentPosition inverts the sequential 3-wide fill and agrees with levelFromPosition', function (): void {
    expect(FortuneBonusParticipant::parentPosition(1))->toBeNull();
    expect(FortuneBonusParticipant::parentPosition(0))->toBeNull();

    // The three children of node k sit at 3k−1, 3k and 3k+1.
    foreach (range(1, 133) as $parent) {
        foreach ([3 * $parent - 1, 3 * $parent, 3 * $parent + 1] as $child) {
            expect(FortuneBonusParticipant::parentPosition($child))->toBe($parent);
        }
    }

    // A parent always sits exactly one level up.
    foreach (range(2, 400) as $position) {
        $parent = FortuneBonusParticipant::parentPosition($position);
        expect(FortuneBonusParticipant::levelFromPosition($parent))
            ->toBe(FortuneBonusParticipant::levelFromPosition($position) - 1);
    }
});

// ── Enrolment gates ─────────────────────────────────────────────────────────

it('enrollEligible enrolls a titled non-ranked distributor with 600 BV and 1 GSB slab', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedTitleBvForFortune($dist->id);
    seedPersonalBvForFortune($dist->id, 60_000);
    seedGsbCredit($dist->id, '2026-06-05');

    $svc = app(FortuneBonusService::class);
    $result = $svc->enrollEligible($month);

    expect($result['enrolled'])->toBe(1);

    $participant = FortuneBonusParticipant::where('distributor_id', $dist->id)->first();
    expect($participant)->not->toBeNull();
    expect($participant->position)->toBe(1);
    expect($participant->matrix_level)->toBe(0);
    expect($participant->eligibility_tier)->toBe('non_ranked');
    expect($participant->month_start)->toBe('2026-06-01');
});

it('does not enroll a distributor with insufficient personal BV', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedTitleBvForFortune($dist->id);
    seedPersonalBvForFortune($dist->id, 50_000); // below 60,000 paise threshold
    seedGsbCredit($dist->id, '2026-06-05');

    $svc = app(FortuneBonusService::class);
    $result = $svc->enrollEligible($month);

    expect($result['enrolled'])->toBe(0);
    expect(FortuneBonusParticipant::count())->toBe(0);
});

it('does not enroll a non-ranked distributor who holds no personal-purchase title', function (): void {
    $dist = registerDistributorForFortune('2026-05-02'); // month 2 — not a new joiner
    $month = Carbon::parse('2026-06-01');

    // 600 BV this month and a slab, but lifetime BV is under the 3,000 BV
    // Retailer threshold, so no title is held (KP 2026-08-07).
    seedPersonalBvForFortune($dist->id, 60_000);
    seedGsbCredit($dist->id, '2026-06-05');

    $svc = app(FortuneBonusService::class);

    expect($svc->enrollEligible($month)['enrolled'])->toBe(0);

    seedTitleBvForFortune($dist->id, '2026-05-10');

    expect($svc->enrollEligible($month)['enrolled'])->toBe(1);
});

it('enrolls a new joiner only on the GSB 1st income, not on any slab', function (): void {
    $slab2Only = registerDistributorForFortune('2026-06-02');
    $slab1 = registerDistributorForFortune('2026-06-02');
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvForFortune($slab2Only->id, 300_000); // 3,000 BV — the month-1 gate
    seedPersonalBvForFortune($slab1->id, 300_000);

    seedGsbCredit($slab2Only->id, '2026-06-05', 2);
    seedGsbCredit($slab1->id, '2026-06-05', 1);

    $svc = app(FortuneBonusService::class);
    $result = $svc->enrollEligible($month);

    expect($result['enrolled'])->toBe(1);
    expect(FortuneBonusParticipant::where('distributor_id', $slab2Only->id)->exists())->toBeFalse();

    $participant = FortuneBonusParticipant::where('distributor_id', $slab1->id)->first();
    expect($participant)->not->toBeNull();
    expect($participant->eligibility_tier)->toBe('new_joiner');
});

it('does not enroll a rank-1 distributor with fewer than 8 slab achievements', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvForFortune($dist->id, 100_000); // 1,000 BV — the rank-1 gate
    foreach (range(1, 7) as $day) {
        seedGsbCredit($dist->id, '2026-06-0'.$day, 1); // 7 achievements, needs 8
    }
    seedRankQualForFortune($dist->id, 1, '2026-06-01');

    $svc = app(FortuneBonusService::class);

    expect($svc->enrollEligible($month)['enrolled'])->toBe(0);

    seedGsbCredit($dist->id, '2026-06-08', 1); // the 8th

    expect($svc->enrollEligible($month)['enrolled'])->toBe(1);
    expect(FortuneBonusParticipant::where('distributor_id', $dist->id)->first()->eligibility_tier)->toBe('rank_1');
});

it('holds a rank-2 distributor to 1,100 BV and 11 slab achievements', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvForFortune($dist->id, 100_000); // 1,000 BV — short of the 1,100 BV gate
    foreach (range(1, 11) as $day) {
        seedGsbCredit($dist->id, '2026-06-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT), 1);
    }
    seedRankQualForFortune($dist->id, 2, '2026-06-01');

    $svc = app(FortuneBonusService::class);

    expect($svc->enrollEligible($month)['enrolled'])->toBe(0);

    seedPersonalBvForFortune($dist->id, 10_000, '2026-06-20'); // now 1,100 BV

    expect($svc->enrollEligible($month)['enrolled'])->toBe(1);
});

it('does not enroll a rank-6 distributor (ineligible)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvForFortune($dist->id, 200_000);
    foreach (range(1, 12) as $day) {
        seedGsbCredit($dist->id, '2026-06-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT));
    }
    seedRankQualForFortune($dist->id, 6, '2026-06-01');

    $svc = app(FortuneBonusService::class);
    $result = $svc->enrollEligible($month);

    expect($result['enrolled'])->toBe(0);
    expect(FortuneBonusParticipant::count())->toBe(0);
});

it('assigns positions in FCFS order by first GSB credit date', function (): void {
    $dist1 = Distributor::factory()->create();
    $dist2 = Distributor::factory()->create();
    $dist3 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // dist2 earned GSB first, dist3 second, dist1 last
    foreach ([$dist1, $dist2, $dist3] as $dist) {
        seedTitleBvForFortune($dist->id);
        seedPersonalBvForFortune($dist->id, 60_000);
    }

    seedGsbCredit($dist2->id, '2026-06-03');
    seedGsbCredit($dist3->id, '2026-06-07');
    seedGsbCredit($dist1->id, '2026-06-10');

    $svc = app(FortuneBonusService::class);
    $svc->enrollEligible($month);

    $p2 = FortuneBonusParticipant::where('distributor_id', $dist2->id)->first();
    $p3 = FortuneBonusParticipant::where('distributor_id', $dist3->id)->first();
    $p1 = FortuneBonusParticipant::where('distributor_id', $dist1->id)->first();

    expect($p2->position)->toBe(1);
    expect($p3->position)->toBe(2);
    expect($p1->position)->toBe(3);
});

it('drops the non-ranked title gate when a reversal wipes the lifetime BV that earned it', function (): void {
    $dist = registerDistributorForFortune('2026-05-02'); // month 2 — not a new joiner
    $month = Carbon::parse('2026-06-01');

    // 3,000 BV lifetime earns the Retailer title, then the order is refunded.
    seedTitleBvForFortune($dist->id, '2026-05-10');
    seedBvReversalForFortune($dist->id, 300_000, '2026-05-20');

    // The month's own gates are still satisfied: 600 BV in June and a slab.
    seedPersonalBvForFortune($dist->id, 60_000);
    seedGsbCredit($dist->id, '2026-06-05');

    $svc = app(FortuneBonusService::class);

    // Lifetime BV is the SIGNED NET sum, so the title is gone with the refund.
    expect($svc->enrollEligible($month)['enrolled'])->toBe(0);
    expect(FortuneBonusParticipant::count())->toBe(0);

    // Re-earning the BV restores the title and, with it, enrolment.
    seedTitleBvForFortune($dist->id, '2026-05-25');

    expect($svc->enrollEligible($month)['enrolled'])->toBe(1);
});

it('refuses to enroll anyone once the month\'s pool is frozen', function (): void {
    $month = Carbon::parse('2026-06-01');

    // A two-person matrix, run and therefore frozen.
    placeFortuneParticipant(Distributor::factory()->create()->id, 1);
    placeFortuneParticipant(Distributor::factory()->create()->id, 2);
    seedCompanyBvForFortunePool(100_000_000);

    app(FortuneBonusService::class)->runForMonth($month);
    expect(FortuneMonthlyPool::where('month_start', '2026-06-01')->exists())->toBeTrue();

    // A distributor who would otherwise qualify cleanly for the same month.
    $late = Distributor::factory()->create();
    seedTitleBvForFortune($late->id);
    seedPersonalBvForFortune($late->id, 60_000);
    seedGsbCredit($late->id, '2026-06-05');

    $result = app(FortuneBonusService::class)->enrollEligible($month);

    expect($result['refused_pool_frozen'])->toBeTrue();
    expect($result['enrolled'])->toBe(0);
    expect(FortuneBonusParticipant::where('month_start', '2026-06-01')->count())->toBe(2);
    expect(FortuneBonusParticipant::where('distributor_id', $late->id)->exists())->toBeFalse();
});

// ── Points + pool ───────────────────────────────────────────────────────────

it('awards FB points from the enrolled downline, by relative depth', function (): void {
    $month = Carbon::parse('2026-06-01');
    $dists = [];

    foreach (range(1, 5) as $position) {
        $dist = Distributor::factory()->create();
        $dists[$position] = $dist;
        placeFortuneParticipant($dist->id, $position);
    }

    seedCompanyBvForFortunePool(100_000_000); // ₹10,00,000 BV → ₹50,000 pool

    app(FortuneBonusService::class)->runForMonth($month);

    $pointsFor = fn (int $position): int => (int) FortuneBonusResult::where('distributor_id', $dists[$position]->id)->first()->points;

    // Positions 2, 3, 4 are the children of 1; position 5 is the child of 2.
    // KP 2026-08-09 depth points: 9 at depth 1, 8 at depth 2.
    expect($pointsFor(1))->toBe(35); // 3 × 9 at depth 1, plus 8 at depth 2
    expect($pointsFor(2))->toBe(9);  // one child
    expect($pointsFor(3))->toBe(0);
    expect($pointsFor(4))->toBe(0);
    expect($pointsFor(5))->toBe(0);

    expect((int) FortuneMonthlyPool::where('month_start', '2026-06-01')->first()->total_points)->toBe(44);
});

it('runs the level cascade — per-level values, caps, ₹30 minimum, frozen level rows', function (): void {
    $month = Carbon::parse('2026-06-01');
    $dists = [];

    foreach (range(1, 5) as $position) {
        $dist = Distributor::factory()->create();
        $dists[$position] = $dist;
        placeFortuneParticipant($dist->id, $position);
    }

    seedCompanyBvForFortunePool(100_000_000); // 10,00,000 BV → 5% = ₹50,000 pool

    // Points 35 / 9 / 0 / 0 / 0 on levels 0 / 1 / 1 / 1 / 2. Cascade:
    //   guarantee 5 × ₹30 = ₹150 → remaining ₹49,850 over 44 points
    //   L0 value floor(₹49,850 ÷ 44) = ₹1,132 → 35 pts would earn ₹39,620,
    //     capped at ₹30,000 (incl the ₹30) → ₹29,970 deducted
    //   L1 value floor(₹19,880 ÷ 9) = ₹2,208 → position 2 earns ₹19,872 + ₹30
    //     = ₹19,902 (below the cap); zero-point members get ₹30
    //   L2 value ₹0 (no points left) → ₹30
    $result = app(FortuneBonusService::class)->runForMonth($month);

    expect($result['pool_paise'])->toBe(5_000_000);
    expect($result['total_points'])->toBe(44);
    expect($result['guaranteed_total_paise'])->toBe(15_000);
    expect($result['is_shortfall'])->toBeFalse();
    expect($result['credited'])->toBe(5);
    expect($result['skipped_zero_income'])->toBe(0);
    expect($result['leftover_paise'])->toBe(800);

    $grossFor = fn (int $position): int => (int) FortuneBonusResult::where('distributor_id', $dists[$position]->id)->first()->gross_paise;
    expect($grossFor(1))->toBe(3_000_000);
    expect($grossFor(2))->toBe(1_990_200);
    expect($grossFor(3))->toBe(3000);
    expect($grossFor(4))->toBe(3000);
    expect($grossFor(5))->toBe(3000);

    $topResult = FortuneBonusResult::where('distributor_id', $dists[1]->id)->first();
    expect($topResult->point_value_paise)->toBe(113_200)
        ->and($topResult->min_commission_paise)->toBe(3000)
        ->and($topResult->cap_paise)->toBe(3_000_000);

    $pool = FortuneMonthlyPool::where('month_start', '2026-06-01')->first();
    expect($pool->point_value_paise)->toBeNull()
        ->and($pool->payout_paise)->toBe(4_999_200)
        ->and($pool->leftover_paise)->toBe(800)
        ->and($pool->min_commission_paise)->toBe(3000);

    $levels = $pool->levels()->get()->keyBy('matrix_level');
    expect($levels)->toHaveCount(3)
        ->and($levels[0]->point_value_paise)->toBe(113_200)
        ->and($levels[0]->cap_paise)->toBe(3_000_000)
        ->and($levels[0]->paid_paise)->toBe(3_000_000)
        ->and($levels[1]->point_value_paise)->toBe(220_800)
        ->and($levels[1]->paid_paise)->toBe(1_990_200 + 3000 + 3000)
        ->and($levels[2]->point_value_paise)->toBe(0)
        ->and($levels[2]->paid_paise)->toBe(3000);

    // Wallet entries match every gross.
    foreach ($dists as $position => $dist) {
        $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'fortune_credit')->first();
        expect($ledger)->not->toBeNull();
        expect((int) $ledger->amount_paise)->toBe($grossFor($position));
    }
});

it('pro-rates the ₹30 minimum when the pool cannot cover it', function (): void {
    $month = Carbon::parse('2026-06-01');

    $top = Distributor::factory()->create();
    $second = Distributor::factory()->create();
    placeFortuneParticipant($top->id, 1);
    placeFortuneParticipant($second->id, 2);

    // ₹5 pool against 2 × ₹30 of guarantees → floor_rupee(₹5 ÷ 2) = ₹2 each.
    seedCompanyBvForFortunePool(10_000);

    $result = app(FortuneBonusService::class)->runForMonth($month);

    expect($result['is_shortfall'])->toBeTrue();
    expect($result['credited'])->toBe(2);
    expect($result['skipped_zero_income'])->toBe(0);
    expect($result['leftover_paise'])->toBe(100);

    $pool = FortuneMonthlyPool::where('month_start', '2026-06-01')->first();
    expect($pool->pool_paise)->toBe(500)
        ->and($pool->is_shortfall)->toBeTrue()
        ->and($pool->shortfall_per_head_paise)->toBe(200);

    foreach ([$top, $second] as $dist) {
        $row = FortuneBonusResult::where('distributor_id', $dist->id)->first();
        expect($row->gross_paise)->toBe(200)
            ->and($row->status)->toBe(FortuneBonusResult::STATUS_CREDITED);
        expect((int) WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'fortune_credit')->first()->amount_paise)->toBe(200);
    }
});

it('skips everyone in a zero-pool month, with no wallet credits', function (): void {
    $month = Carbon::parse('2026-06-01');

    $top = Distributor::factory()->create();
    placeFortuneParticipant($top->id, 1);
    placeFortuneParticipant(Distributor::factory()->create()->id, 2);

    // No company BV at all → ₹0 pool → ₹0 per head.
    $result = app(FortuneBonusService::class)->runForMonth($month);

    expect($result['credited'])->toBe(0);
    expect($result['skipped_zero_income'])->toBe(2);
    expect(WalletLedgerEntry::where('type', 'fortune_credit')->count())->toBe(0);

    $topResult = FortuneBonusResult::where('distributor_id', $top->id)->first();
    expect($topResult->gross_paise)->toBe(0)
        ->and($topResult->status)->toBe(FortuneBonusResult::STATUS_SKIPPED);
});

it('pays a participant with no downline the ₹30 minimum, credited to the wallet', function (): void {
    $month = Carbon::parse('2026-06-01');

    $top = Distributor::factory()->create();
    $bottom = Distributor::factory()->create();
    placeFortuneParticipant($top->id, 1);
    placeFortuneParticipant($bottom->id, 2);

    seedCompanyBvForFortunePool(100_000_000);

    $result = app(FortuneBonusService::class)->runForMonth($month);

    expect($result['credited'])->toBe(2);
    expect($result['skipped_zero_income'])->toBe(0);

    $bottomResult = FortuneBonusResult::where('distributor_id', $bottom->id)->first();
    expect($bottomResult->points)->toBe(0)
        ->and($bottomResult->gross_paise)->toBe(3000)
        ->and($bottomResult->min_commission_paise)->toBe(3000)
        ->and($bottomResult->status)->toBe(FortuneBonusResult::STATUS_CREDITED);
    expect((int) WalletLedgerEntry::where('distributor_id', $bottom->id)->where('type', 'fortune_credit')->first()->amount_paise)->toBe(3000);

    // The top's 9 points at L0 value floor((₹50,000 − ₹60) ÷ 9) = ₹5,548
    // would earn ₹49,932 + ₹30 — capped at ₹30,000 including the minimum.
    $topResult = FortuneBonusResult::where('distributor_id', $top->id)->first();
    expect($topResult->points)->toBe(9)
        ->and($topResult->gross_paise)->toBe(3_000_000)
        ->and($topResult->cap_paise)->toBe(3_000_000)
        ->and($topResult->status)->toBe(FortuneBonusResult::STATUS_CREDITED);
});

it('freezes the month economics — later BV and later enrolments never reprice it', function (): void {
    $month = Carbon::parse('2026-06-01');

    $top = Distributor::factory()->create();
    placeFortuneParticipant($top->id, 1);
    placeFortuneParticipant(Distributor::factory()->create()->id, 2);

    seedCompanyBvForFortunePool(100_000_000);

    $first = app(FortuneBonusService::class)->runForMonth($month);
    $paidGross = (int) FortuneBonusResult::where('distributor_id', $top->id)->first()->gross_paise;

    // More BV lands and two more distributors are placed under position 1.
    seedCompanyBvForFortunePool(900_000_000, '2026-06-20');
    placeFortuneParticipant(Distributor::factory()->create()->id, 3);
    placeFortuneParticipant(Distributor::factory()->create()->id, 4);

    $second = app(FortuneBonusService::class)->runForMonth($month);

    expect($second['pool_paise'])->toBe($first['pool_paise']);
    expect($second['total_points'])->toBe($first['total_points']);
    expect($second['leftover_paise'])->toBe($first['leftover_paise']);
    expect($second['guaranteed_total_paise'])->toBe($first['guaranteed_total_paise']);
    expect(FortuneMonthlyPool::where('month_start', '2026-06-01')->count())->toBe(1);
    expect((int) FortuneBonusResult::where('distributor_id', $top->id)->first()->gross_paise)->toBe($paidGross);
});

it('re-credits a deleted result at the frozen per-level value, not live config', function (): void {
    $month = Carbon::parse('2026-06-01');

    $top = Distributor::factory()->create();
    placeFortuneParticipant($top->id, 1);
    placeFortuneParticipant(Distributor::factory()->create()->id, 2);

    seedCompanyBvForFortunePool(100_000_000);

    $svc = app(FortuneBonusService::class);
    $svc->runForMonth($month);
    $paidGross = (int) FortuneBonusResult::where('distributor_id', $top->id)->first()->gross_paise;
    $frozenValue = (int) FortuneBonusResult::where('distributor_id', $top->id)->first()->point_value_paise;

    // Config changes after the freeze must not reprice the retry.
    setFortunePointsPerDepth([1 => 100]);
    DB::table('fortune_bonus_levels')->where('level', 0)->update(['cap_paise' => 100]);
    FortuneBonusResult::where('distributor_id', $top->id)->delete();

    $svc->runForMonth($month);

    $row = FortuneBonusResult::where('distributor_id', $top->id)->first();
    expect((int) $row->gross_paise)->toBe($paidGross)
        ->and((int) $row->point_value_paise)->toBe($frozenValue);
});

it('re-credits a month frozen under the legacy residual rule at its own snapshot, not the 2026-09-03 caps', function (): void {
    $month = Carbon::parse('2026-06-01');

    // A level-7 member (first position of level 7 is 1,094) with one level-8
    // child (3 × 1,094 = 3,282 → parentPosition = 1,094): 9 points at L7.
    $deep = Distributor::factory()->create();
    placeFortuneParticipant($deep->id, 1094);
    placeFortuneParticipant(Distributor::factory()->create()->id, 3282);

    // The month was configured under the 2026-08-09 rule: L7–8 residual, L9 flat.
    DB::table('fortune_bonus_levels')->whereIn('level', [7, 8])->update(['payout_mode' => 'residual', 'cap_paise' => null]);
    DB::table('fortune_bonus_levels')->where('level', 9)->update(['payout_mode' => 'flat_min', 'cap_paise' => null]);

    seedCompanyBvForFortunePool(20_00_000_00); // ₹1,00,000 pool

    $svc = app(FortuneBonusService::class);
    $svc->runForMonth($month);

    // Uncapped residual: (1,00,000 − 60) ÷ 9 = ₹11,104 → 30 + 9 × 11,104 = ₹99,966.
    $legacyGross = (int) FortuneBonusResult::where('distributor_id', $deep->id)->first()->gross_paise;
    expect($legacyGross)->toBe(99_966_00)
        ->and(DB::table('fortune_monthly_pool_levels')->where('matrix_level', 7)->value('payout_mode'))->toBe('residual');

    // The 2026-09-03 migration lands afterwards: level 7 becomes capped ₹2,500.
    DB::table('fortune_bonus_levels')->where('level', 7)->update(['payout_mode' => 'capped', 'cap_paise' => 250_000]);
    FortuneBonusResult::where('distributor_id', $deep->id)->delete();

    $svc->runForMonth($month);

    expect((int) FortuneBonusResult::where('distributor_id', $deep->id)->first()->gross_paise)->toBe($legacyGross);
});

it('runForMonth is idempotent — re-running does not double-credit', function (): void {
    $month = Carbon::parse('2026-06-01');

    $top = Distributor::factory()->create();
    placeFortuneParticipant($top->id, 1);
    placeFortuneParticipant(Distributor::factory()->create()->id, 2);

    seedCompanyBvForFortunePool(100_000_000);

    $svc = app(FortuneBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);

    expect(FortuneBonusResult::where('distributor_id', $top->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $top->id)->where('type', 'fortune_credit')->count())->toBe(1);
});
