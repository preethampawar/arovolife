<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\PayoutBatchStuckProcessingProvider;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function stuckProvider(): PayoutBatchStuckProcessingProvider
{
    return app(PayoutBatchStuckProcessingProvider::class);
}

/** A `processing` batch whose last write was $hours ago. */
function stuckBatch(float $hours): PayoutBatch
{
    $batch = Helpers::payoutBatch(PayoutBatch::STATUS_PROCESSING);
    $batch->forceFill(['updated_at' => now()->subMinutes((int) round($hours * 60))])->saveQuietly();

    return $batch->fresh();
}

it('flags a batch left in processing for longer than any sweep could run', function (): void {
    $batch = stuckBatch(5);

    expect(stuckProvider()->count())->toBe(1)
        ->and(stuckProvider()->items()->first()->subjectId)->toBe($batch->id);
});

it('leaves a sweep that may still be running alone', function (): void {
    stuckBatch(1);

    expect(stuckProvider()->count())->toBe(0);
});

it('leaves a long-started sweep alone while it is still writing line items', function (): void {
    // The batch row is written twice per sweep — at the start and at the end —
    // so its own `updated_at` says when the sweep STARTED. A sweep hand-started
    // five hours ago is still alive if it is still laying down line items, and
    // the tile must not send an operator to reopen a live batch.
    $batch = stuckBatch(5);

    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => 1,
        'gross_paise' => 50_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'wallet_balance_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_transferred_paise' => 0,
        'status' => PayoutLineItem::STATUS_PENDING,
        'retry_count' => 0,
    ]);

    expect(stuckProvider()->count())->toBe(0);
});

it('flags it once the line items have gone quiet too', function (): void {
    $batch = stuckBatch(5);

    $line = PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => 1,
        'gross_paise' => 50_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'wallet_balance_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_transferred_paise' => 0,
        'status' => PayoutLineItem::STATUS_PENDING,
        'retry_count' => 0,
    ]);
    $line->forceFill(['created_at' => now()->subHours(4)])->saveQuietly();

    expect(stuckProvider()->count())->toBe(1)
        ->and(stuckProvider()->items()->first()->subjectId)->toBe($batch->id);
});

it('ignores every status a finished sweep writes', function (): void {
    foreach ([
        PayoutBatch::STATUS_PENDING,
        PayoutBatch::STATUS_PARTIALLY_FAILED,
        PayoutBatch::STATUS_FAILED,
        PayoutBatch::STATUS_COMPLETED,
        PayoutBatch::STATUS_APPROVED,
    ] as $status) {
        $batch = Helpers::payoutBatch($status);
        $batch->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
    }

    expect(stuckProvider()->count())->toBe(0);
});

it('excludes a snoozed batch', function (): void {
    $batch = stuckBatch(5);

    ActionCenterSnooze::create([
        'action_key' => 'payouts.batch_stuck_processing',
        'subject_type' => 'payout_batch',
        'subject_id' => $batch->id,
        'snoozed_until' => now()->addDay(),
        'reason' => 'Reopening it tonight.',
    ]);

    expect(stuckProvider()->count())->toBe(0);
});
