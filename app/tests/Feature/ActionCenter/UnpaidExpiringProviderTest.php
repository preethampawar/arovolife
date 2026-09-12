<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Orders\UnpaidExpiringProvider;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Support\PaymentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(UnpaidExpiringProvider::class);
});

it('counts an unpaid order just outside the expiry window and ignores one just inside it', function (): void {
    $minutes = app(PaymentSettings::class)->unpaidExpiryMinutes();

    $overdue = Helpers::order([
        'status' => Order::STATUS_PLACED,
        'placed_at' => now()->subMinutes($minutes + 1),
        'paid_at' => null,
    ]);
    Helpers::order([
        'status' => Order::STATUS_PLACED,
        'placed_at' => now()->subMinutes($minutes)->addMinutes(1),
        'paid_at' => null,
    ]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('excludes a snoozed order', function (): void {
    $minutes = app(PaymentSettings::class)->unpaidExpiryMinutes();
    $order = Helpers::order([
        'status' => Order::STATUS_PLACED,
        'placed_at' => now()->subMinutes($minutes + 5),
        'paid_at' => null,
    ]);

    ActionCenterSnooze::create([
        'action_key' => 'orders.unpaid_expiring',
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Customer confirmed payment is in flight.',
    ]);

    expect($this->provider->count())->toBe(0);
});
