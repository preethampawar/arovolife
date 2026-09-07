<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\GsbSlabProgressService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.gsb.topup_golive_date'],
        ['value' => '2000-01-01'],
    );
});

/**
 * A distributor whose lifetime personal BV clears the Genos-BV minimum, with
 * that same purchase still pending its weaker-leg top-up.
 */
function slabProgressDistributor(int $personalBvPaise = 100_000): Distributor
{
    $dist = Distributor::factory()->create();

    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => random_int(3_000_000, 3_999_999),
        'bv_paise' => $personalBvPaise,
        'type' => BvLedgerEntry::TYPE_ACCRUAL,
        'effective_at' => Carbon::today('Asia/Kolkata'),
    ]);

    return $dist;
}

it('keeps pending personal purchase BV out of the carry over until the cut-off', function (): void {
    $dist = slabProgressDistributor();
    $minSlab = app(CompensationPlanSettingsService::class)->gsbMinSlabMatchedBvPaise();

    // A leg has touched the smallest slab, so tonight's cut-off will credit the
    // pending personal BV — but it must not be counted before that happens.
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => $minSlab + 500_000,
        'right_bv_paise' => $minSlab,
    ]);

    $progress = app(GsbSlabProgressService::class)->forDistributor($dist->id);

    expect($progress->leftEffectivePaise)->toBe($minSlab + 500_000)
        ->and($progress->rightEffectivePaise)->toBe($minSlab)
        ->and($progress->personalBvTopupPaise)->toBe(0)
        ->and($progress->pendingPersonalBvTopupPaise)->toBe(100_000)
        ->and($progress->pendingTopupSide)->toBe('R');

    // Matched BV — what the ladder measures — is the untouched weaker side.
    $slab1 = $progress->rows[0];
    expect(min($progress->leftEffectivePaise, $progress->rightEffectivePaise))->toBe($minSlab)
        ->and($slab1->rightProgressPaise)->toBe($minSlab);
});

it('counts personal purchase BV only once the cut-off has credited it', function (): void {
    $dist = slabProgressDistributor();
    $minSlab = app(CompensationPlanSettingsService::class)->gsbMinSlabMatchedBvPaise();
    $today = Carbon::today('Asia/Kolkata')->toDateString();

    // Post cut-off state: the top-up is on the ledger and inside the accumulator.
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => $today,
        'left_bv_paise' => $minSlab + 500_000,
        'right_bv_paise' => $minSlab + 100_000,
    ]);
    GsbPersonalBvTopup::create([
        'distributor_id' => $dist->id,
        'order_id' => random_int(4_000_000, 4_999_999),
        'bv_paise' => 100_000,
        'side' => 'R',
        'date' => $today,
        'created_at' => now(),
    ]);

    $progress = app(GsbSlabProgressService::class)->forDistributor($dist->id);

    expect($progress->rightEffectivePaise)->toBe($minSlab + 100_000)
        ->and($progress->personalBvTopupPaise)->toBe(100_000)
        ->and($progress->topupSide)->toBe('R')
        ->and($progress->pendingPersonalBvTopupPaise)->toBe(0)
        ->and($progress->pendingTopupSide)->toBeNull();
});

it('uses the opening carry-forward once today\'s cut-off has already run', function (): void {
    $dist = slabProgressDistributor();
    $today = Carbon::today('Asia/Kolkata')->toDateString();

    // Today's BV, already consumed by an (in-flight recomputed) cut-off whose
    // closing state now sits in the rolling carry-forward store.
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => $today,
        'left_bv_paise' => 61_259_900,
        'right_bv_paise' => 60_360_000,
    ]);
    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 899_900,
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 0,
    ]);
    GsbCutoffResult::create([
        'distributor_id' => $dist->id,
        'cutoff_date' => $today,
        'left_bv_paise' => 61_259_900,
        'right_bv_paise' => 60_360_000,
        'weaker_bv_paise' => 60_360_000,
        'slab' => 2,
        'gross_gsb_paise' => 400_000,
        'net_gsb_paise' => 400_000,
        'power_cf_before_paise' => 0,
        'power_side_before' => null,
        'power_cf_after_paise' => 899_900,
        'power_side_after' => 'L',
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);

    $progress = app(GsbSlabProgressService::class)->forDistributor($dist->id);

    // Effective sides mirror what that cut-off actually used (opening CF was
    // zero) — not daily BV + closing CF, which would double-count 8,999 BV.
    expect($progress->leftEffectivePaise)->toBe(61_259_900)
        ->and($progress->rightEffectivePaise)->toBe(60_360_000)
        // The carry-forward cards still show the rolling store (post-match).
        ->and($progress->powerCfPaise)->toBe(899_900)
        ->and($progress->powerCfSide)->toBe('L');
});

