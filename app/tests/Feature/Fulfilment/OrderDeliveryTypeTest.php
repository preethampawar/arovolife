<?php

declare(strict_types=1);

/**
 * Slice 1 of the fulfilment build: the order records the buyer's delivery
 * choice as a fact of its own, and a collection has its own fee lever.
 *
 * The load-bearing test here is the first one. Before `delivery_type` existed,
 * `arete_center_id IS NOT NULL` was the only trace that a buyer chose to
 * collect — and that FK is `nullOnDelete`, so deleting a centre silently turned
 * a collection order back into a shipping order carrying the centre's postal
 * address in its `ship_*` columns. That is R-47's forged address, arriving by
 * accident rather than by design.
 */

use App\Modules\Admin\Http\Controllers\AdminSettingsController;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Support\OrderStatusBadge;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Shared\Features\ShiprocketFulfilmentFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

// Deliberately NOT disableTestForeignKeys(): the `nullOnDelete` cascade on
// orders.arete_center_id is precisely the behaviour under test here, and with
// foreign keys switched off it never fires.

function collectionOrderAt(AreteCenter $center): Order
{
    $n = random_int(100000, 999999);

    return Order::create([
        'order_no' => "AO-{$n}",
        'customer_id' => Customer::create(['display_name' => "Buyer {$n}"])->id,
        'arete_center_id' => $center->id,
        'delivery_type' => Order::DELIVERY_COLLECT,
        'status' => Order::STATUS_PLACED,
        'payment_method' => Order::PAYMENT_ONLINE,
        'subtotal_paise' => 100000,
        'total_paise' => 100000,
        'placed_at' => now(),
        'idempotency_key' => "test:collect:{$n}",
    ]);
}

function testCentre(): AreteCenter
{
    $n = random_int(100000, 999999);

    return AreteCenter::create([
        'name' => "Test Centre {$n}",
        'type' => AreteCenter::TYPE_COMPANY,
        'status' => AreteCenter::STATUS_ACTIVE,
        'city' => 'Hyderabad',
        'state' => 'TELANGANA',
        'is_company_default' => false,
    ]);
}

it('keeps the buyer\'s collection choice when the centre is deleted', function () {
    $center = testCentre();
    $order = collectionOrderAt($center);

    expect($order->isCollection())->toBeTrue();

    $center->delete();
    $order->refresh();

    // The FK nulls, as it should — the centre genuinely is gone. What must NOT
    // happen is the order quietly becoming a home delivery, because nothing
    // downstream would then know it was never meant to be shipped anywhere.
    expect($order->arete_center_id)->toBeNull()
        ->and($order->delivery_type)->toBe(Order::DELIVERY_COLLECT)
        ->and($order->isCollection())->toBeTrue();
});

it('defaults an order to home delivery', function () {
    $n = random_int(100000, 999999);
    $order = Order::create([
        'order_no' => "AO-{$n}",
        'customer_id' => Customer::create(['display_name' => "Buyer {$n}"])->id,
        'status' => Order::STATUS_PLACED,
        'payment_method' => Order::PAYMENT_ONLINE,
        'subtotal_paise' => 100000,
        'total_paise' => 100000,
        'placed_at' => now(),
        'idempotency_key' => "test:ship:{$n}",
    ]);

    expect($order->refresh()->delivery_type)->toBe(Order::DELIVERY_SHIP)
        ->and($order->isCollection())->toBeFalse()
        ->and($order->collection_fee_paise)->toBe(0);
});

it('registers the collection fee as an admin-owned setting that starts at zero', function () {
    $registry = AdminSettingsController::registry();

    expect($registry)->toHaveKey('commerce.collection_fee_rupees')
        // Admin-owned: the client can price a collection without a deploy.
        ->and(AdminSettingsController::ownerForKey('commerce.collection_fee_rupees'))->toBe('admin')
        // Zero at launch — R-94's fix is that collecting costs nothing, not
        // that it costs the delivery fee.
        ->and($registry['commerce.collection_fee_rupees']['default'] ?? null)->toBe('0')
        ->and($registry['commerce.collection_fee_rupees']['min'] ?? null)->toBe(0);
});

it('keeps the courier integration off and developer-owned', function () {
    expect(Feature::for(null)->active(ShiprocketFulfilmentFeature::class))->toBeFalse();

    $registry = AdminSettingsController::registry();

    foreach (['fulfilment.default_route', 'fulfilment.shiprocket.enabled', 'fulfilment.shiprocket.pickup_location'] as $key) {
        expect($registry)->toHaveKey($key)
            ->and(AdminSettingsController::ownerForKey($key))->toBe('developer')
            // Zero-trace gating: an admin must not see a courier we have no
            // account with, so every key is bound to the flag.
            ->and($registry[$key]['feature'] ?? null)->toBe(ShiprocketFulfilmentFeature::class);
    }
});

it('shows a parcel waiting at a centre as its own status', function () {
    expect(OrderStatusBadge::label(Order::STATUS_AWAITING_COLLECTION))->toBe('Ready to collect')
        ->and(OrderStatusBadge::classes(Order::STATUS_AWAITING_COLLECTION))->not->toBe('bg-gray-100 text-gray-600 border-gray-200')
        // Operations has to be able to list the parcels sitting with a third
        // party, so this one intermediate state earns a filter chip.
        ->and(OrderStatusBadge::FILTERABLE)->toContain(Order::STATUS_AWAITING_COLLECTION);
});

it('accepts the new status on the orders table', function () {
    $center = testCentre();
    $order = collectionOrderAt($center);

    $order->update(['status' => Order::STATUS_AWAITING_COLLECTION]);

    // MySQL enforces enum membership; SQLite would silently tolerate a value
    // outside the CHECK constraint, which is why this runs on arovolife_test.
    expect($order->refresh()->status)->toBe(Order::STATUS_AWAITING_COLLECTION);
});
