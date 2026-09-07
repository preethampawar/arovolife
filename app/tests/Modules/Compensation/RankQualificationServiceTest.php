<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankQualificationService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

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
    seedGroupBv($dist->id, '2026-06-10', 26_000_000, 26_000_000);

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
    seedGroupBv($dist->id, '2026-06-10', 26_000_000, 26_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(0);
    expect(RankQualification::count())->toBe(0);
});

it('does not qualify for rank 1 when only one side meets the group BV threshold', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 700_000); // meets Dealer title; only the weak side should disqualify
    seedGroupBv($dist->id, '2026-06-10', 26_000_000, 10_000_000);

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
    // supplement the weaker Genos leg toward the rank-1 2.5L/2.5L match.
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // 7,000 BV personal purchase in June → meets Dealer title AND feeds the top-up.
    seedPersonalBvOn($dist->id, 700_000, '2026-06-15');
    // Right (weaker) leg is 5,000 BV short of the 2.5L (25,000,000 paise) match.
    seedGroupBv($dist->id, '2026-06-10', 26_000_000, 24_500_000);

    $result = app(RankQualificationService::class)->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(1); // 24,500,000 + 700,000 top-up ≥ 25,000,000

    $record = RankQualification::where('distributor_id', $dist->id)->where('rank_number', 1)->first();
    expect($record)->not->toBeNull();
    // The recorded group BV stays the RAW figure — the top-up only aids qualification.
    expect((int) $record->right_genos_bv_paise)->toBe(24_500_000);
});

it('caps the rank-1 weaker-leg top-up at 15,000 BV', function (): void {
    // A shortfall larger than the 15,000 BV (1,500,000 paise) cap cannot be
    // fully covered even with abundant personal BV → no qualification.
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBvOn($dist->id, 5_000_000, '2026-06-15'); // 50,000 BV this month (well above cap)
    // Right leg 20,000 BV short — more than the 15,000 BV top-up cap can bridge.
    seedGroupBv($dist->id, '2026-06-10', 26_000_000, 23_000_000);

    $result = app(RankQualificationService::class)->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(0); // 23,000,000 + 1,500,000 cap = 24,500,000 < 25,000,000
});

it('does not create carry-forward records by default (1+2 rule retired for AO-GO, KP 2026-08-05)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 700_000); // Dealer title (Rank-1 min) = 7,000 BV
    seedGroupBv($dist->id, '2026-06-10', 26_000_000, 26_000_000);

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

    // Rank 2 (Pearl): Wholesaler title (15,000 BV personal) + 6L/6L group BV per side.
    seedPersonalBv($dist->id, 1_500_000);
    seedGroupBv($dist->id, '2026-06-10', 61_000_000, 61_000_000);

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
 * each Genos side (each with 6L/6L group BV + Wholesaler personal BV).
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

    // All 4 Pearl qualifiers: personal BV >= 1,500,000 + group BV >= 60M per side.
    foreach ([$leftQual1, $leftQual2, $rightQual1, $rightQual2] as $dist) {
        seedPersonalBv($dist->id, 2_000_000);
        seedGroupBv($dist->id, '2026-06-10', 61_000_000, 61_000_000);
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
    // achieved Rank 2 once — give them the 6L/6L match this month.
    seedGroupBv($candidate->id, '2026-06-10', 61_000_000, 61_000_000);

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

    // No prior rank-1 qualification in any month; 6L/6L + Wholesaler title.
    seedPersonalBv($dist->id, 1_500_000);
    seedGroupBv($dist->id, '2026-06-10', 61_000_000, 61_000_000);

    $result = app(RankQualificationService::class)->checkForMonth($month);

    expect($result['rank_2_count'])->toBe(1);
    expect(
        RankQualification::where('distributor_id', $dist->id)->where('rank_number', 2)->exists()
    )->toBeTrue();
});

