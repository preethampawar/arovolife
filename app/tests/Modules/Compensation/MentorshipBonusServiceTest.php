<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
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

/** A credited GSB cut-off for $sponsee matching $slab. */
function makeCreditedCutoff(Distributor $sponsee, int $slab, int $grossGsbPaise = 100_000, string $status = 'credited'): GsbCutoffResult
{
    return GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => $slab, 'gross_gsb_paise' => $grossGsbPaise,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => $grossGsbPaise,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => $status,
    ]);
}

/**
 * Freeze today's MSB pool at a chosen point value. The single-distributor path
 * never freezes a pool itself, so every credit test needs the day's economics
 * to exist first — exactly as the nightly run leaves them.
 */
function freezeMsbPoolAt(int $pointValuePaise, int $totalPoints = 60): MsbDailyPool
{
    $payout = $pointValuePaise * $totalPoints;

    return MsbDailyPool::create([
        'cutoff_date' => today()->toDateString(),
        'company_bv_paise' => $payout === 0 ? 0 : intdiv($payout * 10_000, 300),
        'pool_rate_bp' => 300,
        'pool_paise' => $payout,
        'total_points' => $totalPoints,
        'point_value_paise' => $pointValuePaise,
        'payout_paise' => $payout,
        'leftover_paise' => 0,
    ]);
}

it('credits the sponsor with slab points × the day\'s pooled point value (slab 1 → 21 × ₹250 = ₹5,250)', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);
    freezeMsbPoolAt(25_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->not->toBeNull();
    expect($mb->slab)->toBe(1);
    expect($mb->msb_points)->toBe(21);
    expect($mb->msb_point_value_paise)->toBe(25_000);
    expect($mb->mb_gross_paise)->toBe(525_000);   // 21 × 25,000 paise = ₹5,250
    // Ladder fields retired — points engine writes null.
    expect($mb->mb_rate_pct)->toBeNull();
    expect($mb->sponsee_cumulative_gsb_paise)->toBeNull();
    // Deductions are deferred to payout time.
    expect($mb->mb_admin_charge_paise)->toBe(0);
    expect($mb->mb_tds_paise)->toBe(0);
    expect($mb->status)->toBe('credited');

    // Sponsor wallet credited with gross.
    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(525_000);
});

it('pays each slab its own points at one shared value (slab 3 → 15 pts; slab 7 → 3 pts, both @ ₹250)', function () {
    $sponsor = Distributor::factory()->create();
    $sponseeA = Distributor::factory()->create();
    $sponseeB = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponseeA);
    makeSponsorship($sponsor, $sponseeB);
    giveSponsorMinBv($sponsor);
    freezeMsbPoolAt(25_000);

    $svc = app(MentorshipBonusService::class);
    $mbA = $svc->processForSponsee($sponseeA->id, makeCreditedCutoff($sponseeA, 3));
    $mbB = $svc->processForSponsee($sponseeB->id, makeCreditedCutoff($sponseeB, 7));

    expect($mbA->msb_points)->toBe(15);
    expect($mbA->mb_gross_paise)->toBe(375_000);
    expect($mbB->msb_points)->toBe(3);
    expect($mbB->mb_gross_paise)->toBe(75_000);
    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(450_000);
});

it('prices every credit at the day\'s frozen pool value, whatever the slab', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    // A busier day resolves to ₹100/point.
    freezeMsbPoolAt(10_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 2));

    expect($mb->msb_points)->toBe(18);
    expect($mb->msb_point_value_paise)->toBe(10_000);
    expect($mb->mb_gross_paise)->toBe(180_000);   // 18 × ₹100 = ₹1,800
});

it('returns null without crediting when the day has no frozen pool', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    // No freezeMsbPoolAt() — e.g. a date whose nightly ran before MSB was on.
    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});

it('writes a ₹0 row with no wallet entry when the day priced at zero', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    // A day nobody accrued on: the pool went unspent and froze at ₹0.
    freezeMsbPoolAt(0, 0);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->not->toBeNull();
    expect($mb->msb_points)->toBe(21);
    expect($mb->msb_point_value_paise)->toBe(0);
    expect($mb->mb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});

it('keeps historical rows unchanged when admin later edits the slab points or the pool rate', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);
    freezeMsbPoolAt(25_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));
    expect($mb->mb_gross_paise)->toBe(525_000);

    DB::table('gsb_slabs')->where('slab', 1)->update(['msb_score' => 99]);
    DB::table('settings')->updateOrInsert(['key' => 'comp.msb.pool_rate_bp'], ['value' => '1']);

    $fresh = MentorshipBonusResult::findOrFail($mb->id);
    expect($fresh->msb_points)->toBe(21);
    expect($fresh->msb_point_value_paise)->toBe(25_000);
    expect($fresh->mb_gross_paise)->toBe(525_000);
});

it('returns null when the slab carries zero MSB points', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    DB::table('gsb_slabs')->where('slab', 1)->update(['msb_score' => 0]);
    freezeMsbPoolAt(25_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});

it('returns null for a non-credited cut-off', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1, 100_000, 'repurchase_held'));

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
});

it('is idempotent — calling twice for the same cutoff does not double-credit', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    freezeMsbPoolAt(25_000);
    $cutoffResult = makeCreditedCutoff($sponsee, 1);

    $svc = app(MentorshipBonusService::class);
    $svc->processForSponsee($sponsee->id, $cutoffResult);
    $second = $svc->processForSponsee($sponsee->id, $cutoffResult);  // second call — returns the existing row

    expect($second)->not->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(1);
    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(525_000);
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

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});

it('never mutates the sponsee GSB row or the sponsor personal BV ledger', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    freezeMsbPoolAt(25_000);
    $bvBefore = (int) BvLedgerEntry::where('distributor_id', $sponsor->id)->sum('bv_paise');
    $cutoffResult = makeCreditedCutoff($sponsee, 1);

    app(MentorshipBonusService::class)->processForSponsee($sponsee->id, $cutoffResult);

    expect((int) BvLedgerEntry::where('distributor_id', $sponsor->id)->sum('bv_paise'))->toBe($bvBefore);
    expect((int) $cutoffResult->fresh()->gross_gsb_paise)->toBe(100_000);
});
