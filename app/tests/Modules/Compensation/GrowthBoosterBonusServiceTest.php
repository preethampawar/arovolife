<?php

declare(strict_types=1);

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
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

/**
 * Seed a credited GsbCutoffResult for the given distributor, date, and slab.
 */
function gbbSeedCutoff(int $distributorId, string $date, int $slab): GsbCutoffResult
{
    return GsbCutoffResult::create([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 1_500_000,
        'right_bv_paise' => 1_500_000,
        'slab' => $slab,
        'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 3_000,
        'tds_paise' => 4_850,
        'net_gsb_paise' => 92_150,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'power_side_after' => 'L',
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);
}

/**
 * Seed company-wide BV for the month. The GBB pool is a percentage of the
 * signed bv_ledger_entries sum, exactly like the GSB and MSB pools — orders
 * are irrelevant to it.
 */
function gbbSeedCompanyBv(int $bvPaise, string $date = '2026-06-10'): void
{
    static $fakeOrderId = 900000;

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 1,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Seed a qualified rank_qualifications row for the given month.
 */
function gbbSeedRank(int $distributorId, string $monthStart, bool $isCarryForward = false): void
{
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $distributorId,
        'rank_number' => 1,
        'month_start' => $monthStart,
        'occurrence_in_month' => 1,
        'is_carry_forward' => $isCarryForward,
        'carry_forward_from_month' => null,
        'status' => 'qualified',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Put the distributor's repurchase cycle in the given state with the engine on.
 */
function gbbSeedCycle(int $distributorId, string $status): RepurchaseCycle
{
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    return RepurchaseCycle::create([
        'distributor_id' => $distributorId,
        'cycle_start_date' => '2026-05-05',
        'due_date' => '2026-06-04',
        'grace_end_date' => '2026-06-11',
        'required_bv_paise' => 100_000,
        'completed_bv_paise' => 0,
        'status' => $status,
    ]);
}

it('returns zero results when no eligible distributors have AGP', function () {
    gbbSeedCompanyBv(1_000_000);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(0);
    expect($result['credited'])->toBe(0);
});

it('freezes a zero-value pool when nobody earned AGP', function () {
    gbbSeedCompanyBv(1_000_000);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $pool = GbbMonthlyPool::first();

    expect($pool)->not->toBeNull();
    expect($pool->pool_paise)->toBe(50_000);       // 5% of 10,00,000 paise
    expect($pool->total_agp)->toBe(0);
    expect($pool->point_value_paise)->toBe(0);
    expect($pool->payout_paise)->toBe(0);
    expect($pool->leftover_paise)->toBe(50_000);   // the whole pool goes unspent
    expect($result['point_value_paise'])->toBe(0);
});

it('returns zero pool when company BV is zero', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCutoff($dist->id, '2026-06-10', 1);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['pool_paise'])->toBe(0);
    expect($result['point_value_paise'])->toBe(0);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->gbb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->count())->toBe(0);
});

it('calculates correct AGP for slab 1 (12 AGP), 2 (5 AGP), 3 (2 AGP)', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(10_000_000);          // ₹1,00,000 BV → ₹5,000 pool
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP
    gbbSeedCutoff($dist->id, '2026-06-06', 2);  // 5 AGP
    gbbSeedCutoff($dist->id, '2026-06-07', 3);  // 2 AGP

    $result = app(GrowthBoosterBonusService::class)->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();

    expect($row)->not->toBeNull();
    expect($row->agp_earned)->toBe(19);  // 12+5+2
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($result['total_agp'])->toBe(19);
    expect($result['credited'])->toBe(1);
});

it('caps AGP at 120 per distributor even with many slab 1 occurrences', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(10_000_000);

    // 11 × slab 1 = 132 AGP raw → should be capped at 120.
    for ($i = 1; $i <= 11; $i++) {
        gbbSeedCutoff($dist->id, '2026-06-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1);
    }

    app(GrowthBoosterBonusService::class)->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->agp_earned)->toBe(120);
});

