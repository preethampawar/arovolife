<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\People\CoolingOffExpiringProvider;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(CoolingOffExpiringProvider::class);
});

it('counts an active distributor whose cooling-off ends within 7 days and ignores one further out', function (): void {
    $expiring = Distributor::factory()->create(['cooling_off_end_at' => now()->addDays(6)]);
    $expiring->user()->update(['status' => 'active']);

    $notYet = Distributor::factory()->create(['cooling_off_end_at' => now()->addDays(8)]);
    $notYet->user()->update(['status' => 'active']);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($expiring->id);
});

it('ignores a non-active distributor even if the window is expiring', function (): void {
    $distributor = Distributor::factory()->create(['cooling_off_end_at' => now()->addDays(3)]);
    $distributor->user()->update(['status' => 'terminated']);

    expect($this->provider->count())->toBe(0);
});

it('ignores a distributor whose cooling-off has already ended', function (): void {
    $distributor = Distributor::factory()->create(['cooling_off_end_at' => now()->subDay()]);
    $distributor->user()->update(['status' => 'active']);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed distributor', function (): void {
    $distributor = Distributor::factory()->create(['cooling_off_end_at' => now()->addDays(2)]);
    $distributor->user()->update(['status' => 'active']);

    ActionCenterSnooze::create([
        'action_key' => 'distributors.cooling_off_expiring',
        'subject_type' => 'distributor',
        'subject_id' => $distributor->id,
        'snoozed_until' => now()->addDay(),
        'reason' => 'Confirmed the distributor does not intend to cancel.',
    ]);

    expect($this->provider->count())->toBe(0);
});
