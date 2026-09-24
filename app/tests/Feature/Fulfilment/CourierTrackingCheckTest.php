<?php

declare(strict_types=1);

/**
 * The staff "Check courier status" button: the fallback for a tracking webhook
 * that never arrived. It goes through the same API-verified path as the webhook.
 */

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\ShiprocketFulfilmentFeature;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\seed;

require_once __DIR__.'/../../Support/shiprocket-fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seed(LedgerAccountSeeder::class);
    seed(RolesAndPermissionsSeeder::class);
    Http::preventStrayRequests();
    config([
        'arovolife.fulfilment.shiprocket.email' => 'api@example.test',
        'arovolife.fulfilment.shiprocket.password' => 'not-a-real-password',
        'arovolife.fulfilment.shiprocket.base_url' => SR_SANDBOX,
    ]);
    srSetting(FulfilmentSettings::KEY_SHIPROCKET_ENABLED, 'true');
    srSetting(FulfilmentSettings::KEY_SHIPROCKET_PICKUP_LOCATION, 'Arovolife-Hyd-Hub');
    Feature::for(null)->activate(ShiprocketFulfilmentFeature::class);
});

function ctcStaff(string $role = 'admin-operations'): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

/** A home-delivery order booked through Shiprocket (shipment 7001) and shipped. */
function ctcShipped(): Order
{
    srFake();
    $order = srPaidOrder();
    srDispatch($order);

    return $order->fresh();
}

function ctcApiSays(string $status): void
{
    Http::fake([
        SR_SANDBOX.'/auth/login' => Http::response(['token' => SR_TOKEN]),
        SR_SANDBOX.'/courier/track/shipment/7001' => Http::response([
            'tracking_data' => ['shipment_track' => [['current_status' => $status]]],
        ]),
    ]);
}

it('marks a home delivery delivered when Shiprocket confirms it, and records who asked', function () {
    $order = ctcShipped();
    ctcApiSays('DELIVERED');
    $staff = ctcStaff();

    actingAs($staff)->post(route('admin.commerce.orders.tracking', $order))
        ->assertRedirect(route('admin.commerce.orders.show', $order))
        ->assertSessionHas('status');

    expect($order->fresh()->status)->toBe(Order::STATUS_DELIVERED)
        ->and(OrderCoolingOff::where('order_id', $order->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'order.delivered_by_courier')->where('subject_id', $order->id)->value('actor_id'))->toBe($staff->id)
        ->and(AuditLog::where('action', 'shipment.tracking_checked')->where('subject_id', $order->id)->exists())->toBeTrue();
});

it('opens only one cooling-off when the check is pressed again after delivery', function () {
    $order = ctcShipped();
    ctcApiSays('DELIVERED');
    $staff = ctcStaff();

    actingAs($staff)->post(route('admin.commerce.orders.tracking', $order));
    actingAs($staff)->post(route('admin.commerce.orders.tracking', $order))->assertSessionHasErrors('tracking');

    expect(OrderCoolingOff::where('order_id', $order->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'order.delivered_by_courier')->where('subject_id', $order->id)->count())->toBe(1);
});

it('only records the courier wording while the parcel is in transit', function () {
    $order = ctcShipped();
    ctcApiSays('OUT FOR DELIVERY');

    actingAs(ctcStaff())->post(route('admin.commerce.orders.tracking', $order))->assertSessionHas('status');

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED)
        ->and(Shipment::where('order_id', $order->id)->value('courier_status'))->toBe('OUT FOR DELIVERY');
});

it('flags a parcel the courier is returning', function () {
    $order = ctcShipped();
    ctcApiSays('RTO IN TRANSIT');

    actingAs(ctcStaff())->post(route('admin.commerce.orders.tracking', $order));

    expect(Shipment::where('order_id', $order->id)->value('status'))->toBe(Shipment::STATUS_RETURNED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

it('never delivers a collection order from the courier status', function () {
    srFake();
    $order = srPaidOrder(null, 1, srCentre());
    srDispatch($order);
    ctcApiSays('DELIVERED');

    actingAs(ctcStaff())->post(route('admin.commerce.orders.tracking', $order))->assertSessionHas('status');

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);

    // Arrived at the centre: still only the buyer's code delivers it.
    Order::whereKey($order->id)->update(['status' => Order::STATUS_AWAITING_COLLECTION]);
    actingAs(ctcStaff())->post(route('admin.commerce.orders.tracking', $order))->assertSessionHas('status');

    expect($order->fresh()->status)->toBe(Order::STATUS_AWAITING_COLLECTION);
});

it('changes nothing when Shiprocket cannot be reached', function () {
    $order = ctcShipped();
    Http::fake([
        SR_SANDBOX.'/auth/login' => Http::response(['token' => SR_TOKEN]),
        SR_SANDBOX.'/courier/track/shipment/7001' => Http::response([], 503),
    ]);

    actingAs(ctcStaff())->post(route('admin.commerce.orders.tracking', $order))->assertSessionHasErrors('tracking');

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED)
        ->and(AuditLog::where('action', 'shipment.tracking_checked')->where('subject_id', $order->id)->value('details')['outcome'] ?? null)
        ->toBe('error: shiprocket unreachable');
});

it('refuses a manually dispatched parcel and anyone without order management', function () {
    srFake();
    $manual = srPaidOrder();
    srDispatch($manual, Shipment::GATEWAY_MANUAL, 'DTDC');

    actingAs(ctcStaff())->post(route('admin.commerce.orders.tracking', $manual->fresh()))->assertSessionHasErrors('tracking');

    $order = ctcShipped();
    actingAs(ctcStaff('admin-finance'))->post(route('admin.commerce.orders.tracking', $order))->assertForbidden();
});

it('shows the button only for a Shiprocket parcel in transit', function () {
    $order = ctcShipped();

    actingAs(ctcStaff())->get(route('admin.commerce.orders.show', $order))->assertOk()->assertSee('Check courier status');

    Feature::for(null)->deactivate(ShiprocketFulfilmentFeature::class);
    actingAs(ctcStaff())->get(route('admin.commerce.orders.show', $order))->assertOk()->assertDontSee('Check courier status');
});