it('requires 6L per side for rank 2 — 5,99,999 BV on one side fails; the 30,000 BV top-up can bridge it', function (): void {
    // Side A: 59,999,900 paise (5,99,999 BV) with no personal top-up → fails.
    $short = Distributor::factory()->create();
    seedPersonalBv($short->id, 1_500_000); // Wholesaler title, but dated pre-June (helper stamps now())
    seedGroupBv($short->id, '2026-06-10', 61_000_000, 59_999_900);

    // Side B: 5,70,000 BV weaker side + 30,000 BV of this-month personal BV
    // (top-up cap for Rank 2) = exactly 6L → passes.
    $topped = Distributor::factory()->create();
    seedPersonalBvOn($topped->id, 3_000_000, '2026-06-15');
    seedGroupBv($topped->id, '2026-06-10', 61_000_000, 57_000_000);

    $month = Carbon::parse('2026-06-01');
    app(RankQualificationService::class)->checkForMonth($month);

    expect(RankQualification::where('distributor_id', $short->id)->where('rank_number', 2)->exists())->toBeFalse();
    expect(RankQualification::where('distributor_id', $topped->id)->where('rank_number', 2)->exists())->toBeTrue();
});

// ── Forfeited days: rank counts only compliant days (client spec 2026-09-07 §2.2) ──

/**
 * A resolved repurchase cycle, straight from the calendar dates the client's RB
 * examples use. `$fulfilledOn` null = still failed at the time of the run.
 */
function seedRankCycle(int $distributorId, string $start, string $due, ?string $fulfilledOn): void
{
    RepurchaseCycle::create([
        'distributor_id' => $distributorId,
        'cycle_start_date' => $start,
        'due_date' => $due,
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => $fulfilledOn === null ? 0 : 60_000,
        'status' => $fulfilledOn === null ? RepurchaseCycle::STATUS_SUSPENDED : RepurchaseCycle::STATUS_COMPLETED,
        'fulfilled_on' => $fulfilledOn,
        'failure_reason' => $fulfilledOn === null ? RepurchaseCycle::REASON_BV_SHORT : null,
        'resolved_at' => Carbon::parse($due)->addDay()->toDateString().' 00:05:00',
    ]);
}

it('grants rank 1 on the 1-23 Aug BV of a distributor still failed at month end (RB example 1)', function (): void {
    // Client doc: cycle 24 Jul - 23 Aug, no repurchase 24-31 Aug. Counted
    // 1-23 Aug: L 2.8L / R 2.9L. Rank 1 is granted anyway — the Rank Bonus is
    // never withheld by the repurchase state, only the failed days' BV is lost.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    seedPersonalBvOn($dist->id, 700_000, '2026-01-05'); // lifetime title gate only
    seedGroupBv($dist->id, '2026-08-10', 28_000_000, 29_000_000);
    seedGroupBv($dist->id, '2026-08-25', 10_000_000, 10_000_000); // forfeited
    seedRankCycle($dist->id, '2026-07-24', '2026-08-23', null);

    $result = app(RankQualificationService::class)->checkForMonth(Carbon::parse('2026-08-01'));

    expect($result['rank_1_count'])->toBe(1);

    $record = RankQualification::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($record)->not->toBeNull()
        ->and((int) $record->left_genos_bv_paise)->toBe(28_000_000)
        ->and((int) $record->right_genos_bv_paise)->toBe(29_000_000);
});

it('refuses rank 1 when the surviving left leg is short (RB example 2)', function (): void {
    // Same cycle; 1-23 Aug L 2.1L / R 2.9L. The 25 Aug BV would carry the left
    // leg over 2.5L, and is exactly what the failed days forfeit.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    seedPersonalBvOn($dist->id, 700_000, '2026-01-05');
    seedGroupBv($dist->id, '2026-08-10', 21_000_000, 29_000_000);
    seedGroupBv($dist->id, '2026-08-25', 10_000_000, 0); // forfeited
    seedRankCycle($dist->id, '2026-07-24', '2026-08-23', null);

    $result = app(RankQualificationService::class)->checkForMonth(Carbon::parse('2026-08-01'));

    expect($result['rank_1_count'])->toBe(0)
        ->and(RankQualification::where('distributor_id', $dist->id)->exists())->toBeFalse();
});

