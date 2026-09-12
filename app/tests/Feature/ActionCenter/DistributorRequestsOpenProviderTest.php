<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\People\DistributorRequestsOpenProvider;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\DistributorRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(DistributorRequestsOpenProvider::class);
});

/** @param array<string, mixed> $overrides */
function distributorRequest(string $status, array $overrides = []): DistributorRequest
{
    $n = random_int(100000, 999999);

    return DistributorRequest::create(array_merge([
        'request_no' => "DR-AC-{$n}",
        'distributor_id' => Distributor::factory()->create()->id,
        'type' => DistributorRequest::TYPE_NAME_CORRECTION,
        'status' => $status,
        'details' => [],
        'reason' => 'Spelling mismatch with PAN.',
        'submitted_at' => now(),
    ], $overrides));
}

it('counts submitted and under-review requests', function (): void {
    $submitted = distributorRequest(DistributorRequest::STATUS_SUBMITTED);
    distributorRequest(DistributorRequest::STATUS_UNDER_REVIEW);

    expect($this->provider->count())->toBe(2);
    expect($this->provider->items()->pluck('subjectId'))->toContain($submitted->id);
});

it('ignores an approved or rejected request', function (): void {
    distributorRequest(DistributorRequest::STATUS_APPROVED);
    distributorRequest(DistributorRequest::STATUS_REJECTED);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed request', function (): void {
    $request = distributorRequest(DistributorRequest::STATUS_SUBMITTED);

    ActionCenterSnooze::create([
        'action_key' => 'distributor_requests.open',
        'subject_type' => 'distributor_request',
        'subject_id' => $request->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Waiting on a document from the applicant.',
    ]);

    expect($this->provider->count())->toBe(0);
});
