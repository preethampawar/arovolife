<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * A sponsor→sponsee pair where the sponsee's group BV matches GSB slab 1, so a
 * cut-off credits GSB and (when enabled) the sponsor earns a Mentorship Bonus.
 *
 * @return array{0: Distributor, 1: Distributor} [sponsor, sponsee]
 */
function seedGsbCreditingPair(): array
{
    $sponsor = Distributor::factory()->create(['status' => 'active', 'adn' => '100000001']);
    $sponsee = Distributor::factory()->create(['status' => 'active', 'adn' => '100000002']);

    // Sponsee personal BV 3,000 (Retailer) so GSB transfers; sponsor 600 BV for MB eligibility.
    BvLedgerEntry::create(['distributor_id' => $sponsee->id, 'order_id' => 999_001, 'bv_paise' => 300_000, 'type' => 'accrual', 'effective_at' => now()]);
    BvLedgerEntry::create(['distributor_id' => $sponsor->id, 'order_id' => 999_002, 'bv_paise' => 60_000, 'type' => 'accrual', 'effective_at' => now()]);

    // Weaker side 1,600,000 paise (16,000 BV) ≥ slab-1 threshold (15,000 BV) → credits slab 1.
    GroupBvDaily::create(['distributor_id' => $sponsee->id, 'date' => today()->toDateString(), 'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000]);

    DB::table('sponsorship')->insert(['sponsor_id' => $sponsor->id, 'distributor_id' => $sponsee->id, 'created_at' => now()]);

    return [$sponsor, $sponsee];
}

it('no-ops when the Genos Sales Bonus feature is off (default)', function (): void {
    seedGsbCreditingPair();

    $code = Artisan::call('gsb:daily-cutoff');

    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Genos Sales Bonus is disabled');
    expect(GsbCutoffResult::count())->toBe(0);
    expect(MentorshipBonusResult::count())->toBe(0);
});

it('runs GSB but skips the Mentorship Bonus when only the GSB feature is on', function (): void {
    [, $sponsee] = seedGsbCreditingPair();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    // MentorshipBonusFeature stays off (default).

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    expect(GsbCutoffResult::where('distributor_id', $sponsee->id)->where('status', GsbCutoffResult::STATUS_CREDITED)->exists())->toBeTrue();
    expect(MentorshipBonusResult::count())->toBe(0); // MB skipped by its flag
});

it('runs both GSB and the Mentorship Bonus when both features are on', function (): void {
    [$sponsor, $sponsee] = seedGsbCreditingPair();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    expect(GsbCutoffResult::where('distributor_id', $sponsee->id)->where('status', GsbCutoffResult::STATUS_CREDITED)->exists())->toBeTrue();
    expect(MentorshipBonusResult::where('sponsor_id', $sponsor->id)->exists())->toBeTrue();
});

it('freezes one MSB pool for the day and prices every sponsor from it', function (): void {
    // Two sponsors, each with a sponsee matching slab 1 → 21 points apiece.
    [$sponsorA] = seedGsbCreditingPair();
    $sponsorB = Distributor::factory()->create(['status' => 'active', 'adn' => '100000003']);
    $sponseeB = Distributor::factory()->create(['status' => 'active', 'adn' => '100000004']);
    BvLedgerEntry::create(['distributor_id' => $sponseeB->id, 'order_id' => 999_003, 'bv_paise' => 300_000, 'type' => 'accrual', 'effective_at' => now()]);
    BvLedgerEntry::create(['distributor_id' => $sponsorB->id, 'order_id' => 999_004, 'bv_paise' => 60_000, 'type' => 'accrual', 'effective_at' => now()]);
    GroupBvDaily::create(['distributor_id' => $sponseeB->id, 'date' => today()->toDateString(), 'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000]);
    DB::table('sponsorship')->insert(['sponsor_id' => $sponsorB->id, 'distributor_id' => $sponseeB->id, 'created_at' => now()]);

    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    $pool = MsbDailyPool::whereDate('cutoff_date', today()->toDateString())->first();
    expect($pool)->not->toBeNull();
    expect($pool->total_points)->toBe(42);   // 21 + 21

    // The day's company BV is the seeded personal BV; both sponsors are priced
    // from the one frozen value, and the payout never exceeds the pool.
    $rows = MentorshipBonusResult::whereIn('sponsor_id', [$sponsorA->id, $sponsorB->id])->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->msb_points)->toBe(21);
        expect($row->msb_point_value_paise)->toBe($pool->point_value_paise);
        expect($row->mb_gross_paise)->toBe(21 * $pool->point_value_paise);
    }
    expect((int) $rows->sum('mb_gross_paise'))->toBe($pool->payout_paise);
    expect($pool->payout_paise)->toBeLessThanOrEqual($pool->pool_paise);
});

it('does not re-price or double-pay MSB when the day is re-run', function (): void {
    [$sponsor] = seedGsbCreditingPair();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);
    $first = MentorshipBonusResult::where('sponsor_id', $sponsor->id)->firstOrFail();

    // A second full run over the same day — cut-offs are already settled.
    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    expect(MsbDailyPool::count())->toBe(1);
    expect(MentorshipBonusResult::where('sponsor_id', $sponsor->id)->count())->toBe(1);
    $again = MentorshipBonusResult::findOrFail($first->id);
    expect($again->mb_gross_paise)->toBe($first->mb_gross_paise);
    expect($again->msb_point_value_paise)->toBe($first->msb_point_value_paise);
});

// ── Daily pool pricing for slabs 3–7 (KP 2026-07-29) ────────────────────────

/**
 * A distributor whose personal BV (accrued yesterday, so it stays out of the
 * cut-off day's company BV) unlocks the given slab's title, with today's group
 * BV matched on both sides at the slab's threshold.
 */
function seedSlabAchiever(int $slab): Distributor
{
    static $seq = 0;
    $titleMinBySlab = [1 => 300_000, 2 => 700_000, 3 => 1_500_000, 4 => 3_200_000, 5 => 6_800_000, 6 => 14_400_000, 7 => 30_000_000];
    $thresholdBySlab = [1 => 1_500_000, 2 => 3_600_000, 3 => 10_000_000, 4 => 30_000_000, 5 => 90_000_000, 6 => 270_000_000, 7 => 810_000_000];

    $d = Distributor::factory()->create(['status' => 'active', 'adn' => '2000'.str_pad((string) ++$seq, 5, '0', STR_PAD_LEFT)]);
    BvLedgerEntry::create([
        'distributor_id' => $d->id, 'order_id' => 900_000 + $seq,
        'bv_paise' => $titleMinBySlab[$slab], 'type' => 'accrual', 'effective_at' => now()->subDay(),
    ]);
    GroupBvDaily::create([
        'distributor_id' => $d->id, 'date' => today()->toDateString(),
        'left_bv_paise' => $thresholdBySlab[$slab], 'right_bv_paise' => $thresholdBySlab[$slab],
    ]);

    return $d;
}

/** One accrual on the cut-off day carrying the whole company turnover BV. */
function seedCompanyDayBv(int $bvPaise): void
{
    $dummy = Distributor::factory()->create(['status' => 'active', 'adn' => '999999999']);
    BvLedgerEntry::create([
        'distributor_id' => $dummy->id, 'order_id' => 899_999,
        'bv_paise' => $bvPaise, 'type' => 'accrual', 'effective_at' => now(),
    ]);
}

it('reproduces the KP 2026-07-29 worked example: 10L BV day prices slabs 3–7 at ₹220/score', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(GsbDailyPoolPricingFeature::class);

    seedCompanyDayBv(100_000_000); // 10,00,000 BV

    $bySlab = [];
    foreach ([1 => 14, 2 => 11, 3 => 8, 4 => 6, 5 => 4, 6 => 2, 7 => 1] as $slab => $count) {
        foreach (range(1, $count) as $i) {
            $bySlab[$slab][] = seedSlabAchiever($slab);
        }
    }

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    $pool = GsbDailyPool::firstOrFail();
    expect($pool->company_bv_paise)->toBe(100_000_000);
    expect($pool->pool_rate_bp)->toBe(4500);
    expect($pool->pool_paise)->toBe(45_000_000);                 // ₹4,50,000
    expect($pool->fixed_payout_paise)->toBe(7_200_000);          // ₹72,000 (14×2,000 + 11×4,000)
    expect($pool->variable_total_score)->toBe(1712);             // 256+360+448+368+280
    expect($pool->variable_score_value_cap_paise)->toBe(25_000);
    expect($pool->variable_score_value_paise)->toBe(22_000);     // 220.79 → ₹220 (floored)
    expect($pool->variable_payout_paise)->toBe(37_664_000);      // ₹3,76,640
    expect($pool->leftover_paise)->toBe(136_000);                // ₹1,360

    // Fixed slab 1 pays in full at the fixed value; variable slab 3 at ₹220.
    $slab1Row = GsbCutoffResult::where('distributor_id', $bySlab[1][0]->id)->firstOrFail();
    expect($slab1Row->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($slab1Row->gross_gsb_paise)->toBe(200_000);
    expect($slab1Row->score_value_paise)->toBe(25_000);

    $slab3Row = GsbCutoffResult::where('distributor_id', $bySlab[3][0]->id)->firstOrFail();
    expect($slab3Row->slab)->toBe(3);
    expect($slab3Row->gross_gsb_paise)->toBe(32 * 22_000);       // ₹7,040, not the fixed ₹8,000
    expect($slab3Row->score_value_paise)->toBe(22_000);

    $slab7Row = GsbCutoffResult::where('distributor_id', $bySlab[7][0]->id)->firstOrFail();
    expect($slab7Row->gross_gsb_paise)->toBe(280 * 22_000);      // ₹61,600

    // Grand total actually credited to wallets = fixed + variable payouts.
    expect((int) DB::table('wallet_ledger_entries')->where('type', 'gsb_credit')->sum('amount_paise'))
        ->toBe(44_864_000);                                      // ₹4,48,640

    // Re-run of the same date is idempotent: pool economics frozen, no double credit.
    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);
    expect(GsbDailyPool::count())->toBe(1);
    expect(GsbDailyPool::firstOrFail()->variable_score_value_paise)->toBe(22_000);
    expect((int) DB::table('wallet_ledger_entries')->where('type', 'gsb_credit')->sum('amount_paise'))
        ->toBe(44_864_000);
});

