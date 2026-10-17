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
    expect($plan->adminChargeWeeklyCapPaise())->toBe(2_500_000);  // ₹25,000 (KP Round-5)
    expect($plan->adminChargeMonthlyCapPaise())->toBe(2_500_000); // ₹25,000 (KP Round-5)
    expect($plan->minPayoutPaise())->toBe(10_000);          // ₹100 (KP)
    expect($plan->gsbPoolRateBp())->toBe(4500);             // 45% daily GSB pool (KP 2026-07-29)
});

it('reads an overridden GSB pool rate from the database', function () {
    DB::table('settings')->insert([
        'key' => 'comp.gsb.pool_rate_bp', 'value' => '4000', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(CompensationPlanSettingsService::class)->gsbPoolRateBp())->toBe(4000); // 40%
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

// ── Growth Booster (client 2026-10-09) ──────────────────────────────────────

it('defaults the Growth Booster pool to 4% and the point value cap to ₹240', function () {
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->gbbPoolRateBp())->toBe(400);
    expect($plan->gbbPointValueCapPaise())->toBe(24_000);
    expect(method_exists($plan, 'gbbAgpCap'))->toBeFalse();   // per-distributor AGP cap retired
});

it('refuses a Growth Booster point value cap below ₹1 or not a whole rupee instead of clamping it (F-6)', function (string $value) {
    DB::table('settings')->insert([
        'key' => 'comp.gbb.point_value_cap_paise', 'value' => $value, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => app(CompensationPlanSettingsService::class)->gbbPointValueCapPaise())
        ->toThrow(RuntimeException::class, 'comp.gbb.point_value_cap_paise must be');
})->with(['zero' => '0', 'ninety-nine' => '99', 'not a whole rupee' => '24050']);

it('accepts ₹1 and the neutralise value as Growth Booster point value caps', function (string $value, int $expected) {
    DB::table('settings')->insert([
        'key' => 'comp.gbb.point_value_cap_paise', 'value' => $value, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(CompensationPlanSettingsService::class)->gbbPointValueCapPaise())->toBe($expected);
})->with(['₹1' => ['100', 100], 'neutralise' => ['100000000', 100_000_000]]);

// ── Rank Bonus (client 2026-10-05 Rank Income Point System) ─────────────────

it('exposes RAP points for every rank per the 05-10-2026 Rank Income Point System', function (): void {
    $plan = app(CompensationPlanSettingsService::class);
    expect(array_map(fn (int $r) => $plan->rankRapPoints($r), range(1, 9)))
        ->toBe([72, 189, 468, 1125, 2583, 5688, 11934, 23877, 39501]);
    expect($plan->aogoPointsPerGrant())->toBe(36)
        ->and($plan->rankPointValueCapPaise())->toBe(20_000)
        ->and($plan->rankFirstPassMaxRank())->toBe(3)
        ->and(method_exists($plan, 'rankPoolPct'))->toBeFalse();
});

it('refuses a Rank point value cap below ₹1 or not a whole rupee instead of clamping it (F-6)', function (string $value, string $message) {
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.rank.point_value_cap_paise'],
        ['value' => $value, 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
    );

    expect(fn () => app(CompensationPlanSettingsService::class)->rankPointValueCapPaise())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'below ₹1' => ['50', 'comp.rank.point_value_cap_paise must be at least 100 paise (₹1); refusing to price the Rank Bonus with a cap of 50'],
    'not a whole rupee' => ['20050', 'comp.rank.point_value_cap_paise must be a whole rupee (a multiple of 100 paise); refusing to price the Rank Bonus with a cap of 20050'],
]);

it('accepts a whole-rupee Rank point value cap', function (string $value, int $expected) {
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.rank.point_value_cap_paise'],
        ['value' => $value, 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
    );

    expect(app(CompensationPlanSettingsService::class)->rankPointValueCapPaise())->toBe($expected);
})->with(['₹200' => ['20000', 20_000], '₹1' => ['100', 100], 'neutralise' => ['100000000', 100_000_000]]);

it('refuses a pass-1 rank ceiling outside 1–9 instead of clamping it (F-6)', function (string $value) {
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.rank.first_pass_max_rank'],
        ['value' => $value, 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
    );

    expect(fn () => app(CompensationPlanSettingsService::class)->rankFirstPassMaxRank())
        ->toThrow(RuntimeException::class, 'comp.rank.first_pass_max_rank must be between 1 and 9; refusing to price the Rank Bonus with '.$value);
})->with(['zero' => '0', 'ten' => '10']);

it('returns a pass-1 rank ceiling inside 1–9 as stored', function (string $value, int $expected) {
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.rank.first_pass_max_rank'],
        ['value' => $value, 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
    );

    expect(app(CompensationPlanSettingsService::class)->rankFirstPassMaxRank())->toBe($expected);
})->with(['one' => ['1', 1], 'three' => ['3', 3], 'nine' => ['9', 9]]);

// ── Deduction helpers (basis-point math) ────────────────────────────────────

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
    expect($slab1['bonus_paise'])->toBe(200_000);         // KP 2026-07-21: score 8 × ₹250
    expect($slab1['score_value_paise'])->toBe(25_000);
    expect($slab1['carry_forward_lifetime'])->toBeTrue();
    // Mentorship points engine: slab 1 → 21 points. The points carry no
    // configured rupee value since KP 2026-07-30 — they are priced daily from
    // the MSB pool (MsbDailyPoolService).
    expect($slab1['msb_score'])->toBe(21);
    expect($slab1)->not->toHaveKey('msb_score_value_paise');

    // Slab 7 (Global Distributor) is a fully payable slab: score 280 × ₹250 → ₹70,000.
    expect($plan->gsbSlab(7)['bonus_paise'])->toBe(7_000_000);
    expect($plan->gsbSlab(7)['msb_score'])->toBe(3);

    expect($plan->rankRapPoints(1))->toBe(72);
    expect($plan->rankName(9))->toBe('Elite Diamond Partner');
    expect($plan->fortunePointsForDepth(1))->toBe(9); // KP 2026-08-09: 9/8/7/6/5/4/3/2/1
    expect($plan->fortunePointsForDepth(9))->toBe(1);
    expect($plan->fortunePointsForDepth(0))->toBe(0); // you earn nothing from yourself
    expect($plan->fortunePointsForDepth(10))->toBe(0); // the matrix is 9 levels deep
    expect($plan->fortunePoolRateBp())->toBe(500); // 5% of the month's company BV
    expect($plan->fortuneTier('rank_3')['slabs_required'])->toBe(14); // KP 2026-08-07: 8/11/14/17/20
});

it('builds the slab tooltip from the live ladder, not from written-out numbers', function () {
    seedCompensationPlanTables();
    $plan = app(CompensationPlanSettingsService::class);

    // KP 2026-07-21 "New Engine" thresholds. The admin daily cut-off screens
    // quoted the retired 30K/90K/2.7L/8L/24L/72L ladder until this became derived.
    expect($plan->gsbSlabThresholdSummary())
        ->toBe('Slab 1=15K, 2=36K, 3=1L, 4=3L, 5=9L, 6=27L, 7=81L BV matched on the weaker side.');

    // An admin plan edit moves the tooltip with it; a deactivated slab drops out.
    // A fresh instance because the ladder is cached for the life of a request.
    DB::table('gsb_slabs')->where('slab', 2)->update(['matched_bv_paise' => 4_000_000]);
    DB::table('gsb_slabs')->where('slab', 7)->update(['is_active' => false]);

    expect((new CompensationPlanSettingsService)->gsbSlabThresholdSummary())
        ->toBe('Slab 1=15K, 2=40K, 3=1L, 4=3L, 5=9L, 6=27L BV matched on the weaker side.');
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
