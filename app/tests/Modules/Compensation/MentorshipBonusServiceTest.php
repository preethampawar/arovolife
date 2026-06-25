<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function makeSponsorship(Distributor $sponsor, Distributor $sponsee): void
{
    DB::table('sponsorship')->insert([
        'sponsor_id' => $sponsor->id,
        'distributor_id' => $sponsee->id,
        'created_at' => now(),
    ]);
}

/** Give a sponsor exactly the minimum 600 BV (60,000 paise) needed for bonus eligibility. */
function giveSponsorMinBv(Distributor $sponsor): void
{
    BvLedgerEntry::create([
        'distributor_id' => $sponsor->id,
        'order_id' => 700_000 + $sponsor->id,
        'bv_paise' => 60_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
}

it('credits sponsor with 10% of sponsee GSB when sponsee cumulative < 30K GSB', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    $cutoffResult = GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 1, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
    ]);

    $svc = app(MentorshipBonusService::class);
    $mb = $svc->processForSponsee($sponsee->id, $cutoffResult);

    expect($mb)->not->toBeNull();
    expect($mb->mb_rate_pct)->toBe(10);
    // MB gross = 10% of 100,000 = 10,000
    expect($mb->mb_gross_paise)->toBe(10_000);
    // Admin charge = 3% of 10,000 = 300
    expect($mb->mb_admin_charge_paise)->toBe(300);
    // TDS = 5% of (10,000 - 300) = 5% of 9,700 = 485
    expect($mb->mb_tds_paise)->toBe(485);
    // Net = 10,000 - 300 - 485 = 9,215
    expect($mb->mb_paise)->toBe(9_215);
    expect($mb->status)->toBe('credited');

    // Sponsor wallet credited with net amount.
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(9_215);
});

it('steps down MB rate after each 30K cumulative GSB milestone', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    // Sponsee has already earned 60K cumulative GSB → rate should be 8% (10 - 2 steps)
    MentorshipBonusResult::create([
        'sponsor_id' => $sponsor->id, 'sponsee_id' => $sponsee->id,
        'cutoff_date' => today()->subDays(2)->toDateString(),
        'sponsee_gsb_paise' => 3_000_000, 'mb_rate_pct' => 10, 'mb_paise' => 300_000,
        'sponsee_cumulative_gsb_paise' => 3_000_000, 'status' => 'credited',
    ]);
    MentorshipBonusResult::create([
        'sponsor_id' => $sponsor->id, 'sponsee_id' => $sponsee->id,
        'cutoff_date' => today()->subDay()->toDateString(),
        'sponsee_gsb_paise' => 3_000_000, 'mb_rate_pct' => 9, 'mb_paise' => 270_000,
        'sponsee_cumulative_gsb_paise' => 6_000_000, 'status' => 'credited',
    ]);

    $cutoffResult = GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 1, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
    ]);

    $svc = app(MentorshipBonusService::class);
    $mb = $svc->processForSponsee($sponsee->id, $cutoffResult);

    expect($mb->mb_rate_pct)->toBe(8);  // 10 - 2 steps (60K = 2 × 30K milestones)
    // MB gross = 8% of 100,000 = 8,000; admin = 240; TDS = round(7,760 × 5%) = 388; net = 7,372
    expect($mb->mb_gross_paise)->toBe(8_000);
    expect($mb->mb_paise)->toBe(7_372);
});

it('floors MB rate at 1%', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    // Sponsee cumulative = 270K GSB (9 × 30K milestones → rate = max(10-9, 1) = 1%)
    MentorshipBonusResult::create([
        'sponsor_id' => $sponsor->id, 'sponsee_id' => $sponsee->id,
        'cutoff_date' => today()->subDay()->toDateString(),
        'sponsee_gsb_paise' => 27_000_000, 'mb_rate_pct' => 1, 'mb_paise' => 270_000,
        'sponsee_cumulative_gsb_paise' => 27_000_000, 'status' => 'credited',
    ]);

    $cutoffResult = GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 1, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
    ]);

    $svc = app(MentorshipBonusService::class);
    $mb = $svc->processForSponsee($sponsee->id, $cutoffResult);

    expect($mb->mb_rate_pct)->toBe(1);
    // MB gross = 1% of 100,000 = 1,000; admin = 30; TDS = round(970 × 5%) = 49; net = 921
    expect($mb->mb_gross_paise)->toBe(1_000);
    expect($mb->mb_paise)->toBe(921);
});

it('is idempotent — calling twice for the same cutoff does not double-credit', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    $cutoffResult = GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 1, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
    ]);

    $svc = app(MentorshipBonusService::class);
    $svc->processForSponsee($sponsee->id, $cutoffResult);
    $svc->processForSponsee($sponsee->id, $cutoffResult);  // second call — should be a no-op

    expect(MentorshipBonusResult::count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(1);
    // Net MB (10% of 100K, after 3% admin + 5% TDS) = 9,215
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(9_215);
});

it('blocks MB credit when sponsor personal BV is below the minimum threshold', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

    // Sponsor has only 599 BV (59,900 paise) — one BV below the 600 BV gate.
    BvLedgerEntry::create([
        'distributor_id' => $sponsor->id,
        'order_id' => 700_000 + $sponsor->id,
        'bv_paise' => 59_900,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    $cutoffResult = GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 1, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
    ]);

    $svc = app(MentorshipBonusService::class);
    $mb = $svc->processForSponsee($sponsee->id, $cutoffResult);

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});
