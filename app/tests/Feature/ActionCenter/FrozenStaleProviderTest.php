<?php

declare(strict_types=1);

/**
 * `distributors.frozen_stale` has no existing precedent worklist (see the
 * provider's docblock): the condition — a frozen account whose most recent
 * `admin.distributor.frozen` audit row is 14 days old or older — is defined
 * here rather than copied.
 */

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\People\FrozenStaleProvider;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(FrozenStaleProvider::class);
});

function freeze(Distributor $distributor, Carbon $at): void
{
    $distributor->user()->update(['status' => 'frozen']);

    $log = AuditLog::create([
        'action' => 'admin.distributor.frozen',
        'subject_type' => 'distributor',
        'subject_id' => $distributor->id,
        'details' => ['reason' => 'Test freeze.'],
    ]);
    $log->forceFill(['created_at' => $at])->saveQuietly();
}

it('counts a frozen distributor just past 14 days and ignores one just inside', function (): void {
    $stale = Distributor::factory()->create();
    freeze($stale, now()->subDays(14)->subHour());

    $fresh = Distributor::factory()->create();
    freeze($fresh, now()->subDays(14)->addHour());

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($stale->id);
});

it('ignores a distributor that was frozen and later unfrozen', function (): void {
    $distributor = Distributor::factory()->create();
    freeze($distributor, now()->subDays(20));
    $distributor->user()->update(['status' => 'active']);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed distributor', function (): void {
    $distributor = Distributor::factory()->create();
    freeze($distributor, now()->subDays(20));

    ActionCenterSnooze::create([
        'action_key' => 'distributors.frozen_stale',
        'subject_type' => 'distributor',
        'subject_id' => $distributor->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Case is with legal, decision expected next week.',
    ]);

    expect($this->provider->count())->toBe(0);
});
