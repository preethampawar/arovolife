<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\PayoutBatchAwaitingApprovalProvider;
use App\Modules\Compensation\Models\PayoutBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(PayoutBatchAwaitingApprovalProvider::class);
});

it('requires finance.approve, the checker permission, not finance.record', function (): void {
    expect($this->provider->permission())->toBe('finance.approve');
});

it('counts a pending batch and ignores an approved one', function (): void {
    $pending = Helpers::payoutBatch(PayoutBatch::STATUS_PENDING);
    Helpers::payoutBatch(PayoutBatch::STATUS_APPROVED);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($pending->id);
});

it('links a monthly batch to the monthly payouts screen', function (): void {
    $batch = Helpers::payoutBatch(PayoutBatch::STATUS_PENDING, ['batch_type' => PayoutBatch::TYPE_MONTHLY]);

    expect($this->provider->items()->first()->url)->toContain('monthly-payouts')
        ->and($this->provider->items()->first()->url)->toContain((string) $batch->id);
});

it('excludes a snoozed batch', function (): void {
    $batch = Helpers::payoutBatch(PayoutBatch::STATUS_PENDING);

    ActionCenterSnooze::create([
        'action_key' => 'payouts.batch_awaiting_approval',
        'subject_type' => 'payout_batch',
        'subject_id' => $batch->id,
        'snoozed_until' => now()->addDays(1),
        'reason' => 'Second approver reviewing this afternoon.',
    ]);

    expect($this->provider->count())->toBe(0);
});
