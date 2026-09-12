<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\People\LineChangePendingProvider;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(LineChangePendingProvider::class);
});

function lineChangeRequest(string $status): LineChangeRequest
{
    return LineChangeRequest::create([
        'distributor_id' => Distributor::factory()->create()->id,
        'from_placement_parent_id' => 1,
        'to_placement_parent_id' => 2,
        'requested_at' => now(),
        'status' => $status,
    ]);
}

it('counts a pending line-change request', function (): void {
    $pending = lineChangeRequest('pending');

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($pending->id);
});

it('ignores an approved or rejected request', function (): void {
    lineChangeRequest('approved');
    lineChangeRequest('rejected');

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed request', function (): void {
    $request = lineChangeRequest('pending');

    ActionCenterSnooze::create([
        'action_key' => 'line_change.pending',
        'subject_type' => 'line_change_request',
        'subject_id' => $request->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Awaiting sponsor confirmation.',
    ]);

    expect($this->provider->count())->toBe(0);
});
