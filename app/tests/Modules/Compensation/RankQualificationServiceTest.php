<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankQualificationService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function seedPersonalBv(int $distributorId, int $bvPaise): void
{
    static $fakeOrderId = 900000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function seedGroupBv(int $distributorId, string $date, int $leftBv, int $rightBv): void
{
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => $date,
        'left_bv_paise' => $leftBv,
        'right_bv_paise' => $rightBv,
    ]);
}

function seedGenealogyAndSide(int $ancestorId, int $childId, string $side, int $depth = 1): void
{
    DB::table('genealogy_closure')->insertOrIgnore([
        'ancestor_id' => $ancestorId,
        'descendant_id' => $childId,
        'depth' => $depth,
    ]);
    DB::table('genealogy_closure')->insertOrIgnore([
        'ancestor_id' => $childId,
        'descendant_id' => $childId,
        'depth' => 0,
    ]);
    DB::table('distributors')->where('id', $childId)->update([
        'placement_parent_id' => $ancestorId,
        'placement_side' => $side,
    ]);
}

it('returns zero qualifications when no group BV data exists', function (): void {
    $month = Carbon::parse('2026-06-01');

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['total_qualifications'])->toBe(0);
    expect($result['rank_1_count'])->toBe(0);
});

it('qualifies a distributor with sufficient monthly group BV and personal BV for rank 1 (Silver)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 700_000); // Dealer title (Rank-1 min) = 7,000 BV
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 31_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month, occurrenceNumber: 1);

    expect($result['rank_1_count'])->toBe(1);
    expect($result['total_qualifications'])->toBeGreaterThanOrEqual(1);

    $record = RankQualification::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($record)->not->toBeNull();
    expect($record->status)->toBe(RankQualification::STATUS_QUALIFIED);
    expect($record->occurrence_in_month)->toBe(1);
    expect($record->is_carry_forward)->toBeFalse();
});

it('does not qualify a distributor whose personal BV is below rank-1 minimum', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 400_000);
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 31_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(0);
    expect(RankQualification::count())->toBe(0);
});

it('does not qualify for rank 1 when only one side meets the group BV threshold', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 700_000); // meets Dealer title; only the weak side should disqualify
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 10_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(0);
});

/** Seed a personal-purchase BV accrual dated inside a specific month (for the
 *  weaker-leg top-up, which counts only that month's personal BV). */
function seedPersonalBvOn(int $distributorId, int $bvPaise, string $date): void
{
    static $fakeOrderId = 960000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 10:00:00',
        'created_at' => $date.' 10:00:00',
        'updated_at' => $date.' 10:00:00',
    ]);
}

it('qualifies for rank 1 when the weaker leg is topped up by this-month personal BV', function (): void {
    // KP 2026-06-28: up to 15,000 BV of this month's personal purchases may
    // supplement the weaker Genos leg toward the rank-1 3L/3L match.
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // 7,000 BV personal purchase in June → meets Dealer title AND feeds the top-up.
    seedPersonalBvOn($dist->id, 700_000, '2026-06-15');
    // Right (weaker) leg is 5,000 BV short of the 3L (30,000,000 paise) match.
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 29_500_000);

    $result = app(RankQualificationService::class)->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(1); // 29,500,000 + 700,000 top-up ≥ 30,000,000

    $record = RankQualification::where('distributor_id', $dist->id)->where('rank_number', 1)->first();
    expect($record)->not->toBeNull();
    // The recorded group BV stays the RAW figure — the top-up only aids qualification.
    expect((int) $record->right_genos_bv_paise)->toBe(29_500_000);
});

it('caps the rank-1 weaker-leg top-up at 15,000 BV', function (): void {
    // A shortfall larger than the 15,000 BV (1,500,000 paise) cap cannot be
    // fully covered even with abundant personal BV → no qualification.
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvOn($dist->id, 5_000_000, '2026-06-15'); // 50,000 BV this month (well above cap)
    // Right leg 20,000 BV short — more than the 15,000 BV top-up cap can bridge.
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 28_000_000);

    $result = app(RankQualificationService::class)->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(0); // 28,000,000 + 1,500,000 cap = 29,500,000 < 30,000,000
});

it('does not create carry-forward records by default (1+2 rule retired for AO-GO, KP 2026-08-05)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 700_000); // Dealer title (Rank-1 min) = 7,000 BV
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 31_000_000);

    $svc = app(RankQualificationService::class);
    $svc->checkForMonth($month, occurrenceNumber: 1);

    $records = RankQualification::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->get();

    // Only the qualifying month — the engine creates no carry-forward rows.
    expect($records)->toHaveCount(1);
    expect($records->where('is_carry_forward', true))->toHaveCount(0);
});

