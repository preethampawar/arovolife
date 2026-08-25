<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
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
