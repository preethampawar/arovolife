<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\GsbReversalAwaitingApprovalProvider;
use App\Modules\Compensation\Models\GsbReversalRequest;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(GsbReversalAwaitingApprovalProvider::class);
});

/** @param  array<string, mixed>  $overrides */
function pendingReversal(string $status = GsbReversalRequest::STATUS_PENDING, array $overrides = []): GsbReversalRequest
{
    return GsbReversalRequest::create(array_merge([
        'gsb_cutoff_result_id' => random_int(1, 1_000_000),
        'distributor_id' => Distributor::factory()->create()->id,
        'cutoff_date' => '2026-08-14',
        'net_gsb_paise' => 4_500_000,
        'repurchase_deduction_paise' => 500_000,
        'status' => $status,
        'reason' => 'Slab was matched against a reversed order.',
    ], $overrides));
}

it('requires the checker permission, not the one that raises the request', function (): void {
    expect($this->provider->permission())->toBe('compensation.reversal.approve');
});

it('counts a pending reversal and ignores a decided one', function (): void {
    $pending = pendingReversal();
    pendingReversal(GsbReversalRequest::STATUS_APPROVED);
    pendingReversal(GsbReversalRequest::STATUS_REJECTED);

    expect($this->provider->count())->toBe(1)
        ->and($this->provider->items()->first()->subjectId)->toBe($pending->id);
});

it('names the distributor and the cut-off date so the approver knows what they are signing', function (): void {
    $request = pendingReversal();
    $item = $this->provider->items()->first();

    expect($item->title)->toContain($request->distributor->adn)
        ->and($item->title)->toContain('2026-08-14')
        ->and($item->meta['net_gsb_paise'])->toBe(4_500_000);
});

it('excludes a snoozed request', function (): void {
    $request = pendingReversal();

    ActionCenterSnooze::create([
        'action_key' => 'compensation.gsb_reversal_awaiting_approval',
        'subject_type' => 'gsb_reversal_request',
        'subject_id' => $request->id,
        'snoozed_until' => now()->addDay(),
        'reason' => 'Waiting on the finance lead to come back from leave.',
    ]);

    expect($this->provider->count())->toBe(0);
});