it('distributes pool proportionally between two distributors', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Pool: 5% of 2,00,000 paise BV = 10,000 paise.
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP
    gbbSeedCutoff($d2->id, '2026-06-06', 2);  //  5 AGP

    $result = app(GrowthBoosterBonusService::class)->runForMonth($month);

    // Total AGP = 17. 10,000 ÷ 17 = 588.2 paise → floored to ₹5 (500 paise).
    $row1 = GbbMonthlyResult::where('distributor_id', $d1->id)->first();
    $row2 = GbbMonthlyResult::where('distributor_id', $d2->id)->first();

    expect($row1->gbb_gross_paise)->toBe(500 * 12);  // 6000
    expect($row2->gbb_gross_paise)->toBe(500 * 5);   // 2500
    expect($result['total_agp'])->toBe(17);
    expect($result['point_value_paise'])->toBe(500);
    expect($result['credited'])->toBe(2);
});

it('sets the pool to 5% of monthly company BV, floors the point value to whole rupees and keeps the residual as leftover', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(150_000, '2026-06-03');
    gbbSeedCompanyBv(50_000, '2026-06-20');
    // A June-30 entry is inside the month; a July-1 entry must not count.
    gbbSeedCompanyBv(100_000, '2026-07-01');

    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $pool = GbbMonthlyPool::first();

    expect($pool->company_bv_paise)->toBe(200_000);
    expect($pool->pool_rate_bp)->toBe(500);
    expect($pool->pool_paise)->toBe(10_000);            // 5% of 2,00,000
    expect($pool->total_agp)->toBe(12);
    expect($pool->point_value_paise)->toBe(800);        // 833.3 floored to ₹8
    expect($pool->payout_paise)->toBe(9_600);
    expect($pool->leftover_paise)->toBe(400);           // flooring residual
    expect($result['point_value_paise'])->toBe(800);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->point_value_paise)->toBe(800);
    expect($row->gbb_gross_paise)->toBe(9_600);
});

it('freezes the month economics — later BV and cut-offs never reprice it', function () {
    $d1 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);

    $firstGross = GbbMonthlyResult::where('distributor_id', $d1->id)->first()->gbb_gross_paise;

    // More BV lands, and a second distributor earns AGP for the same month.
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(5_000_000, '2026-06-25');
    gbbSeedCutoff($d2->id, '2026-06-26', 1);  // 12 AGP

    $result = $svc->runForMonth($month);

    $pool = GbbMonthlyPool::first();

    expect(GbbMonthlyPool::count())->toBe(1);
    expect($pool->company_bv_paise)->toBe(200_000);
    expect($pool->pool_paise)->toBe(10_000);
    expect($pool->total_agp)->toBe(12);
    expect($pool->point_value_paise)->toBe(800);
    expect($result['point_value_paise'])->toBe(800);

    // The already-paid distributor is untouched; the newcomer is priced at the
    // frozen value (the overspend is the company's, not a reprice).
    expect(GbbMonthlyResult::where('distributor_id', $d1->id)->first()->gbb_gross_paise)->toBe($firstGross);
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->first()->gbb_gross_paise)->toBe(9_600);
});

it('deducts 3% admin charge and 5% TDS', function () {
    // KP 2026-06-26: GBB is now within the admin-charge scope.
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);

    app(GrowthBoosterBonusService::class)->runForMonth($month);

    // Deductions are applied at payout time, not at credit time.
    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();

    expect($row->admin_charge_paise)->toBe(0);
    expect($row->tds_paise)->toBe(0);
    expect($row->gbb_net_paise)->toBe($row->gbb_gross_paise);
});

it('credits wallet via gbb_credit type', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);

    app(GrowthBoosterBonusService::class)->runForMonth($month);

    $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'gbb_credit')
        ->first();

    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBeGreaterThan(0);
});

it('is idempotent — re-running the same month does not double-credit', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);  // second run

    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->count())->toBe(1);
});

it('skips slabs 4–7 (no AGP awarded)', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);

    // Only slab 4 and above — should yield 0 AGP, no credit.
    GsbCutoffResult::create([
        'distributor_id' => $dist->id,
        'cutoff_date' => '2026-06-05',
        'left_bv_paise' => 27_000_000,
        'right_bv_paise' => 27_000_000,
        'slab' => 4,
        'gross_gsb_paise' => 1_200_000,
        'admin_charge_paise' => 30_000,
        'tds_paise' => 58_500,
        'net_gsb_paise' => 1_111_500,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'power_side_after' => 'L',
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);

    $result = app(GrowthBoosterBonusService::class)->runForMonth($month);

    expect($result['total_agp'])->toBe(0);
    expect($result['credited'])->toBe(0);
});

