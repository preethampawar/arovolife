<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankQualification;
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

    seedPersonalBv($dist->id, 600_000);
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

    seedPersonalBv($dist->id, 600_000);
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 10_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(0);
});

it('creates carry-forward records for M+1 and M+2 when rank 1 is achieved', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 600_000);
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
