<?php

declare(strict_types=1);

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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

    // The already-paid distributor is untouched; the newcomer has no roster row
    // and is refused — paying them would spend a pool already fully divided.
    expect(GbbMonthlyResult::where('distributor_id', $d1->id)->first()->gbb_gross_paise)->toBe($firstGross);
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
    expect($result['qualified_after_freeze'])->toBe(1);
});

it('refuses a distributor whose AGP lands after the freeze and never overspends the frozen pool', function () {
    $d1 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');   // pool = 10,000 paise
    gbbSeedCutoff($d1->id, '2026-06-05', 1);   // 12 AGP → point value ₹8

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);

    // A gsb:daily-cutoff re-run for a date inside the closed month adds a
    // credited cut-off that was not in the frozen denominator.
    $d2 = Distributor::factory()->create();
    gbbSeedCutoff($d2->id, '2026-06-26', 1);   // 12 AGP, after the freeze

    $second = $svc->runForMonth($month);

    expect($second['credited'])->toBe(0);
    expect($second['qualified_after_freeze'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);

    // The month still spends exactly what it froze.
    $pool = GbbMonthlyPool::first();
    $creditedGross = (int) GbbMonthlyResult::where('status', GbbMonthlyResult::STATUS_CREDITED)->sum('gbb_gross_paise');

    expect($creditedGross)->toBe((int) $pool->payout_paise);
    expect((int) $pool->leftover_paise)->toBe((int) $pool->pool_paise - $creditedGross);
    expect((int) $pool->leftover_paise)->toBeGreaterThanOrEqual(0);

    // R-35: the refusal permanently withholds a month's income, so it is an
    // audit fact with an 8-year retention, not a log line that rotates away.
    $audit = DB::table('audit_log')
        ->where('action', 'gbb.result.qualified_after_freeze')
        ->where('subject_type', 'distributor')
        ->where('subject_id', $d2->id)
        ->get();

    expect($audit)->toHaveCount(1);

    $details = json_decode((string) $audit->first()->details, true);
    expect($details['year_month'])->toBe('2026-06-01')
        ->and($details['agp'])->toBe(12)
        ->and($details['frozen_total_agp'])->toBe(12)
        ->and($details['refused_gross_paise'])->toBe(9_600);
});

it('releases a held row at its frozen gross even after its live AGP has grown', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);                // pool = 10,000 paise
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP, payable
    gbbSeedCutoff($d2->id, '2026-06-06', 2);  //  5 AGP, in grace
    $cycle = gbbSeedCycle($d2->id, RepurchaseCycle::STATUS_GRACE);

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth(Carbon::parse('2026-06-01'));

    // 17 AGP in the denominator → ₹5 a point → the held row is worth ₹25.
    $held = GbbMonthlyResult::where('distributor_id', $d2->id)->first();
    expect($held->agp_earned)->toBe(5);
    expect($held->gbb_gross_paise)->toBe(2_500);

    // A late cut-off for the same month would take the held distributor to 17
    // AGP. It landed after the freeze, so it must not re-price the row.
    gbbSeedCutoff($d2->id, '2026-06-20', 1);  // +12 AGP live
    $svc->runForMonth(Carbon::parse('2026-06-01'));

    $held->refresh();
    expect($held->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_HELD);
    expect($held->agp_earned)->toBe(5);
    expect($held->gbb_gross_paise)->toBe(2_500);

    event(new IncomeReactivated($d2->id, $cycle->id));

    $held->refresh();
    expect($held->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect((int) WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->sum('amount_paise'))
        ->toBe(2_500);

    // Nothing was paid beyond the frozen payout.
    $pool = GbbMonthlyPool::first();
    expect((int) GbbMonthlyResult::where('status', GbbMonthlyResult::STATUS_CREDITED)->sum('gbb_gross_paise'))
        ->toBe((int) $pool->payout_paise);
});

it('freezes the repurchase deduction on the row; admin charge and TDS are left to the payout', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);

    app(GrowthBoosterBonusService::class)->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();

    expect($row->admin_charge_paise)->toBe(0);
    expect($row->tds_paise)->toBe(0);
    expect($row->repurchase_deduction_paise)->toBe((int) floor($row->gbb_gross_paise / 10));
    expect($row->gbb_net_paise)->toBe($row->gbb_gross_paise - $row->repurchase_deduction_paise);
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

it('re-freezes a pool that was frozen before the month closed, and discards the rows it produced', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');

    $svc = app(GrowthBoosterBonusService::class);

    // A manual run made WHILE June was still open, before anyone had earned
    // AGP: the month freezes at a zero denominator, which would otherwise pay
    // ₹0 for June for ever.
    Carbon::setTestNow('2026-06-10 12:00:00');
    $svc->runForMonth($month);
    expect(GbbMonthlyPool::first()->total_agp)->toBe(0);

    // June closes; the earner's cut-off is in the books; the scheduled run fires.
    Carbon::setTestNow('2026-07-01 00:45:00');
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP
    $result = $svc->runForMonth($month);

    $pool = GbbMonthlyPool::first();

    expect(GbbMonthlyPool::count())->toBe(1);
    expect($pool->total_agp)->toBe(12);
    expect($pool->point_value_paise)->toBe(800);
    expect($result['credited'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->first()->gbb_gross_paise)->toBe(9_600);

    // R-35: the replacement is an audit fact, not just a log line.
    expect(DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->count())->toBe(1);
});

