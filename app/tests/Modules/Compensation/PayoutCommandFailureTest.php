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

    $this->artisan('gsb:weekly-payout', ['--date' => Carbon::today()->toDateString()])
        ->assertExitCode(1);

    $batch = PayoutBatch::where('batch_type', PayoutBatch::TYPE_WEEKLY)->first();

    expect($batch)->not->toBeNull();
    expect($batch->status)->toBe(PayoutBatch::STATUS_FAILED);
});

it('payout:monthly-run marks a stuck batch failed and exits non-zero', function () {
    throwOnBatchFinalize();

    $this->artisan('payout:monthly-run', ['--month' => Carbon::today()->format('Y-m')])
        ->assertExitCode(1);

    $batch = PayoutBatch::where('batch_type', PayoutBatch::TYPE_MONTHLY)->first();

    expect($batch)->not->toBeNull();
    expect($batch->status)->toBe(PayoutBatch::STATUS_FAILED);
});
