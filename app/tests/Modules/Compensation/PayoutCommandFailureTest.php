<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

/** Every flag the monthly completion gate reads; a flag-off engine is excused. */
function activateCreditingFeatures(): void
{
    foreach ([
        RankBonusFeature::class,
        GrowthBoosterBonusFeature::class,
        FortuneBonusFeature::class,
        AreteDevelopmentCenterBonusFeature::class,
        PurchaseOffersFeature::class,
        RepurchaseEngineFeature::class,
    ] as $feature) {
        Feature::for(null)->activate($feature);
    }
}

/**
 * Make the batch runner throw AFTER the batch row has been flipped to
 * `processing` — the exact shape that used to strand the batch forever,
 * because the idempotency guard skips a `processing` batch on every later run.
 */
function throwOnBatchFinalize(): void
{
    PayoutBatch::updating(function (PayoutBatch $batch): void {
        if (in_array($batch->status, [PayoutBatch::STATUS_PENDING, PayoutBatch::STATUS_PARTIALLY_FAILED], true)) {
            throw new RuntimeException('simulated finalize failure');
        }
    });
}

it('gsb:weekly-payout marks a stuck batch failed and exits non-zero', function () {
    throwOnBatchFinalize();

    $this->artisan('gsb:weekly-payout', ['--date' => '2026-08-25']) // a Tuesday
        ->assertExitCode(1);

    $batch = PayoutBatch::where('batch_type', PayoutBatch::TYPE_WEEKLY)->first();

    expect($batch)->not->toBeNull();
    expect($batch->status)->toBe(PayoutBatch::STATUS_FAILED);
});

it('gsb:weekly-payout refuses a batch date that is not a Tuesday unless forced', function () {
    // A Wednesday-dated batch derives a window that splits a Wed→Tue week: the
    // leftover days would wait for — and be swept by — the next real Tuesday.
    $this->artisan('gsb:weekly-payout', ['--date' => '2026-08-19'])
        ->expectsOutputToContain('is a Wednesday')
        ->assertExitCode(1);

    expect(PayoutBatch::count())->toBe(0);

    $this->artisan('gsb:weekly-payout', ['--date' => '2026-08-19', '--force' => true])
        ->assertExitCode(0);
});

it('gsb:weekly-payout names the earning week it pays, not just the batch date', function () {
    // An operator reading only the batch date cannot tell which week's earnings
    // moved; the batch dated T pays the week that closed on T−7.
    $this->artisan('gsb:weekly-payout', ['--date' => '2026-08-18'])
        ->expectsOutputToContain('batch 2026-08-18 pays earnings 2026-08-05 → 2026-08-11');
});

it('payout:monthly-run marks a stuck batch failed and exits non-zero', function () {
    throwOnBatchFinalize();

    // The two gates are exercised on their own below; this test is about what
    // happens once the batch is running, so it is handed the same overrides the
    // payout close hands it on the 8th.
    $this->artisan('payout:monthly-run', [
        '--month' => Carbon::today()->format('Y-m'),
        '--in-flight' => true,
        '--force' => true,
    ])->assertExitCode(1);

    $batch = PayoutBatch::where('batch_type', PayoutBatch::TYPE_MONTHLY)->first();

    expect($batch)->not->toBeNull();
    expect($batch->status)->toBe(PayoutBatch::STATUS_FAILED);
});

it('payout:monthly-run refuses a batch month that has not closed', function () {
    // F47: typed bare it used to default to the month in flight and sweep every
    // unswept Group B/C/D credit — the 1st→8th buffer defeated by one command.
    $this->artisan('payout:monthly-run', ['--month' => Carbon::today()->format('Y-m')])
        ->expectsOutputToContain('has not closed yet')
        ->assertExitCode(1);

    expect(PayoutBatch::count())->toBe(0);
});

it('payout:monthly-run refuses while the month it pays has incomplete crediting', function () {
    // The batch month is closed, so only the completion gate can refuse: no
    // crediting engine has a run for the month whose credits it would sweep.
    // (A flag-off engine is excused by the gate, so they are on here.)
    activateCreditingFeatures();
    $this->artisan('payout:monthly-run', ['--month' => '2026-08'])
        ->expectsOutputToContain('crediting is incomplete')
        ->assertExitCode(1);

    expect(PayoutBatch::count())->toBe(0);
});

it('payout:monthly-run defaults to the month that has just ended, never the live one', function () {
    activateCreditingFeatures();

    $this->artisan('payout:monthly-run')
        ->expectsOutputToContain(Carbon::today()->startOfMonth()->subMonthNoOverflow()->format('F Y'))
        ->assertExitCode(1);

    expect(PayoutBatch::count())->toBe(0);
});
