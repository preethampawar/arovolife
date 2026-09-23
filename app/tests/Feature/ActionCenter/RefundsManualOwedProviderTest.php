<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\RefundsManualOwedProvider;
use App\Modules\Commerce\Models\Order;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Payments\Support\RefundWorklist;
use Database\Seeders\LedgerAccountSeeder;
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

it('counts a cancelled paid order owed outside the gateway, matching the refunds worklist', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    $cancelled = Helpers::order([
        'status' => Order::STATUS_CANCELLED,
        'paid_at' => now()->subDays(5),
        'cancelled_at' => now()->subDays(4),
    ]);
    app(LedgerPoster::class)->transfer('Commerce', 'order.cancelled', $cancelled->id, 'order.cancelled:'.$cancelled->id, 'liability.customer_prepayment', 'liability.refund_payable', 100000);
    // Cancelled before payment: nothing is owed, so it is not listed.
    Helpers::order(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()->subDay()]);
    $approved = Helpers::order([
        'status' => Order::STATUS_REFUND_APPROVED,
        'refund_approved_at' => now()->subDays(2),
    ]);

    expect($this->provider->count())->toBe(2)
        ->and($this->provider->count())->toBe(app(RefundWorklist::class)->manualRefunds()->count())
        ->and($this->provider->items()->pluck('subjectId')->all())->toBe([$cancelled->id, $approved->id])
        ->and($this->provider->items()->first()->subtitle)->toStartWith('Cancelled after payment');

    // Once finance records the NEFT the cancelled order drops off.
    app(LedgerPoster::class)->transfer('Payments', 'refund.manual_settlement', $cancelled->id, 'refund.manual.order:'.$cancelled->id, 'liability.refund_payable', 'asset.cash.bank.settlement', 100000);

    expect($this->provider->count())->toBe(1);
});