it('writes no pool row and pays legacy fixed bonuses when the pool flag is off', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);

    seedCompanyDayBv(100_000_000);
    $slab3 = seedSlabAchiever(3);

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    expect(GsbDailyPool::count())->toBe(0);
    $row = GsbCutoffResult::where('distributor_id', $slab3->id)->firstOrFail();
    expect($row->gross_gsb_paise)->toBe(800_000); // fixed ₹8,000 (gsb_slabs.bonus_paise)
    expect($row->score_value_paise)->toBe(25_000);
});

it('prices slabs 3–7 at ₹0 on a starved day while slabs 1–2 still pay in full', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(GsbDailyPoolPricingFeature::class);

    // No company BV on the cut-off day → pool ₹0.
    $slab1 = seedSlabAchiever(1);
    $slab3 = seedSlabAchiever(3);

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    $pool = GsbDailyPool::firstOrFail();
    expect($pool->pool_paise)->toBe(0);
    expect($pool->variable_score_value_paise)->toBe(0);
    expect($pool->leftover_paise)->toBe(-200_000); // fixed slab 1 paid beyond the pool (KP-approved overshoot)

    expect(GsbCutoffResult::where('distributor_id', $slab1->id)->value('gross_gsb_paise'))->toBe(200_000);

    $slab3Row = GsbCutoffResult::where('distributor_id', $slab3->id)->firstOrFail();
    expect($slab3Row->status)->toBe(GsbCutoffResult::STATUS_CREDITED); // weaker leg consumed as usual
    expect($slab3Row->gross_gsb_paise)->toBe(0);
    expect(DB::table('wallet_ledger_entries')->where('distributor_id', $slab3->id)->count())->toBe(0);
});

