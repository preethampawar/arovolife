<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Orders\PackedNotShippedProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(PackedNotShippedProvider::class);
});

it('counts an order just outside the ship SLA and ignores one just inside it', function (): void {
    $sla = app(ActionCenterSettings::class)->shipSlaHours();

    $overdue = Helpers::order([
        'status' => Order::STATUS_READY_TO_SHIP,
        'packed_at' => now()->subHours($sla + 1),
    ]);
    Helpers::order([
        'status' => Order::STATUS_READY_TO_SHIP,
        'packed_at' => now()->subHours($sla)->addMinutes(5),
    ]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('excludes a snoozed order', function (): void {
    $sla = app(ActionCenterSettings::class)->shipSlaHours();
    $order = Helpers::order([
        'status' => Order::STATUS_READY_TO_SHIP,
        'packed_at' => now()->subHours($sla + 5),
    ]);

    ActionCenterSnooze::create([
        'action_key' => 'orders.packed_not_shipped',
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Carrier pickup booked for tomorrow.',
    ]);

    expect($this->provider->count())->toBe(0);
});