it('keeps a premature pool once a distributor has been paid against it', function () {
    $d1 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP

    $svc = app(GrowthBoosterBonusService::class);

    // Mid-month run that DID pay: those economics can never move again.
    Carbon::setTestNow('2026-06-10 12:00:00');
    $svc->runForMonth($month);
    $paidPool = GbbMonthlyPool::first();
    expect($paidPool->total_agp)->toBe(12);

    // More AGP lands, and the month closes.
    Carbon::setTestNow('2026-07-01 00:45:00');
    $d2 = Distributor::factory()->create();
    gbbSeedCutoff($d2->id, '2026-06-26', 1);
    $svc->runForMonth($month);

    $pool = GbbMonthlyPool::first();

    expect(GbbMonthlyPool::count())->toBe(1);
    expect($pool->id)->toBe($paidPool->id);
    expect($pool->total_agp)->toBe(12);
    expect(DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->count())->toBe(0);
    // The newcomer arrived after the roster closed, exactly as a normal re-run.
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
});

it('keeps a premature pool when the row it funded was credited at a zero gross', function () {
    // The month froze with no company BV, so the point value floored to ₹0 and
    // the earner was credited ₹0. That row is still the record of a real
    // participation in this pool — re-freezing it would move economics a
    // distributor has already been shown.
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP, no BV anywhere

    $svc = app(GrowthBoosterBonusService::class);

    Carbon::setTestNow('2026-06-10 12:00:00');
    $svc->runForMonth($month);

    $prematurePool = GbbMonthlyPool::first();
    $zeroRow = GbbMonthlyResult::where('distributor_id', $dist->id)->first();

    expect($prematurePool->total_agp)->toBe(12);
    expect($zeroRow->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($zeroRow->gbb_gross_paise)->toBe(0);

    // The month closes and BV arrives late. The pool is NOT replaced.
    Carbon::setTestNow('2026-07-01 00:45:00');
    gbbSeedCompanyBv(200_000, '2026-06-25');
    $svc->runForMonth($month);

    $pool = GbbMonthlyPool::first();

    expect(GbbMonthlyPool::count())->toBe(1);
    expect($pool->id)->toBe($prematurePool->id);
    expect($pool->pool_paise)->toBe(0);
    expect(GbbMonthlyResult::whereKey($zeroRow->id)->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->count())->toBe(0);
});

it('snapshots the rows a premature re-freeze discards into the audit details', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');
    gbbSeedCutoff($dist->id, '2026-06-06', 2);  // 5 AGP
    // Unspent repurchase wallet → a wallet-blocked row: gross 0, never funded
    // by the pool, so the premature pool can still be replaced.
    gbbSeedRepurchaseWalletCredit($dist->id, 50_000, '2026-06-02 09:00:00');

    $svc = app(GrowthBoosterBonusService::class);

    Carbon::setTestNow('2026-06-10 12:00:00');
    $svc->runForMonth($month);

    $discardedRow = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($discardedRow->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED);

    Carbon::setTestNow('2026-07-01 00:45:00');
    $svc->runForMonth($month);

    $audit = DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->first();
    expect($audit)->not->toBeNull();

    $details = json_decode((string) $audit->details, true);

    expect($details['discarded_results'])->toBe(1);
    expect($details['discarded_rows'])->toHaveCount(1);
    expect($details['discarded_rows'][0]['id'])->toBe($discardedRow->id)
        ->and($details['discarded_rows'][0]['distributor_id'])->toBe($dist->id)
        ->and($details['discarded_rows'][0]['agp_earned'])->toBe(5)
        ->and($details['discarded_rows'][0]['status'])->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED)
        ->and($details['discarded_rows'][0]['gbb_gross_paise'])->toBe(0);
});

it('refuses the monthly run when the previous month rank check never succeeded', function () {
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    // GBB for July reads JUNE's qualifications to exclude last month's rankers.
    // A succeeded check for July is the wrong month and must not open the gate.
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => '2026-07-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-07-03');
    gbbSeedCutoff($dist->id, '2026-07-05', 1);
    gbbSeedRank($dist->id, '2026-06-01');   // ranked in June — the plan bars them

    $exit = Artisan::call('gbb:monthly-run', ['--month' => '2026-07']);

    expect($exit)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('rank:check-qualifications --month=2026-06');
    // The barred distributor was NOT credited behind an empty exclusion list.
    expect(GbbMonthlyResult::where('year_month', '2026-07-01')->count())->toBe(0);
    expect(GbbMonthlyPool::count())->toBe(0);
});

it('runs once the previous month rank check has succeeded, still excluding last month rankers', function () {
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => '2026-06-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-07-03');
    gbbSeedCutoff($dist->id, '2026-07-05', 1);
    gbbSeedRank($dist->id, '2026-06-01');

    expect(Artisan::call('gbb:monthly-run', ['--month' => '2026-07']))->toBe(Command::SUCCESS);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->count())->toBe(0);
});

