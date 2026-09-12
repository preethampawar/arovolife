<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\RefundsManualOwedProvider;
use App\Modules\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(RefundsManualOwedProvider::class);
});

it('counts a refund-approved order with no gateway intent and ignores one with an intent', function (): void {
    $owed = Helpers::order([
        'status' => Order::STATUS_REFUND_APPROVED,
        'refund_approved_at' => now()->subDays(3),
    ]);
    $withIntent = Helpers::order([
        'status' => Order::STATUS_REFUND_APPROVED,
        'refund_approved_at' => now()->subDays(3),
    ]);
    Helpers::refundIntent($withIntent->id);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($owed->id);
});

it('ignores an order in another status', function (): void {
    Helpers::order(['status' => Order::STATUS_PAID]);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed order', function (): void {
    $order = Helpers::order([
        'status' => Order::STATUS_REFUND_APPROVED,
        'refund_approved_at' => now()->subDays(3),
    ]);

    ActionCenterSnooze::create([
        'action_key' => 'refunds.manual_owed',
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'NEFT scheduled for tomorrow.',
    ]);

    expect($this->provider->count())->toBe(0);
});
