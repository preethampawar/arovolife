<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\PayoutBatchPartiallyFailedProvider;
use App\Modules\Compensation\Models\PayoutBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(PayoutBatchPartiallyFailedProvider::class);
});

it('counts a partially failed batch and ignores a completed one', function (): void {
    $failed = Helpers::payoutBatch(PayoutBatch::STATUS_PARTIALLY_FAILED);
    Helpers::payoutBatch(PayoutBatch::STATUS_COMPLETED);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($failed->id);
});

it('excludes a snoozed batch', function (): void {
    $batch = Helpers::payoutBatch(PayoutBatch::STATUS_PARTIALLY_FAILED);

    ActionCenterSnooze::create([
        'action_key' => 'payouts.batch_partially_failed',
        'subject_type' => 'payout_batch',
        'subject_id' => $batch->id,
        'snoozed_until' => now()->addDays(1),
        'reason' => 'Retry scheduled tonight.',
    ]);

    expect($this->provider->count())->toBe(0);
});