it('adds the rolling carry-forward while today\'s cut-off has not run yet', function (): void {
    $dist = slabProgressDistributor();

    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 100_000,
        'right_bv_paise' => 50_000,
    ]);
    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 899_900,
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 0,
    ]);

    $progress = app(GsbSlabProgressService::class)->forDistributor($dist->id);

    expect($progress->leftEffectivePaise)->toBe(999_900)
        ->and($progress->rightEffectivePaise)->toBe(50_000);
});

it('does not preview a top-up while no leg has touched the first slab', function (): void {
    $dist = slabProgressDistributor();

    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 100_000,
        'right_bv_paise' => 50_000,
    ]);

    $progress = app(GsbSlabProgressService::class)->forDistributor($dist->id);

    expect($progress->leftEffectivePaise)->toBe(100_000)
        ->and($progress->rightEffectivePaise)->toBe(50_000)
        ->and($progress->pendingPersonalBvTopupPaise)->toBe(0)
        ->and($progress->pendingTopupSide)->toBeNull();
});

it('shows only the preserved carry-forward on a forfeited day, and flags that the day did not count', function (): void {
    // Client spec 2026-09-07 §2.1: today's Genos BV was never added and never
    // will be. Showing it as ladder progress would promise a match that cannot
    // happen, so the ladder falls back to the carry-forward the forfeit left in
    // the store — which is what tomorrow will actually build on.
    $dist = slabProgressDistributor();
    $today = Carbon::today('Asia/Kolkata')->toDateString();

    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 800_000, 'power_side' => 'L', 'slab1_weaker_bv_paise' => 500_000,
    ]);
    GroupBvDaily::create([
        'distributor_id' => $dist->id, 'date' => $today,
        'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000,
    ]);
    GsbCutoffResult::create([
        'distributor_id' => $dist->id,
        'cutoff_date' => $today,
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
        'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0,
        'net_gsb_paise' => 0,
        'power_cf_before_paise' => 800_000,
        'power_side_before' => 'L',
        'power_cf_after_paise' => 800_000,
        'power_side_after' => 'L',
        'slab1_weaker_cf_before_paise' => 500_000,
        'slab1_weaker_cf_after_paise' => 500_000,
        'status' => GsbCutoffResult::STATUS_REPURCHASE_FORFEITED,
    ]);

    $progress = app(GsbSlabProgressService::class)->forDistributor($dist->id);

    expect($progress->forfeitedToday)->toBeTrue()
        // Carry-forward only: the 20,000 / 16,000 BV of today's business is gone.
        ->and($progress->leftEffectivePaise)->toBe(800_000)
        ->and($progress->rightEffectivePaise)->toBe(0)
        ->and($progress->slab1WeakerCfPaise)->toBe(500_000)
        // Slab-1 progress = weaker (0) + the slab-1 store, capped by the
        // stronger side — never today's 16,000 BV weaker leg.
        ->and($progress->rows[0]->progressPaise)->toBe(500_000);
});

it('counts the day normally when today has a no_match row rather than a forfeit', function (): void {
    // The guard is specific to the forfeit status: an ordinary cut-off that has
    // already run rebuilds the opening state from its own before-snapshot and
    // still counts the day's BV.
    $dist = slabProgressDistributor();
    $today = Carbon::today('Asia/Kolkata')->toDateString();

    GroupBvDaily::create([
        'distributor_id' => $dist->id, 'date' => $today,
        'left_bv_paise' => 600_000, 'right_bv_paise' => 400_000,
    ]);
    GsbCutoffResult::create([
        'distributor_id' => $dist->id,
        'cutoff_date' => $today,
        'left_bv_paise' => 600_000,
        'right_bv_paise' => 400_000,
        'weaker_bv_paise' => 400_000,
        'gross_gsb_paise' => 0,
        'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0,
        'power_side_before' => null,
        'power_cf_after_paise' => 600_000,
        'power_side_after' => 'L',
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 400_000,
        'status' => GsbCutoffResult::STATUS_NO_MATCH,
    ]);

    $progress = app(GsbSlabProgressService::class)->forDistributor($dist->id);

    expect($progress->forfeitedToday)->toBeFalse()
        ->and($progress->leftEffectivePaise)->toBe(600_000)
        ->and($progress->rightEffectivePaise)->toBe(400_000);
});
