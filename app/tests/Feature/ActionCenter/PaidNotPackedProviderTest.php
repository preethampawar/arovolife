<?php

declare(strict_types=1);

/**
 * The reference provider (plan §4, Orders): paid, not packed, older than the
 * pack SLA. Asserts the SLA boundary in both directions and that a snoozed
 * subject drops out of both `count()` and `items()`.
 */

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Orders\PaidNotPackedProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Commerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(PaidNotPackedProvider::class);
});

it('counts an order just outside the pack SLA and ignores one just inside it', function (): void {
    $sla = app(ActionCenterSettings::class)->packSlaHours();

    $overdue = Helpers::paidOrder(now()->subHours($sla + 1));
    Helpers::paidOrder(now()->subHours($sla)->addMinutes(5));

    expect($this->provider->count())->toBe(1);

    $items = $this->provider->items();
    expect($items)->toHaveCount(1)
        ->and($items->first()->subjectId)->toBe($overdue->id)
        ->and($items->first()->subjectType)->toBe('order')
        ->and($items->first()->title)->toBe($overdue->order_no);
});

it('promotes an item past its due time to the next severity up', function (): void {
    $sla = app(ActionCenterSettings::class)->packSlaHours();

    // Paid 3x the SLA ago: the due time (paid + SLA) is well past.
    Helpers::paidOrder(now()->subHours($sla * 3));

    expect($this->provider->severity())->toBe(Severity::WARNING)
        ->and($this->provider->items()->first()->severity)->toBe(Severity::CRITICAL);
});

it('ignores orders that are packed or in another status', function (): void {
    $sla = app(ActionCenterSettings::class)->packSlaHours();

    Helpers::paidOrder(now()->subHours($sla + 5), now()->subHour());
    Helpers::paidOrder(now()->subHours($sla + 5), null, Order::STATUS_SHIPPED);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed order until the snooze expires', function (): void {
    $sla = app(ActionCenterSettings::class)->packSlaHours();
    $order = Helpers::paidOrder(now()->subHours($sla + 5));

    ActionCenterSnooze::create([
        'action_key' => 'orders.paid_not_packed',
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Supplier delivery arrives Thursday.',
    ]);

    expect($this->provider->count())->toBe(0)
        ->and($this->provider->items())->toHaveCount(0);

    // Nothing sweeps the row; the query simply stops matching it (plan §5).
    $this->travel(3)->days();

    expect($this->provider->count())->toBe(1);
});
