<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Returns\AwaitingReceiptProvider;
use App\Modules\ActionCenter\Support\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(AwaitingReceiptProvider::class);
});

it('is statutory, critical from the moment the refund is held, and counts every open hold', function (): void {
    $recent = Helpers::returnRequest(entitlementsHeldAt: now()->subDays(2));

    expect($this->provider->statutory())->toBeTrue()
        ->and($this->provider->severity())->toBe(Severity::CRITICAL)
        ->and($this->provider->count())->toBe(1)
        ->and($this->provider->items()->first()->subjectId)->toBe($recent->id);
});

it('marks a hold past the 10-day alert window as due without changing its already-critical severity', function (): void {
    $overdue = Helpers::returnRequest(entitlementsHeldAt: now()->subDays(10)->subHour());
    $within = Helpers::returnRequest(entitlementsHeldAt: now()->subDays(9));

    $items = $this->provider->items()->keyBy('subjectId');

    expect($items[$overdue->id]->isOverdue())->toBeTrue()
        ->and($items[$within->id]->isOverdue())->toBeFalse()
        ->and($items[$overdue->id]->severity)->toBe(Severity::CRITICAL)
        ->and($items[$within->id]->severity)->toBe(Severity::CRITICAL);
});

it('ignores a return already received or resolved', function (): void {
    Helpers::returnRequest(entitlementsHeldAt: now()->subDays(15), receivedAt: now());
    Helpers::returnRequest(entitlementsHeldAt: now()->subDays(15), receiptOutcome: 'courier_lost');

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed return (a snooze row is never written by the UI for a statutory type, but the base rule still applies)', function (): void {
    $return = Helpers::returnRequest(entitlementsHeldAt: now()->subDays(15));

    ActionCenterSnooze::create([
        'action_key' => 'returns.awaiting_receipt',
        'subject_type' => 'return_request',
        'subject_id' => $return->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Testing the exclusion path directly.',
    ]);

    expect($this->provider->count())->toBe(0);
});
