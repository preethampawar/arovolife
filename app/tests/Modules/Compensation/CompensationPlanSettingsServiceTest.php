<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

// ── Scalar fallback + override ──────────────────────────────────────────────

it('falls back to the registry default when a scalar setting is absent', function () {
    // No settings rows seeded → defaults apply.
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->tdsRateBp())->toBe(500);                 // 5%
    expect($plan->adminChargeRateBp())->toBe(300);          // 3%
    expect($plan->adminChargeCapPaise())->toBe(3_000_000);  // ₹30,000
    expect($plan->minPayoutPaise())->toBe(10_000);          // ₹100 (KP)
    expect($plan->gsbScoreRatePaise())->toBe(36_000);       // ₹360/point
});

it('reads an overridden scalar setting from the database', function () {
    DB::table('settings')->insert([
        'key' => 'comp.tds.rate_bp', 'value' => '1000', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(CompensationPlanSettingsService::class)->tdsRateBp())->toBe(1000); // 10%
});

it('excludes Fortune ranks 6–9 by default and respects an override', function () {
    expect(app(CompensationPlanSettingsService::class)->fortuneIneligibleRanks())
        ->toBe([6, 7, 8, 9]);

    DB::table('settings')->insert([
        ['key' => 'comp.fortune.exclude_rank_6', 'value' => 'false', 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'comp.fortune.exclude_rank_5', 'value' => 'true', 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Fresh instance so the scalar cache reflects the new rows.
    app()->forgetInstance(CompensationPlanSettingsService::class);
    expect(app(CompensationPlanSettingsService::class)->fortuneIneligibleRanks())
        ->toBe([5, 7, 8, 9]);
});

// ── Deduction helpers (basis-point math) ────────────────────────────────────

it('computes admin charge with the configured rate and cap', function () {
    $plan = app(CompensationPlanSettingsService::class);

    // 3% of 1,000,000 = 30,000 (below the ₹30,000 cap).
    expect($plan->adminCharge(1_000_000))->toBe(30_000);
    // 3% of 200,000,000 = 6,000,000 → capped at 3,000,000.
    expect($plan->adminCharge(200_000_000))->toBe(3_000_000);
});

it('computes TDS as a basis-point share of the supplied base', function () {
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->tds(174_600))->toBe(8_730); // 5% of 174,600
});

// ── Tabular lookups ─────────────────────────────────────────────────────────

it('exposes the seeded GSB slab ladder', function () {
    seedCompensationPlanTables();
    $plan = app(CompensationPlanSettingsService::class);

    $slab1 = $plan->gsbSlab(1);
    expect($slab1['matched_bv_paise'])->toBe(1_500_000);
    expect($slab1['bonus_paise'])->toBe(180_000);
    expect($slab1['carry_forward_lifetime'])->toBeTrue();

    // Slab 7 (Global Distributor) is a fully payable slab: score 167 → ₹60,120.
    expect($plan->gsbSlab(7)['bonus_paise'])->toBe(6_012_000);

    expect($plan->rankPoolPct(1))->toBe(7.0);
    expect($plan->rankName(9))->toBe('Elite Diamond Partner');
    expect($plan->fortuneLevelBonusPaise(0))->toBe(339);
    expect($plan->fortuneTier('rank_3')['slabs_required'])->toBe(8);
});

// ── Engine reads config, not a constant ─────────────────────────────────────

it('GSB credit reflects an edited slab bonus, proving config is read not hardcoded', function () {
    seedCompensationPlanTables();

    // Halve slab 1's score so bonus becomes 3 × ₹360 = ₹1,080 (108,000 paise).
    DB::table('gsb_slabs')->where('slab', 1)->update(['score' => 3, 'bonus_paise' => 108_000]);

    $dist = Distributor::factory()->create();
    BvLedgerEntry::create([
        'distributor_id' => $dist->id, 'order_id' => 999_999,
        'bv_paise' => 300_000, 'type' => 'accrual', 'effective_at' => now(),
    ]);
    GroupBvDaily::create([
        'distributor_id' => $dist->id, 'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000,
    ]);

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($result->slab)->toBe(1);
    expect($result->gross_gsb_paise)->toBe(108_000); // the edited bonus, not the old 100,000 or default 180,000
});