it('excludes a distributor who held a qualified rank in the previous month', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP, never ranked
    gbbSeedCutoff($d2->id, '2026-06-06', 1);  // 12 AGP, ranked in May
    gbbSeedRank($d2->id, '2026-05-01');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(12);   // only d1 in the denominator
    expect($result['credited'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);
});

it('excludes a prior-month carry-forward rank too — a paid carry row still means ranked', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRank($dist->id, '2026-05-01', isCarryForward: true);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(0);
    expect($result['credited'])->toBe(0);
});

it('keeps a distributor ranked for the FIRST time in the current month eligible', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRank($dist->id, '2026-06-01');  // this month only — no prior-month row

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['credited'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->first()->status)
        ->toBe(GbbMonthlyResult::STATUS_CREDITED);
});

it('makes a distributor ranked in M-2 but not M-1 eligible again', function () {
    // Documents the literal reading of the spec: only the IMMEDIATELY previous
    // month is checked, so a lapsed ranker re-enters the Growth Booster.
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRank($dist->id, '2026-04-01');  // M-2, nothing in May

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['credited'])->toBe(1);
    expect($result['total_agp'])->toBe(12);
});

it('holds a grace-window distributor without crediting, but keeps their AGP in the denominator', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP, payable
    gbbSeedCutoff($d2->id, '2026-06-06', 2);  //  5 AGP, in grace
    gbbSeedCycle($d2->id, RepurchaseCycle::STATUS_GRACE);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    // Denominator is 17, not 12 — held AGP still dilutes because it can be paid.
    expect($result['total_agp'])->toBe(17);
    expect($result['point_value_paise'])->toBe(500);
    expect($result['credited'])->toBe(1);
    expect($result['held'])->toBe(1);

    $heldRow = GbbMonthlyResult::where('distributor_id', $d2->id)->first();
    expect($heldRow->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_HELD);
    expect($heldRow->gbb_gross_paise)->toBe(2_500);
    expect($heldRow->credited_at)->toBeNull();
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);
});

it('releases a held month on reactivation and never double-credits on a re-fired event', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP
    $cycle = gbbSeedCycle($dist->id, RepurchaseCycle::STATUS_GRACE);

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_HELD);

    event(new IncomeReactivated($dist->id, $cycle->id));
    event(new IncomeReactivated($dist->id, $cycle->id));  // re-fired

    $row->refresh();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($row->credited_at)->not->toBeNull();

    $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->get();
    expect($ledger)->toHaveCount(1);
    expect($ledger->first()->amount_paise)->toBe($row->gbb_gross_paise);
});

it('excludes a suspended distributor from the denominator and never releases them', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);                // pool = 10,000 paise
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP, payable
    gbbSeedCutoff($d2->id, '2026-06-06', 2);  //  5 AGP, suspended
    $cycle = gbbSeedCycle($d2->id, RepurchaseCycle::STATUS_SUSPENDED);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    // Baseline: with only d1's 12 AGP the value is 10,000 ÷ 12 = 833 → ₹8.
    // Had the suspended 5 AGP diluted the pool it would have been ₹5 (see the
    // two-distributor proportional test, same pool and same AGP).
    expect($result['total_agp'])->toBe(12);
    expect($result['point_value_paise'])->toBe(800);
    expect($result['suspended'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $d1->id)->first()->gbb_gross_paise)->toBe(9_600);

    $suspendedRow = GbbMonthlyResult::where('distributor_id', $d2->id)->first();
    expect($suspendedRow->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED);
    expect($suspendedRow->agp_earned)->toBe(5);
    expect($suspendedRow->gbb_gross_paise)->toBe(0);

    // Forfeited: reactivation must never release a suspended month.
    event(new IncomeReactivated($d2->id, $cycle->id));

    $suspendedRow->refresh();
    expect($suspendedRow->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED);
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);
});
