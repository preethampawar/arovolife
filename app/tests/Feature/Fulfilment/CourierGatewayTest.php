<?php

declare(strict_types=1);

/**
 * Slice 3: the courier contract and the driver that has always existed in
 * practice — an operator, a parcel, and a docket.
 *
 * The behaviour worth pinning here is the fallback. Payments must never fall
 * back (a stub marks money received that was not), so it would be easy to copy
 * that rule across and refuse to dispatch when an integration is down. That
 * would strand real parcels for no safety gain, and these tests say so.
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Fulfilment\Data\Consignee;
use App\Modules\Fulfilment\Data\DispatchInstruction;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use App\Modules\Fulfilment\Services\CourierGatewayResolver;
use App\Modules\Fulfilment\Services\ManualCourier;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $overrides */
function anOrder(array $overrides = []): Order
{
    $n = random_int(100000, 999999);

    return Order::create(array_merge([
        'order_no' => "AO-{$n}",
        'customer_id' => Customer::create(['display_name' => "Buyer {$n}"])->id,
        'status' => Order::STATUS_PAID,
        'payment_method' => Order::PAYMENT_ONLINE,
        'subtotal_paise' => 100000,
        'total_paise' => 100000,
        'placed_at' => now(),
        'idempotency_key' => "test:courier:{$n}",
        'ship_name' => 'A Buyer',
        'ship_phone_e164' => '+919999999999',
        'ship_line1' => '12 Road',
        'ship_city' => 'Hyderabad',
        'ship_state' => 'TELANGANA',
        'ship_pincode' => '500001',
    ], $overrides));
}

function aShipment(Order $order): Shipment
{
    return Shipment::create([
        'order_id' => $order->id,
        'warehouse_code' => 'DEFAULT',
        'carrier_code' => 'MANUAL',
        'status' => Shipment::STATUS_PICKED,
    ]);
}

function aCentre(): AreteCenter
{
    $n = random_int(100000, 999999);

    return AreteCenter::create([
        'name' => "Centre {$n}",
        'type' => AreteCenter::TYPE_COMPANY,
        'status' => AreteCenter::STATUS_ACTIVE,
        'address_line_1' => '5 Market Street',
        'city' => 'Vijayawada',
        'state' => 'ANDHRA PRADESH',
        'pincode' => '520001',
        'contact_number' => '+918888888888',
        'is_company_default' => true,
    ]);
}

function freshSettings(): FulfilmentSettings
{
    // FulfilmentSettings caches its rows for the life of the instance, and the
    // container holds it as a singleton. A test that writes a setting must
    // drop the instance or it reads the value from before the write.
    app()->forgetInstance(FulfilmentSettings::class);

    return app(FulfilmentSettings::class);
}

it('always permits a manual dispatch', function () {
    expect(app(ManualCourier::class)->permitted())->toBeTrue()
        ->and(app(ManualCourier::class)->name())->toBe(Shipment::GATEWAY_MANUAL);
});

it('records what the operator wrote on the docket', function () {
    $shipment = aShipment(anOrder());

    $result = app(ManualCourier::class)->dispatch(
        $shipment,
        new DispatchInstruction(
            consignee: Consignee::forHomeDelivery($shipment->order),
            carrierName: 'Delhivery',
            awbNo: '1234567890',
        ),
        'shipment:'.$shipment->id,
    );

    expect($result->gateway)->toBe(Shipment::GATEWAY_MANUAL)
        ->and($result->status)->toBe(Shipment::STATUS_DISPATCHED)
        ->and($result->carrierCode)->toBe('Delhivery')
        ->and($result->awbNo)->toBe('1234567890')
        // No remote booking happened, so there is no remote id to hold.
        ->and($result->gatewayShipmentId)->toBeNull()
        ->and($result->labelUrl)->toBeNull();

    $event = ShipmentEvent::where('shipment_id', $shipment->id)->sole();
    expect($event->event_type)->toBe('manual.dispatch')
        ->and($event->direction)->toBe(ShipmentEvent::DIRECTION_SYSTEM)
        ->and($event->payload['carrier'])->toBe('Delhivery');
});

