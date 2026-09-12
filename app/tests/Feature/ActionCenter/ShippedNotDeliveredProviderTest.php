<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Orders\ShippedNotDeliveredProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(ShippedNotDeliveredProvider::class);
});

it('counts an order just outside the delivery chase window and ignores one just inside it', function (): void {
    $slaHours = app(ActionCenterSettings::class)->deliveryChaseDays() * 24;

    $overdue = Helpers::order([
        'status' => Order::STATUS_SHIPPED,
        'shipped_at' => now()->subHours($slaHours + 1),
    ]);
    Helpers::order([
        'status' => Order::STATUS_SHIPPED,
        'shipped_at' => now()->subHours($slaHours)->addMinutes(5),
    ]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('excludes a snoozed order', function (): void {
    $slaHours = app(ActionCenterSettings::class)->deliveryChaseDays() * 24;
    $order = Helpers::order([
        'status' => Order::STATUS_SHIPPED,
        'shipped_at' => now()->subHours($slaHours + 5),
    ]);

    ActionCenterSnooze::create([
        'action_key' => 'orders.shipped_not_delivered',
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Courier confirmed delivery for today.',
    ]);

    expect($this->provider->count())->toBe(0);
});