it('freezes the cap as the day value when no slab 3–7 achiever exists', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(GsbDailyPoolPricingFeature::class);

    seedCompanyDayBv(10_000_000);
    seedSlabAchiever(1);

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    // A later admin retry landing on slab 3–7 prices at the full ₹250 (pool had room).
    expect(GsbDailyPool::firstOrFail()->variable_score_value_paise)->toBe(25_000);
});

it('reuses the frozen pool value on a single-distributor retry and never recomputes it', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(GsbDailyPoolPricingFeature::class);

    GsbDailyPool::create([
        'cutoff_date' => today()->toDateString(),
        'company_bv_paise' => 50_000_000, 'pool_rate_bp' => 4500, 'pool_paise' => 22_500_000,
        'fixed_payout_paise' => 0, 'variable_total_score' => 100,
        'variable_score_value_cap_paise' => 25_000, 'variable_score_value_paise' => 20_000,
        'variable_payout_paise' => 2_000_000, 'leftover_paise' => 20_500_000,
    ]);

    $slab3 = seedSlabAchiever(3);

    expect(Artisan::call('gsb:daily-cutoff', ['--distributor' => $slab3->id]))->toBe(0);

    expect(GsbDailyPool::count())->toBe(1); // single runs never freeze a new pool
    $row = GsbCutoffResult::where('distributor_id', $slab3->id)->firstOrFail();
    expect($row->gross_gsb_paise)->toBe(32 * 20_000); // frozen ₹200/score, not recomputed
    expect($row->score_value_paise)->toBe(20_000);
});

it('falls back to the fixed bonus on a single run for a date with no pool row', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(GsbDailyPoolPricingFeature::class);

    $slab3 = seedSlabAchiever(3);

    expect(Artisan::call('gsb:daily-cutoff', ['--distributor' => $slab3->id]))->toBe(0);

    expect(GsbDailyPool::count())->toBe(0);
    expect(GsbCutoffResult::where('distributor_id', $slab3->id)->value('gross_gsb_paise'))->toBe(800_000);
});
