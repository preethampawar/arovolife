<?php

declare(strict_types=1);

/**
 * Choosing the Shiprocket courier: the list staff see (rates, delivery days,
 * services) and the booking made with the one they picked.
 */

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\AreteCenterDeclaration;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use App\Modules\Fulfilment\Services\DispatchService;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\ShiprocketFulfilmentFeature;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

function ccStaff(string $role = 'admin-operations'): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

/**
 * The happy-path Shiprocket fakes plus a pickup address and three couriers:
 * India Post (cheapest), DTDC, and Delhivery (recommended, dearest).
 *
 * @param  array<string, mixed>  $overrides
 */
function ccFake(array $overrides = []): void
{
    srFake(array_merge([
        SR_SANDBOX.'/settings/company/pickup' => Http::response(['data' => ['shipping_address' => [
            ['pickup_location' => 'Other-Hub', 'pin_code' => '110001'],
            ['pickup_location' => 'Arovolife-Hyd-Hub', 'pin_code' => '502032'],
        ]]]),
        SR_SANDBOX.'/courier/serviceability/*' => Http::response(['status' => 200, 'data' => [
            'recommended_courier_company_id' => 10,
            'available_courier_companies' => [
                ['courier_company_id' => 12, 'courier_name' => 'DTDC Surface', 'rate' => 65.5, 'estimated_delivery_days' => '5', 'etd' => 'Sep 29, 2026', 'rating' => 3.9, 'is_surface' => true, 'realtime_tracking' => 'MIS', 'pod_available' => 'On Request', 'call_before_delivery' => 'Not Available', 'delivery_boy_contact' => 'Not Available'],
                ['courier_company_id' => 10, 'courier_name' => 'Delhivery Surface', 'rate' => 72, 'estimated_delivery_days' => '4', 'etd' => 'Sep 28, 2026', 'rating' => 4.2, 'is_surface' => true, 'realtime_tracking' => 'Real Time', 'pod_available' => 'Instant', 'call_before_delivery' => 'Available', 'delivery_boy_contact' => 'Available'],
                ['courier_company_id' => 33, 'courier_name' => 'India Post', 'rate' => 58, 'estimated_delivery_days' => '7', 'etd' => 'Oct 1, 2026', 'rating' => 3.1, 'is_surface' => false, 'realtime_tracking' => 'Something new', 'pod_available' => 'Not Available', 'call_before_delivery' => 'Not Available'],
                ['courier_name' => 'No id, skipped', 'rate' => 1],
            ],
        ]]),
    ], $overrides));
}

/** @return array<int, array<array-key, mixed>> */
function ccAssignBodies(): array
{
    return Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/courier/assign/awb'))
        ->map(fn (array $pair): array => $pair[0]->data())->values()->all();
}

it('lists the couriers, recommended first then cheapest, with the services each really offers', function () {
    ccFake();
    $order = srPaidOrder();

    $quotes = actingAs(ccStaff())->getJson(route('admin.commerce.orders.courier-quotes', $order))
        ->assertOk()->json('quotes');

    expect(array_column($quotes, 'name'))->toBe(['Delhivery Surface', 'India Post', 'DTDC Surface'])
        ->and($quotes[0]['recommended'])->toBeTrue()
        ->and($quotes[0]['rate'])->toBe('₹72.00')
        ->and($quotes[0]['etd_days'])->toBe(4)
        ->and($quotes[0]['services'])->toBe(['Real-time tracking', 'Instant proof of delivery', 'Calls before delivery', 'Delivery agent contact'])
        ->and($quotes[1]['services'])->toBe([])
        ->and($quotes[1]['mode'])->toBe('Air')
        ->and($quotes[2]['services'])->toBe(['Proof of delivery on request']);

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/courier/serviceability/')
        && $r['pickup_postcode'] === '502032' && $r['delivery_postcode'] === '411001' && (int) $r['cod'] === 0);
});

