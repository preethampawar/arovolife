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

it('creates carry-forward records for M+1 and M+2 when rank 1 is achieved', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 700_000); // Dealer title (Rank-1 min) = 7,000 BV
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 31_000_000);

    $svc = app(RankQualificationService::class);
    $svc->checkForMonth($month, occurrenceNumber: 1);

    $records = RankQualification::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->get();

    expect($records)->toHaveCount(3);

    $carryForwards = $records->where('is_carry_forward', true);
    expect($carryForwards)->toHaveCount(2);

    $months = $carryForwards->pluck('month_start')->sort()->values();
    expect($months[0])->toBe('2026-07-01');
    expect($months[1])->toBe('2026-08-01');
});

it('does NOT create carry-forward records for rank 2 (1+2 rule is Rank 1 only, KP 2026-06-28)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Rank 2 (Pearl): Wholesaler title (15,000 BV personal) + 5L/5L group BV per side.
    seedPersonalBv($dist->id, 1_500_000);
    seedGroupBv($dist->id, '2026-06-10', 51_000_000, 51_000_000);

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

it('reads carry-forward months from rank_tiers config (admin-configurable, not hardcoded)', function (): void {
    // Admin lowers Rank 1's carry-forward to a single month.
    DB::table('rank_tiers')->where('rank_number', 1)->update(['carry_forward_months' => 1]);
    app()->forgetInstance(CompensationPlanSettingsService::class);

    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    seedPersonalBv($dist->id, 700_000);
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 31_000_000);

    app(RankQualificationService::class)->checkForMonth($month, occurrenceNumber: 1);

    $records = RankQualification::where('distributor_id', $dist->id)->where('rank_number', 1)->get();
    expect($records)->toHaveCount(2); // qualifying month + 1 carry-forward (not the default 2)
    expect($records->where('is_carry_forward', true))->toHaveCount(1);
});

it('qualifies a distributor for rank 3 (Emerald) when they have 2+ Pearl qualifiers on each Genos side', function (): void {
    // Binary tree: candidate → leftQual1 ('L') → leftQual2 ('L')
    //                          candidate → rightQual1 ('R') → rightQual2 ('R')
    // Each slot (parent+side) is unique, so leftQual2 must be under leftQual1.
    $candidate = Distributor::factory()->create();
    $leftQual1 = Distributor::factory()->create();
    $leftQual2 = Distributor::factory()->create();
    $rightQual1 = Distributor::factory()->create();
    $rightQual2 = Distributor::factory()->create();

    $month = Carbon::parse('2026-06-01');

    // Candidate personal BV >= 5,000,000 (rank-3 threshold).
    seedPersonalBv($candidate->id, 6_000_000);

    // All 4 Pearl qualifiers: personal BV >= 1,500,000 + group BV >= 50M per side.
    foreach ([$leftQual1, $leftQual2, $rightQual1, $rightQual2] as $dist) {
        seedPersonalBv($dist->id, 2_000_000);
        seedGroupBv($dist->id, '2026-06-10', 51_000_000, 51_000_000);
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

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    // All 4 qualify for rank 2 → candidate's L and R sides each have 2 rank-2 quals.
    expect($result['rank_2_count'])->toBe(4);
    expect($result['rank_3_count'])->toBeGreaterThanOrEqual(1);

    $emeraldRecord = RankQualification::where('distributor_id', $candidate->id)
        ->where('rank_number', 3)
        ->first();

    expect($emeraldRecord)->not->toBeNull();
    expect($emeraldRecord->status)->toBe(RankQualification::STATUS_QUALIFIED);
});