/**
 * Seed an unspent repurchase-wallet credit so the distributor fails the
 * repurchase wallet = ₹0 gate at month end.
 */
function gbbSeedRepurchaseWalletCredit(int $distributorId, int $amountPaise, string $createdAt): void
{
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'type' => 'repurchase_deduction',
        'amount_paise' => abs($amountPaise),
        'reference_id' => null,
        'reference_type' => null,
        'memo' => 'test',
        'created_at' => $createdAt,
    ]);
}

it('refuses to credit a suspended distributor who becomes payable on a re-run, so the frozen pool is never overspent', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);                // pool = 10,000 paise
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP, payable
    gbbSeedCutoff($d2->id, '2026-06-06', 2);  //  5 AGP, suspended
    $cycle = gbbSeedCycle($d2->id, RepurchaseCycle::STATUS_SUSPENDED);

    $first = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));
    expect($first['total_agp'])->toBe(12);    // d2's 5 AGP never entered the denominator

    // d2 becomes repurchase-compliant and the month is re-run. The month's
    // frozen point value was priced without their AGP, so they must not be paid.
    $cycle->update(['status' => RepurchaseCycle::STATUS_ACTIVE]);

    $second = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($second['credited'])->toBe(0);
    expect($second['total_agp'])->toBe(12);

    $refused = GbbMonthlyResult::where('distributor_id', $d2->id)->first();
    expect($refused->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED);
    expect($refused->gbb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);

    // The month still spends exactly what it froze.
    $pool = GbbMonthlyPool::first();
    $creditedGross = (int) GbbMonthlyResult::where('status', GbbMonthlyResult::STATUS_CREDITED)->sum('gbb_gross_paise');

    expect($creditedGross)->toBe((int) $pool->payout_paise);
    expect((int) $pool->leftover_paise)->toBe((int) $pool->pool_paise - $creditedGross);
    expect((int) $pool->leftover_paise)->toBeGreaterThanOrEqual(0);

    // R-35: the refusal permanently withholds a month's income, so it is an
    // audit fact with an 8-year retention, not a log line that rotates away.
    $audit = DB::table('audit_log')
        ->where('action', 'gbb.result.excluded_from_frozen_denominator')
        ->where('subject_type', 'distributor')
        ->where('subject_id', $d2->id)
        ->get();

    expect($audit)->toHaveCount(1);

    $details = json_decode((string) $audit->first()->details, true);
    expect($details['year_month'])->toBe('2026-06-01')
        ->and($details['agp'])->toBe(5)
        ->and($details['frozen_total_agp'])->toBe(12)
        ->and($details['existing_status'])->toBe(GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED);
});

it('still releases a held row after a re-run — held AGP was inside the frozen denominator', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP
    $cycle = gbbSeedCycle($dist->id, RepurchaseCycle::STATUS_GRACE);

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));
    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));  // re-run

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_HELD);
    expect($row->gbb_gross_paise)->toBe(9_600);

    event(new IncomeReactivated($dist->id, $cycle->id));

    $row->refresh();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect((int) WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->sum('amount_paise'))->toBe(9_600);
});

it('records a repurchase-wallet-blocked row so the blocked distributor is visible on the month', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);                // pool = 10,000 paise
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP, payable
    gbbSeedCutoff($d2->id, '2026-06-06', 2);  //  5 AGP, wallet never spent down
    gbbSeedRepurchaseWalletCredit($d2->id, 50_000, '2026-06-03 09:00:00');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(12);   // blocked AGP excluded from the denominator
    expect($result['skipped_wallet_nonzero'])->toBe(1);
    expect($result['wallet_blocked'])->toBe(1);

    $blocked = GbbMonthlyResult::where('distributor_id', $d2->id)->first();
    expect($blocked)->not->toBeNull();
    expect($blocked->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED);
    expect($blocked->agp_earned)->toBe(5);
    expect($blocked->gbb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);
});

it('refuses to credit a wallet-blocked distributor on a later run once their wallet is spent', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($d1->id, '2026-06-05', 1);
    gbbSeedCutoff($d2->id, '2026-06-06', 2);
    gbbSeedRepurchaseWalletCredit($d2->id, 50_000, '2026-06-03 09:00:00');

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    // The wallet is spent down to ₹0 — but the month's denominator never held
    // their AGP, so the month can still never pay them.
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $d2->id,
        'type' => 'repurchase_wallet_used',
        'amount_paise' => -50_000,
        'reference_id' => null,
        'reference_type' => null,
        'memo' => 'test',
        'created_at' => '2026-06-20 09:00:00',
    ]);

    $second = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($second['credited'])->toBe(0);

    $blocked = GbbMonthlyResult::where('distributor_id', $d2->id)->first();
    expect($blocked->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED);
    expect($blocked->gbb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);

    $pool = GbbMonthlyPool::first();
    expect((int) $pool->leftover_paise)->toBeGreaterThanOrEqual(0);
});
