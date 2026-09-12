<?php

declare(strict_types=1);

/**
 * The netting query (plan §9, A2): a cancelled order whose picked stock was
 * never reversed must show up; once the reversal exists, it must not.
 */

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Orders\RestockNotReconciledProvider;
use App\Modules\Commerce\Models\Order;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(RestockNotReconciledProvider::class);
});

it('appears when a cancelled order sold stock with no reversal', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()->subHour()]);
    $itemId = Helpers::orderItem($order->id);
    Helpers::stockMovement(StockMovement::TYPE_SALE_OUT, $itemId, -2);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($order->id);
});

it('does not appear once the sale is fully reversed', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()->subHour()]);
    $itemId = Helpers::orderItem($order->id);
    Helpers::stockMovement(StockMovement::TYPE_SALE_OUT, $itemId, -2);
    Helpers::stockMovement(StockMovement::TYPE_SALE_REVERSAL, $itemId, 2);

    expect($this->provider->count())->toBe(0);
});

it('ignores orders that are not cancelled or refunded', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_SHIPPED]);
    $itemId = Helpers::orderItem($order->id);
    Helpers::stockMovement(StockMovement::TYPE_SALE_OUT, $itemId, -2);

    expect($this->provider->count())->toBe(0);
});

it('counts a refunded order the same way as a cancelled one', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_REFUNDED, 'refunded_at' => now()->subHour()]);
    $itemId = Helpers::orderItem($order->id);
    Helpers::stockMovement(StockMovement::TYPE_SALE_OUT, $itemId, -3);

    expect($this->provider->count())->toBe(1);
});

it('excludes a snoozed order', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()->subHour()]);
    $itemId = Helpers::orderItem($order->id);
    Helpers::stockMovement(StockMovement::TYPE_SALE_OUT, $itemId, -2);

    ActionCenterSnooze::create([
        'action_key' => 'orders.restock_not_reconciled',
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Stock adjustment already logged, awaiting posting.',
    ]);

    expect($this->provider->count())->toBe(0);
});
