<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\RefundsFailedProvider;
use App\Modules\Payments\Models\RefundIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(RefundsFailedProvider::class);
});

it('counts a failed refund and ignores a forfeited one', function (): void {
    $order = Helpers::order();
    $failed = Helpers::refundIntent($order->id, [
        'status' => RefundIntent::STATUS_FAILED,
        'failed_at' => now()->subDays(2),
    ]);
    Helpers::refundIntent(Helpers::order()->id, [
        'status' => RefundIntent::STATUS_FAILED,
        'failed_at' => now()->subDays(2),
        'error_code' => RefundIntent::ERROR_GOODS_NOT_RETURNED,
    ]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($failed->id);
});

it('ignores a refund that is not failed', function (): void {
    Helpers::refundIntent(Helpers::order()->id, ['status' => RefundIntent::STATUS_CREATED]);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed refund', function (): void {
    $order = Helpers::order();
    $refund = Helpers::refundIntent($order->id, [
        'status' => RefundIntent::STATUS_FAILED,
        'failed_at' => now()->subDays(2),
    ]);

    ActionCenterSnooze::create([
        'action_key' => 'refunds.failed',
        'subject_type' => 'refund_intent',
        'subject_id' => $refund->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Gateway support ticket open.',
    ]);

    expect($this->provider->count())->toBe(0);
});
