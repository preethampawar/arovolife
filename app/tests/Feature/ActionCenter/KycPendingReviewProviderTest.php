<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\People\KycPendingReviewProvider;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(KycPendingReviewProvider::class);
});

it('counts a pending distributor just outside the KYC review SLA and ignores one just inside', function (): void {
    $overdue = Distributor::factory()->create(['created_at' => now()->subHours(49)]);
    $overdue->user()->update(['status' => 'pending']);

    $inside = Distributor::factory()->create(['created_at' => now()->subHours(47)]);
    $inside->user()->update(['status' => 'pending']);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('ignores a secondary half of a couple registration', function (): void {
    $distributor = Distributor::factory()->create([
        'created_at' => now()->subHours(72),
        'spouse_distributor_id' => 999,
        'is_primary_couple' => false,
    ]);
    $distributor->user()->update(['status' => 'pending']);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed distributor', function (): void {
    $distributor = Distributor::factory()->create(['created_at' => now()->subHours(72)]);
    $distributor->user()->update(['status' => 'pending']);

    ActionCenterSnooze::create([
        'action_key' => 'kyc.pending_review',
        'subject_type' => 'distributor',
        'subject_id' => $distributor->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Applicant asked for extra time to gather documents.',
    ]);

    expect($this->provider->count())->toBe(0);
});