it('does NOT create carry-forward records for rank 2 (1+2 rule is Rank 1 only, KP 2026-06-28)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Rank 2 (Pearl): Wholesaler title (15,000 BV personal) + 8L/8L group BV per side.
    seedPersonalBv($dist->id, 1_500_000);
    seedGroupBv($dist->id, '2026-06-10', 81_000_000, 81_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month, occurrenceNumber: 1);

    expect($result['rank_2_count'])->toBe(1);

    $records = RankQualification::where('distributor_id', $dist->id)
        ->where('rank_number', 2)
        ->get();

    // Only the qualifying month — no M+1 / M+2 carry-forwards for rank 2.
    expect($records)->toHaveCount(1);
    expect($records->where('is_carry_forward', true))->toHaveCount(0);
});

/**
 * Build the rank-3 structural tree: candidate with 2 Pearl-grade qualifiers on
 * each Genos side (each with 8L/8L group BV + Wholesaler personal BV).
 *
 * @return array{candidate: Distributor}
 */
function seedEmeraldStructure(): array
{
    // Binary tree: candidate → leftQual1 ('L') → leftQual2 ('L')
    //                          candidate → rightQual1 ('R') → rightQual2 ('R')
    // Each slot (parent+side) is unique, so leftQual2 must be under leftQual1.
    $candidate = Distributor::factory()->create();
    $leftQual1 = Distributor::factory()->create();
    $leftQual2 = Distributor::factory()->create();
    $rightQual1 = Distributor::factory()->create();
    $rightQual2 = Distributor::factory()->create();

    // Candidate personal BV >= 3,200,000 (rank-3 threshold).
    seedPersonalBv($candidate->id, 6_000_000);

    // All 4 Pearl qualifiers: personal BV >= 1,500,000 + group BV >= 80M per side.
    foreach ([$leftQual1, $leftQual2, $rightQual1, $rightQual2] as $dist) {
        seedPersonalBv($dist->id, 2_000_000);
        seedGroupBv($dist->id, '2026-06-10', 81_000_000, 81_000_000);
    }

    // Direct children of candidate.
    seedGenealogyAndSide($candidate->id, $leftQual1->id, 'L', 1);
    seedGenealogyAndSide($candidate->id, $rightQual1->id, 'R', 1);

    // Depth-2 children: leftQual2 under leftQual1, rightQual2 under rightQual1.
    seedGenealogyAndSide($leftQual1->id, $leftQual2->id, 'L', 1);
    seedGenealogyAndSide($rightQual1->id, $rightQual2->id, 'R', 1);

    // Transitive closure rows for depth-2 descendants of candidate.
    DB::table('genealogy_closure')->insertOrIgnore([
        'ancestor_id' => $candidate->id, 'descendant_id' => $leftQual2->id, 'depth' => 2,
    ]);
    DB::table('genealogy_closure')->insertOrIgnore([
        'ancestor_id' => $candidate->id, 'descendant_id' => $rightQual2->id, 'depth' => 2,
    ]);

    return ['candidate' => $candidate];
}