it('never stores the pincodes of a quote request, or the pickup address', function () {
    ccFake([SR_SANDBOX.'/settings/company/pickup' => Http::response(['data' => ['shipping_address' => [
        ['pickup_location' => 'Arovolife-Hyd-Hub', 'pin_code' => '502032', 'address' => '7 Warehouse Lane', 'phone' => '9000011111'],
    ]]])]);
    $order = srPaidOrder();

    actingAs(ccStaff())->getJson(route('admin.commerce.orders.courier-quotes', $order))->assertOk();

    $quote = (string) json_encode(ShipmentEvent::where('event_type', 'courier.serviceability')->value('payload'));
    $pickup = (string) json_encode(ShipmentEvent::where('event_type', 'settings.pickup')->value('payload'));
    expect($quote)->toContain('DTDC Surface')
        ->and(str_contains($quote, '411001') || str_contains($quote, '502032'))->toBeFalse()
        ->and(str_contains($pickup, 'shipping_address') || str_contains($pickup, '9000011111')
            || str_contains($pickup, '502032') || str_contains($pickup, 'Warehouse Lane'))->toBeFalse();
});

it('refuses to quote for an order that has already shipped', function () {
    ccFake();
    $order = srPaidOrder();
    srDispatch($order);

    actingAs(ccStaff())->getJson(route('admin.commerce.orders.courier-quotes', $order->fresh()))->assertStatus(422);
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/courier/serviceability/'));
});

it('refuses to quote for a centre that has not accepted its declarations', function () {
    ccFake();
    $centre = srCentre();
    AreteCenterDeclaration::where('center_id', $centre->id)->delete();
    $order = srPaidOrder(null, 1, $centre);

    actingAs(ccStaff())->getJson(route('admin.commerce.orders.courier-quotes', $order))->assertStatus(422);

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/courier/serviceability/'));
});

it('quotes a collection order to its centre', function () {
    ccFake();
    $order = srPaidOrder(null, 1, srCentre());

    actingAs(ccStaff())->getJson(route('admin.commerce.orders.courier-quotes', $order))->assertOk();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/courier/serviceability/') && $r['delivery_postcode'] === '506002');
});

it('refuses to quote without parcel details, or for anyone without order management', function () {
    ccFake();
    $unsized = srPaidOrder(srVariant(['weight_g' => 0]));
    actingAs(ccStaff())->getJson(route('admin.commerce.orders.courier-quotes', $unsized))->assertStatus(422);
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/courier/serviceability/'));

    $order = srPaidOrder();
    actingAs(ccStaff('admin-finance'))->getJson(route('admin.commerce.orders.courier-quotes', $order))->assertForbidden();
});

it('says staff can still dispatch when Shiprocket cannot list couriers', function () {
    ccFake([SR_SANDBOX.'/courier/serviceability/*' => Http::response([], 503)]);
    $order = srPaidOrder();

    actingAs(ccStaff())->getJson(route('admin.commerce.orders.courier-quotes', $order))
        ->assertStatus(422)->assertJsonPath('error', fn (string $e): bool => str_contains($e, 'Shiprocket will choose'));
});

it('books with the chosen courier and keeps the rate Shiprocket quoted, not one the form sent', function () {
    ccFake();
    $order = srPaidOrder();

    actingAs(ccStaff())->post(route('admin.commerce.orders.ship', $order), [
        'route' => 'shiprocket', 'courier_id' => 12, 'quoted_rate_paise' => 1,
    ])->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'quoted ₹65.50, 5 days'));

    $shipment = Shipment::where('order_id', $order->id)->first();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED)
        ->and(ccAssignBodies())->toBe([['shipment_id' => 7001, 'courier_id' => 12]])
        ->and($shipment->courier_company_id)->toBe(12)
        ->and($shipment->quoted_rate_paise)->toBe(6550)
        ->and($shipment->quoted_etd_days)->toBe(5)
        ->and(AuditLog::where('action', 'order.dispatched')->where('subject_id', $order->id)->value('details'))
        ->toMatchArray(['courier_id' => 12, 'quoted_rate_paise' => 6550, 'chose_recommended' => false]);
});

it('refuses a courier that no longer serves the route before anything is booked', function () {
    ccFake();
    $order = srPaidOrder();

    actingAs(ccStaff())->post(route('admin.commerce.orders.ship', $order), ['route' => 'shiprocket', 'courier_id' => 999])
        ->assertSessionHasErrors('ship');

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/orders/create/adhoc'));
    expect($order->fresh()->status)->not->toBe(Order::STATUS_SHIPPED);
});

