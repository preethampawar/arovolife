<?php

declare(strict_types=1);

/**
 * Slice 5: the admin order page dispatches through DispatchService, with a
 * route picker, the parcel-detail alert, the half-finished-booking checkbox,
 * and a dispatch queue (plan AD-8).
 */

use App\Modules\Commerce\Models\Order;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\OrderFulfilmentService;
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

function adStaff(string $role = 'admin-operations'): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

it('refuses a manual dispatch with no carrier name and leaves the order alone', function () {
    $order = srPaidOrder();

    actingAs(adStaff())
        ->post(route('admin.commerce.orders.ship', $order), ['route' => 'manual'])
        ->assertSessionHasErrors('ship_carrier');

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

it('dispatches manually through the service, recording the courier leg', function () {
    $order = srPaidOrder();

    actingAs(adStaff())
        ->post(route('admin.commerce.orders.ship', $order), ['ship_carrier' => 'DTDC', 'ship_tracking_no' => 'D123'])
        ->assertRedirect(route('admin.commerce.orders.show', $order))
        ->assertSessionHas('status', "Order {$order->order_no} shipped via DTDC, AWB D123.");

    $order->refresh();
    $shipment = Shipment::where('order_id', $order->id)->sole();
    expect($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and($order->ship_carrier)->toBe('DTDC')
        ->and($shipment->gateway)->toBe(Shipment::GATEWAY_MANUAL)
        ->and($shipment->consigned_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'order.dispatched')->where('subject_id', $order->id)->exists())->toBeTrue();
});

it('offers the Shiprocket route only while Shiprocket is switched on', function () {
    $order = srPaidOrder();
    $staff = adStaff();

    actingAs($staff)->get(route('admin.commerce.orders.show', $order))
        ->assertOk()->assertSee('value="shiprocket"', false);

    Feature::for(null)->deactivate(ShiprocketFulfilmentFeature::class);

    actingAs($staff)->get(route('admin.commerce.orders.show', $order))
        ->assertOk()->assertDontSee('value="shiprocket"', false)->assertDontSee('Shiprocket-ready');
});

it('shows which products lack parcel details and refuses Shiprocket before packing', function () {
    srFake();
    $variant = srVariant(['weight_g' => 0]);
    $order = srPaidOrder($variant);
    $staff = adStaff();

    actingAs($staff)->get(route('admin.commerce.orders.show', $order))
        ->assertOk()
        ->assertSee('Shiprocket needs a weight and packed size')
        ->assertSee(route('admin.catalog.products.edit', $variant->product_id), false);

    actingAs($staff)
        ->post(route('admin.commerce.orders.ship', $order), ['route' => 'shiprocket'])
        ->assertSessionHasErrors('ship');

    Http::assertNothingSent();
    $order->refresh();
    // Refused before packing: no stock was committed for a courier that was never going to take it.
    expect($order->status)->toBe(Order::STATUS_PAID)
        ->and($order->packed_at)->toBeNull();
});

it('books through Shiprocket from the order page and shows the label', function () {
    srFake();
    $order = srPaidOrder();
    $staff = adStaff();

    actingAs($staff)
        ->post(route('admin.commerce.orders.ship', $order), ['route' => 'shiprocket', 'ship_carrier' => 'ignored'])
        ->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_SHIPPED)
        ->and($order->ship_tracking_no)->toBe('AWB778899')
        ->and($order->ship_carrier)->toBe('Delhivery Surface');

    actingAs($staff)->get(route('admin.commerce.orders.show', $order))
        ->assertSee('https://labels.example.test/7001.pdf', false);
});

it('will not hand-dispatch a Shiprocket-booked parcel until the operator ticks the cancelled box', function () {
    $order = srPaidOrder();
    app(OrderFulfilmentService::class)->pack($order, null, null);
    Shipment::where('order_id', $order->id)->update(['gateway' => Shipment::GATEWAY_SHIPROCKET, 'gateway_shipment_id' => '7001']);
    $staff = adStaff();

    actingAs($staff)->get(route('admin.commerce.orders.show', $order->fresh()))
        ->assertSee('already booked with Shiprocket')
        ->assertSee('name="confirm_remote_cancelled"', false);

    actingAs($staff)
        ->post(route('admin.commerce.orders.ship', $order), ['route' => 'manual', 'ship_carrier' => 'DTDC'])
        ->assertSessionHasErrors('ship');
    expect($order->fresh()->status)->not->toBe(Order::STATUS_SHIPPED);

    actingAs($staff)
        ->post(route('admin.commerce.orders.ship', $order), ['route' => 'manual', 'ship_carrier' => 'DTDC', 'confirm_remote_cancelled' => '1'])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
    $audit = AuditLog::where('action', 'order.dispatched')->where('subject_id', $order->id)->sole();
    expect($audit->details['remote_booking_cancelled_by_operator']['gateway_shipment_id'])->toBe('7001');
});

it('lists waiting orders for operations and flags the ones Shiprocket cannot take', function () {
    $ready = srPaidOrder();
    $gappy = srPaidOrder(srVariant(['height_mm' => null]));

    actingAs(adStaff())->get(route('admin.fulfilment.dispatch'))
        ->assertOk()
        ->assertSee($ready->order_no)
        ->assertSee($gappy->order_no)
        ->assertSee('1 product missing size or weight');
});

it('keeps the dispatch queue from staff who cannot dispatch', function () {
    actingAs(adStaff('admin-finance'))->get(route('admin.fulfilment.dispatch'))->assertForbidden();
    actingAs(adStaff('admin-finance'))->post(route('admin.commerce.orders.ship', srPaidOrder()), ['ship_carrier' => 'DTDC'])->assertForbidden();
});
