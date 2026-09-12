<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Returns\AwaitingInspectionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(AwaitingInspectionProvider::class);
});

it('counts a return just outside the 48h SLA and ignores one just inside it', function (): void {
    $overdue = Helpers::returnRequest(receivedAt: now()->subHours(49));
    Helpers::returnRequest(receivedAt: now()->subHours(47));

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('ignores a return that already has an inspection', function (): void {
    $return = Helpers::returnRequest(receivedAt: now()->subHours(72));
    Helpers::returnInspection($return->id);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed return', function (): void {
    $return = Helpers::returnRequest(receivedAt: now()->subHours(72));

    ActionCenterSnooze::create([
        'action_key' => 'returns.awaiting_inspection',
        'subject_type' => 'return_request',
        'subject_id' => $return->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Inspector visiting the warehouse tomorrow.',
    ]);

    expect($this->provider->count())->toBe(0);
});