it('lets Shiprocket choose when no courier is picked, as before', function () {
    ccFake();
    $order = srPaidOrder();

    actingAs(ccStaff())->post(route('admin.commerce.orders.ship', $order), ['route' => 'shiprocket'])->assertSessionHasNoErrors();

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/courier/serviceability/'));
    expect(ccAssignBodies())->toBe([['shipment_id' => 7001]])
        ->and(Shipment::where('order_id', $order->id)->value('quoted_rate_paise'))->toBeNull();
});

it('ignores a courier id on a manual dispatch', function () {
    ccFake();
    $order = srPaidOrder();

    actingAs(ccStaff())->post(route('admin.commerce.orders.ship', $order), ['route' => 'manual', 'ship_carrier' => 'DTDC', 'courier_id' => 12])
        ->assertSessionHasNoErrors();

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'shiprocket'));
    expect(Shipment::where('order_id', $order->id)->value('courier_company_id'))->toBeNull();
});

it('applies the choice to a held booking that has no AWB yet, without booking twice', function () {
    ccFake([
        SR_SANDBOX.'/courier/assign/awb' => Http::sequence()
            ->push(['awb_assign_status' => 0, 'response' => ['data' => ['awb_assign_error' => 'AWB not assigned']]])
            ->push(['awb_assign_status' => 1, 'response' => ['data' => ['awb_code' => 'AWB5511', 'courier_name' => 'DTDC Surface']]]),
        SR_SANDBOX.'/shipments/7001' => Http::response(['data' => ['awb' => '', 'courier' => '']]),
    ]);
    $order = srPaidOrder();

    actingAs(ccStaff())->post(route('admin.commerce.orders.ship', $order), ['route' => 'shiprocket'])->assertSessionHasErrors('ship');
    actingAs(ccStaff())->post(route('admin.commerce.orders.ship', $order), ['route' => 'shiprocket', 'courier_id' => 12])->assertSessionHasNoErrors();

    expect(Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/orders/create/adhoc'))->count())->toBe(1)
        ->and(ccAssignBodies())->toBe([['shipment_id' => 7001], ['shipment_id' => 7001, 'courier_id' => 12]])
        ->and(Shipment::where('order_id', $order->id)->value('quoted_rate_paise'))->toBe(6550)
        ->and($order->fresh()->ship_tracking_no)->toBe('AWB5511');
});

it('ignores the choice when the held booking already has its courier', function () {
    ccFake([
        SR_SANDBOX.'/courier/assign/awb' => Http::response(['awb_assign_status' => 0, 'response' => ['data' => ['awb_assign_error' => 'AWB not assigned']]]),
        SR_SANDBOX.'/shipments/7001' => Http::response(['data' => ['awb' => 'AWB4400', 'courier' => 'Delhivery Surface']]),
    ]);
    $order = srPaidOrder();
    actingAs(ccStaff())->post(route('admin.commerce.orders.ship', $order), ['route' => 'shiprocket'])->assertSessionHasErrors('ship');

    actingAs(ccStaff())->post(route('admin.commerce.orders.ship', $order), ['route' => 'shiprocket', 'courier_id' => 12])->assertSessionHasNoErrors();

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/courier/serviceability/'));
    expect(ccAssignBodies())->toHaveCount(1)
        ->and($order->fresh()->ship_tracking_no)->toBe('AWB4400')
        ->and(Shipment::where('order_id', $order->id)->value('courier_company_id'))->toBeNull();
});

it('looks the pickup pincode up again when the pickup nickname changes', function () {
    ccFake();
    $order = srPaidOrder();
    app(DispatchService::class)->courierQuotes($order, 'shiprocket');

    srSetting(FulfilmentSettings::KEY_SHIPROCKET_PICKUP_LOCATION, 'Other-Hub');
    app(DispatchService::class)->courierQuotes($order, 'shiprocket');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/courier/serviceability/') && $r['pickup_postcode'] === '110001');
});

it('shows the courier panel only while Shiprocket can be chosen', function () {
    ccFake();
    $order = srPaidOrder();

    actingAs(ccStaff())->get(route('admin.commerce.orders.show', $order))->assertOk()->assertSee('courier-choice', false);

    Feature::for(null)->deactivate(ShiprocketFulfilmentFeature::class);
    actingAs(ccStaff())->get(route('admin.commerce.orders.show', $order))->assertOk()->assertDontSee('id="courier-choice"', false);
});