it('never lets a carrier name outgrow its column', function () {
    $shipment = aShipment(anOrder());

    $result = app(ManualCourier::class)->dispatch(
        $shipment,
        new DispatchInstruction(
            consignee: Consignee::forHomeDelivery($shipment->order),
            carrierName: str_repeat('X', 80),
            awbNo: str_repeat('9', 120),
        ),
        'shipment:'.$shipment->id,
    );

    // Truncated in the driver, so what is reported back is what will store.
    // markDispatched() used to truncate at write time, and the admin screen
    // then displayed something the operator had never typed.
    expect(mb_strlen((string) $result->carrierCode))->toBe(32)
        ->and(mb_strlen((string) $result->awbNo))->toBe(64);

    $shipment->update(['carrier_code' => $result->carrierCode, 'awb_no' => $result->awbNo]);
    expect($shipment->refresh()->carrier_code)->toBe($result->carrierCode);
});

it('refuses a dispatch that names no carrier', function () {
    $shipment = aShipment(anOrder());

    app(ManualCourier::class)->dispatch(
        $shipment,
        new DispatchInstruction(consignee: Consignee::forHomeDelivery($shipment->order)),
        'shipment:'.$shipment->id,
    );
})->throws(RuntimeException::class, 'needs the carrier name');

it('reports no tracking for a manual dispatch', function () {
    $shipment = aShipment(anOrder());

    expect(app(ManualCourier::class)->track($shipment))->toBeNull();
});

it('consigns a collection to the centre, carrying the buyer only as the collector', function () {
    $centre = aCentre();
    $order = anOrder([
        'delivery_type' => Order::DELIVERY_COLLECT,
        'arete_center_id' => $centre->id,
        // A collection order has no shipping address of its own — that is the
        // point of R-47's fix. Only the collector's name and phone survive.
        'ship_line1' => null,
        'ship_city' => null,
        'ship_state' => null,
        'ship_pincode' => null,
    ]);

    $consignee = Consignee::forCollectionAt($centre, $order);

    expect($consignee->isCollection())->toBeTrue()
        ->and($consignee->areteCenterId)->toBe($centre->id)
        ->and($consignee->name)->toBe($centre->name)
        ->and($consignee->pincode)->toBe('520001')
        ->and($consignee->collectorName)->toBe('A Buyer');
});

it('refuses to consign a home delivery with no address', function () {
    $order = anOrder(['ship_line1' => null, 'ship_pincode' => null]);

    Consignee::forHomeDelivery($order);
})->throws(RuntimeException::class, 'has no delivery address');

it('refuses to consign to a centre with no pincode', function () {
    $centre = aCentre();
    $centre->update(['pincode' => null]);

    Consignee::forCollectionAt($centre->refresh(), anOrder());
})->throws(RuntimeException::class, 'has no pincode');

it('offers manual only while there is no courier account', function () {
    $resolver = app(CourierGatewayResolver::class);

    expect(array_keys($resolver->available()))->toBe([Shipment::GATEWAY_MANUAL])
        ->and($resolver->supports(Shipment::GATEWAY_MANUAL))->toBeTrue()
        ->and($resolver->supports(Shipment::GATEWAY_SHIPROCKET))->toBeFalse();
});

it('falls back to manual instead of refusing to dispatch', function () {
    $resolver = app(CourierGatewayResolver::class);

    // Asking for a courier that is not available must not strand the parcel.
    // This is the deliberate divergence from PaymentGatewayResolver, which
    // closes checkout rather than falling back — there, the fallback would
    // mark money received that was not.
    expect($resolver->route(Shipment::GATEWAY_SHIPROCKET)->name())->toBe(Shipment::GATEWAY_MANUAL)
        ->and($resolver->route('nonsense')->name())->toBe(Shipment::GATEWAY_MANUAL)
        ->and($resolver->route(null)->name())->toBe(Shipment::GATEWAY_MANUAL);
});

it('highlights the configured route but still resolves to what works', function () {
    DB::table('settings')->updateOrInsert(
        ['key' => FulfilmentSettings::KEY_DEFAULT_ROUTE],
        ['value' => Shipment::GATEWAY_SHIPROCKET, 'version' => 1, 'updated_at' => now()],
    );

    expect(freshSettings()->defaultRoute())->toBe(Shipment::GATEWAY_SHIPROCKET);

    app()->forgetInstance(CourierGatewayResolver::class);
    expect(app(CourierGatewayResolver::class)->preferred()->name())->toBe(Shipment::GATEWAY_MANUAL);
});

it('keeps the courier off when the setting is absent', function () {
    $settings = freshSettings();

    expect($settings->shiprocketEnabled())->toBeFalse()
        ->and($settings->defaultRoute())->toBe(Shipment::GATEWAY_MANUAL)
        ->and($settings->shiprocketPickupLocation())->toBe('');
});
