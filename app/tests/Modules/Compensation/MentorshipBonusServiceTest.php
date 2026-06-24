<?php

declare(strict_types=1);

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

it('credits sponsor with 10% of sponsee GSB when sponsee cumulative < 30K GSB', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

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
    expect($mb->mb_paise)->toBe(10_000);  // 10% of 100,000
    expect($mb->status)->toBe('credited');

    // Sponsor wallet should have received 10,000 paise
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(10_000);
});

it('steps down MB rate after each 30K cumulative GSB milestone', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

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
    expect($mb->mb_paise)->toBe(8_000);
});

it('floors MB rate at 1%', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

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
    expect($mb->mb_paise)->toBe(1_000);
});
