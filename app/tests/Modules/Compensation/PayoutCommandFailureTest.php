<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

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

    $this->artisan('payout:monthly-run', ['--month' => Carbon::today()->format('Y-m')])
        ->assertExitCode(1);

    $batch = PayoutBatch::where('batch_type', PayoutBatch::TYPE_MONTHLY)->first();

    expect($batch)->not->toBeNull();
    expect($batch->status)->toBe(PayoutBatch::STATUS_FAILED);
});