it('qualifies a distributor for rank 3 (Emerald) with 2+ Pearls per side and their own Pearl Q-Period', function (): void {
    ['candidate' => $candidate] = seedEmeraldStructure();
    $month = Carbon::parse('2026-06-01');

    // Q-Period gate (KP 2026-08-05): the candidate must personally have
    // achieved Rank 2 once — give them the 8L/8L match this month.
    seedGroupBv($candidate->id, '2026-06-10', 81_000_000, 81_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    // 4 downline Pearls + the candidate → candidate's own R2 counts this month.
    expect($result['rank_2_count'])->toBe(5);
    expect($result['rank_3_count'])->toBeGreaterThanOrEqual(1);

    $emeraldRecord = RankQualification::where('distributor_id', $candidate->id)
        ->where('rank_number', 3)
        ->first();

    expect($emeraldRecord)->not->toBeNull();
    expect($emeraldRecord->status)->toBe(RankQualification::STATUS_QUALIFIED);
});

it('blocks rank 3 when the candidate never achieved rank 2 themselves (own Q-Period gate)', function (): void {
    ['candidate' => $candidate] = seedEmeraldStructure();
    $month = Carbon::parse('2026-06-01');

    // 2 Pearls per side but no own Rank-2 achievement, ever.
    $result = app(RankQualificationService::class)->checkForMonth($month);

    expect($result['rank_2_count'])->toBe(4);
    expect($result['rank_3_count'])->toBe(0);
    expect(RankQualification::where('distributor_id', $candidate->id)->where('rank_number', 3)->exists())->toBeFalse();
});

it('counts a prior-month own rank-2 achievement toward the Q-Period gate', function (): void {
    ['candidate' => $candidate] = seedEmeraldStructure();
    $month = Carbon::parse('2026-06-01');

    // Candidate achieved Pearl in May — no R2 group BV this month.
    RankQualification::create([
        'distributor_id' => $candidate->id,
        'rank_number' => 2,
        'month_start' => '2026-05-01',
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);

    $result = app(RankQualificationService::class)->checkForMonth($month);

    expect($result['rank_3_count'])->toBeGreaterThanOrEqual(1);
    expect(RankQualification::where('distributor_id', $candidate->id)->where('rank_number', 3)->exists())->toBeTrue();
});

it('counts Q-Period over lifetime occurrences, including two in one month (Option C, KP 2026-08-07)', function (): void {
    // Raise Rank 2's Q-Period to 2 (admin-configurable) so rank 3 needs the
    // candidate's own Pearl achieved twice, whenever.
    DB::table('rank_tiers')->where('rank_number', 2)->update(['pyp_required' => 2]);
    app()->forgetInstance(CompensationPlanSettingsService::class);

    ['candidate' => $candidate] = seedEmeraldStructure();
    $month = Carbon::parse('2026-06-01');

    // A single Pearl occurrence is not enough.
    RankQualification::create([
        'distributor_id' => $candidate->id,
        'rank_number' => 2,
        'month_start' => '2026-05-01',
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);

    $result = app(RankQualificationService::class)->checkForMonth($month);
    expect($result['rank_3_count'])->toBe(0);

    // A second occurrence in the SAME month completes the lifetime count.
    RankQualification::create([
        'distributor_id' => $candidate->id,
        'rank_number' => 2,
        'month_start' => '2026-05-01',
        'occurrence_in_month' => 2,
        'is_carry_forward' => false,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);

    $result = app(RankQualificationService::class)->checkForMonth($month, occurrenceNumber: 2);
    expect($result['rank_3_count'])->toBeGreaterThanOrEqual(1);
});

it('counts Q-Period occurrences across months with gaps (Option C, KP 2026-08-07)', function (): void {
    DB::table('rank_tiers')->where('rank_number', 2)->update(['pyp_required' => 2]);
    app()->forgetInstance(CompensationPlanSettingsService::class);

    ['candidate' => $candidate] = seedEmeraldStructure();
    $month = Carbon::parse('2026-06-01');

    // February + May — a gap in between changes nothing; the count is lifetime.
    foreach (['2026-02-01', '2026-05-01'] as $monthStart) {
        RankQualification::create([
            'distributor_id' => $candidate->id,
            'rank_number' => 2,
            'month_start' => $monthStart,
            'occurrence_in_month' => 1,
            'is_carry_forward' => false,
            'status' => RankQualification::STATUS_QUALIFIED,
        ]);
    }

    $result = app(RankQualificationService::class)->checkForMonth($month);
    expect($result['rank_3_count'])->toBeGreaterThanOrEqual(1);
    expect(RankQualification::where('distributor_id', $candidate->id)->where('rank_number', 3)->exists())->toBeTrue();
});

it('allows attaining rank 2 directly without ever holding rank 1 (skip allowed, KP 2026-08-05)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // No prior rank-1 qualification in any month; 8L/8L + Wholesaler title.
    seedPersonalBv($dist->id, 1_500_000);
    seedGroupBv($dist->id, '2026-06-10', 81_000_000, 81_000_000);

    $result = app(RankQualificationService::class)->checkForMonth($month);

    expect($result['rank_2_count'])->toBe(1);
    expect(
        RankQualification::where('distributor_id', $dist->id)->where('rank_number', 2)->exists()
    )->toBeTrue();
});

it('requires 8L per side for rank 2 — 7,99,999 BV on one side fails; the 30,000 BV top-up can bridge it', function (): void {
    // Side A: 79,999,900 paise (7,99,999 BV) with no personal top-up → fails.
    $short = Distributor::factory()->create();
    seedPersonalBv($short->id, 1_500_000); // Wholesaler title, but dated pre-June (helper stamps now())
    seedGroupBv($short->id, '2026-06-10', 81_000_000, 79_999_900);

    // Side B: 7,70,000 BV weaker side + 30,000 BV of this-month personal BV
    // (top-up cap for Rank 2) = exactly 8L → passes.
    $topped = Distributor::factory()->create();
    seedPersonalBvOn($topped->id, 3_000_000, '2026-06-15');
    seedGroupBv($topped->id, '2026-06-10', 81_000_000, 77_000_000);

    $month = Carbon::parse('2026-06-01');
    app(RankQualificationService::class)->checkForMonth($month);

    expect(RankQualification::where('distributor_id', $short->id)->where('rank_number', 2)->exists())->toBeFalse();
    expect(RankQualification::where('distributor_id', $topped->id)->where('rank_number', 2)->exists())->toBeTrue();
});