it('adds the post-fulfilment days back and grants rank 1 (RB example 3)', function (): void {
    // Fails 24-26 Aug, fulfils 27 Aug. Counted = 1-23 Aug (L 2.3L / R 3.0L)
    // plus 27-31 Aug (L 0.2L) = exactly the rank-1 2.5L target.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $required = app(CompensationPlanSettingsService::class)->rankGroupBvRequired(1);

    $dist = Distributor::factory()->create();
    seedPersonalBvOn($dist->id, 700_000, '2026-01-05');
    seedGroupBv($dist->id, '2026-08-10', 23_000_000, 30_000_000);
    seedGroupBv($dist->id, '2026-08-25', 10_000_000, 10_000_000); // forfeited
    seedGroupBv($dist->id, '2026-08-28', 2_000_000, 0);
    seedRankCycle($dist->id, '2026-07-24', '2026-08-23', '2026-08-27');

    $result = app(RankQualificationService::class)->checkForMonth(Carbon::parse('2026-08-01'));

    expect($result['rank_1_count'])->toBe(1);

    $record = RankQualification::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect((int) $record->left_genos_bv_paise)->toBe(25_000_000)
        ->and((int) $record->left_genos_bv_paise)->toBe($required)
        ->and((int) $record->right_genos_bv_paise)->toBe(30_000_000);
});

it('does not touch a closed month for a cycle that only starts failing on the 1st of the next month', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    seedPersonalBvOn($dist->id, 700_000, '2026-01-05');
    seedGroupBv($dist->id, '2026-08-25', 26_000_000, 26_000_000);
    seedRankCycle($dist->id, '2026-08-01', '2026-08-31', '2026-09-05'); // forfeits 1-4 Sep

    $result = app(RankQualificationService::class)->checkForMonth(Carbon::parse('2026-08-01'));

    $record = RankQualification::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($result['rank_1_count'])->toBe(1)
        ->and((int) $record->left_genos_bv_paise)->toBe(26_000_000)
        ->and((int) $record->right_genos_bv_paise)->toBe(26_000_000);
});

it('excludes nothing while the repurchase engine flag is off', function (): void {
    // RB example 2's numbers with the engine off: the 25 Aug BV counts and the
    // distributor qualifies on the raw month sum, exactly as before the change.
    $dist = Distributor::factory()->create();
    seedPersonalBvOn($dist->id, 700_000, '2026-01-05');
    seedGroupBv($dist->id, '2026-08-10', 21_000_000, 29_000_000);
    seedGroupBv($dist->id, '2026-08-25', 10_000_000, 0);
    seedRankCycle($dist->id, '2026-07-24', '2026-08-23', null);

    $result = app(RankQualificationService::class)->checkForMonth(Carbon::parse('2026-08-01'));

    $record = RankQualification::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($result['rank_1_count'])->toBe(1)
        ->and((int) $record->left_genos_bv_paise)->toBe(31_000_000);
});

it('leaves the weaker-leg personal top-up untouched by failed days', function (): void {
    // Personal purchase BV is not Genos BV: a failed day forfeits group BV only,
    // so the 15,000-BV top-up still bridges the surviving 2.35L left leg to 2.5L
    // even though the purchase itself falls on a forfeited day.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    seedPersonalBvOn($dist->id, 1_500_000, '2026-08-25'); // on a forfeited day
    seedGroupBv($dist->id, '2026-08-10', 23_500_000, 30_000_000);
    seedGroupBv($dist->id, '2026-08-25', 10_000_000, 0); // forfeited
    seedRankCycle($dist->id, '2026-07-24', '2026-08-23', '2026-08-27');

    $result = app(RankQualificationService::class)->checkForMonth(Carbon::parse('2026-08-01'));

    $record = RankQualification::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($result['rank_1_count'])->toBe(1)
        ->and((int) $record->left_genos_bv_paise)->toBe(23_500_000);
});

it('never returns a negative counted side when reversals exceed the month sum', function (): void {
    // Group BV can be debited by a cancelled order, so a forfeited range may sum
    // to more than the month itself. The counted BV clamps at zero per side.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    seedGroupBv($dist->id, '2026-08-15', -4_000_000, -4_000_000); // a cancelled order's reversal
    seedGroupBv($dist->id, '2026-08-25', 5_000_000, 5_000_000); // forfeited: more than the month's 1L net
    seedRankCycle($dist->id, '2026-07-24', '2026-08-23', '2026-08-27');

    $counted = app(RankQualificationService::class)
        ->countedGenosBvForMonth(Carbon::parse('2026-08-01'));

    expect($counted[$dist->id])->toBe(['left' => 0, 'right' => 0]);
});
