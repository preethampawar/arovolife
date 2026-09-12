<?php

declare(strict_types=1);

/**
 * Statutory: past the contractual seven-business-day promise (terms §8). The
 * business-day math is `RefundWorklist::classify()`'s alone; this exercises
 * the boundary across a weekend so a weekend never tips an item over early.
 */

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\RefundsPastPromiseProvider;
use App\Modules\Payments\Models\RefundIntent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(RefundsPastPromiseProvider::class);
    // A Monday, so the 7-business-day boundary spans a full weekend.
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('is statutory and cannot be snoozed', function (): void {
    expect($this->provider->statutory())->toBeTrue();
});

it('counts a refund just past 7 business days and ignores one just inside, across a weekend', function (): void {
    $overdueSince = now()->copy()->subWeekdays(7);
    $insideSince = now()->copy()->subWeekdays(6);

    $overdue = Helpers::refundIntent(Helpers::order()->id, [
        'status' => RefundIntent::STATUS_CREATED,
        'released_at' => $overdueSince,
        'created_at' => $overdueSince,
    ]);
    Helpers::refundIntent(Helpers::order()->id, [
        'status' => RefundIntent::STATUS_CREATED,
        'released_at' => $insideSince,
        'created_at' => $insideSince,
    ]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('ignores a held refund even if old', function (): void {
    Helpers::refundIntent(Helpers::order()->id, [
        'status' => RefundIntent::STATUS_CREATED,
        'held_at' => now()->copy()->subDays(30),
    ]);

    expect($this->provider->count())->toBe(0);
});

it('ignores a failed refund', function (): void {
    Helpers::refundIntent(Helpers::order()->id, [
        'status' => RefundIntent::STATUS_FAILED,
        'failed_at' => now()->copy()->subDays(30),
    ]);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed refund on principle, though the UI offers no snooze control', function (): void {
    $overdueSince = now()->copy()->subWeekdays(7);
    $refund = Helpers::refundIntent(Helpers::order()->id, [
        'status' => RefundIntent::STATUS_CREATED,
        'released_at' => $overdueSince,
        'created_at' => $overdueSince,
    ]);

    ActionCenterSnooze::create([
        'action_key' => 'refunds.past_promise',
        'subject_type' => 'refund_intent',
        'subject_id' => $refund->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Gateway confirmed in flight.',
    ]);

    expect($this->provider->count())->toBe(0);
});
