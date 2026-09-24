<?php

declare(strict_types=1);

/**
 * Slice 6: the Shiprocket tracking webhook (plan T10, T11), buyer tracking,
 * and the courier-exception worklist. The webhook body is only a prompt: the
 * job re-reads the status from Shiprocket's API before changing anything.
 */

use App\Modules\ActionCenter\Providers\Orders\CourierExceptionProvider;
use App\Modules\ActionCenter\Providers\Orders\ShippedNotDeliveredProvider;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Notifications\OrderStatusChangedNotification;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\ShiprocketFulfilmentFeature;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

require_once __DIR__.'/../../Support/shiprocket-fixtures.php';

uses(RefreshDatabase::class);

const SRW_TOKEN = 'hook-token-for-tests';

beforeEach(function (): void {
    seed(LedgerAccountSeeder::class);
    Http::preventStrayRequests();
    config([
        'arovolife.fulfilment.shiprocket.email' => 'api@example.test',
        'arovolife.fulfilment.shiprocket.password' => 'not-a-real-password',
        'arovolife.fulfilment.shiprocket.base_url' => SR_SANDBOX,
        'arovolife.fulfilment.shiprocket.webhook_token' => SRW_TOKEN,
    ]);
    srSetting(FulfilmentSettings::KEY_SHIPROCKET_ENABLED, 'true');
    srSetting(FulfilmentSettings::KEY_SHIPROCKET_PICKUP_LOCATION, 'Arovolife-Hyd-Hub');
    Feature::for(null)->activate(ShiprocketFulfilmentFeature::class);
});

/** A home-delivery order booked through Shiprocket (AWB778899, shipment 7001) and shipped. */
function srwShipped(): Order
{
    srFake();
    $order = srPaidOrder();
    srDispatch($order);

    return $order->fresh();
}

/** What Shiprocket's tracking API will say about shipment 7001. */
function srwApiSays(string $status): void
{
    Http::fake([
        SR_SANDBOX.'/auth/login' => Http::response(['token' => SR_TOKEN]),
        SR_SANDBOX.'/courier/track/shipment/7001' => Http::response([
            'tracking_data' => ['shipment_track' => [['current_status' => $status]]],
        ]),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function srwPost(array $overrides = [], ?string $token = SRW_TOKEN): TestResponse
{
    return postJson(route('webhooks.courier.tracking'), array_merge([
        'awb' => 'AWB778899',
        'order_id' => 'ignored',
        'sr_order_id' => 9001,
        'current_status' => 'DELIVERED',
        'current_status_id' => 7,
        'current_timestamp' => '24 09 2026 15:01:00',
        'courier_name' => 'Delhivery Surface',
        'scans' => [['location' => 'Pune, near the buyer', 'activity' => 'Delivered to Ravi']],
    ], $overrides), $token === null ? [] : ['x-api-key' => $token]);
}

it('does not exist while the flag is off or no token is set', function () {
    Feature::for(null)->deactivate(ShiprocketFulfilmentFeature::class);
    srwPost()->assertNotFound();

    Feature::for(null)->activate(ShiprocketFulfilmentFeature::class);
    config(['arovolife.fulfilment.shiprocket.webhook_token' => '']);
    srwPost()->assertNotFound();
});

it('refuses a wrong token and stores nothing', function () {
    srwPost([], 'guessed')->assertUnauthorized();
    srwPost([], null)->assertUnauthorized();

    expect(ShipmentEvent::where('direction', ShipmentEvent::DIRECTION_WEBHOOK)->count())->toBe(0);
});

it('marks a home delivery delivered when the API confirms it, opening cooling-off', function () {
    $order = srwShipped();
    srwApiSays('DELIVERED');

    srwPost()->assertOk()->assertJson(['status' => 'queued']);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_DELIVERED)
        ->and(OrderCoolingOff::where('order_id', $order->id)->exists())->toBeTrue()
        ->and(Shipment::where('order_id', $order->id)->value('courier_status'))->toBe('DELIVERED')
        ->and(AuditLog::where('action', 'order.delivered_by_courier')->where('subject_id', $order->id)->exists())->toBeTrue();
});

it('ignores a DELIVERED body the API does not confirm', function () {
    $order = srwShipped();
    srwApiSays('IN TRANSIT');

    srwPost()->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED)
        ->and(Shipment::where('order_id', $order->id)->value('courier_status'))->toBe('IN TRANSIT');
});

it('stores a redelivered update once', function () {
    srwShipped();
    srwApiSays('IN TRANSIT');

    srwPost(['current_status' => 'IN TRANSIT', 'current_status_id' => 18])->assertOk();
    srwPost(['current_status' => 'IN TRANSIT', 'current_status_id' => 18])->assertOk()->assertJson(['status' => 'duplicate']);

    expect(ShipmentEvent::where('direction', ShipmentEvent::DIRECTION_WEBHOOK)->count())->toBe(1);
});

it('never stores the scan locations or anything else outside the allow-list', function () {
    srwShipped();
    srwApiSays('IN TRANSIT');

    srwPost(['current_status' => 'IN TRANSIT', 'customer_phone' => '9812345678'])->assertOk();

    $payload = json_encode(ShipmentEvent::where('direction', ShipmentEvent::DIRECTION_WEBHOOK)->sole()->payload);
    expect($payload)->not->toContain('near the buyer')
        ->and($payload)->not->toContain('Ravi')
        ->and($payload)->not->toContain('9812345678')
        ->and($payload)->toContain('AWB778899');
});

it('leaves a collection order alone when the courier delivers to the centre', function () {
    srFake();
    $order = srPaidOrder(null, 1, srCentre());
    srDispatch($order);
    srwApiSays('DELIVERED');

    srwPost()->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

it('flags a parcel the courier is returning, and drops it from the late-delivery list', function () {
    $order = srwShipped();
    Order::whereKey($order->id)->update(['shipped_at' => now()->subDays(30)]);
    srwApiSays('RTO INITIATED');

    srwPost(['current_status' => 'RTO INITIATED', 'current_status_id' => 9])->assertOk();

    $shipment = Shipment::where('order_id', $order->id)->sole();
    expect($shipment->status)->toBe(Shipment::STATUS_RETURNED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_SHIPPED)
        ->and(app(CourierExceptionProvider::class)->items()->pluck('subjectId')->all())->toBe([$order->id])
        ->and(app(ShippedNotDeliveredProvider::class)->items()->pluck('subjectId')->all())->not->toContain($order->id);
});

it('gives the buyer a tracking link for a Shiprocket parcel', function () {
    $order = srwShipped();
    $shipment = Shipment::where('order_id', $order->id)->sole();

    expect($shipment->trackingUrl())->toBe('https://shiprocket.co/tracking/AWB778899');

    $shipment->update(['gateway' => Shipment::GATEWAY_MANUAL]);
    expect($shipment->trackingUrl())->toBeNull();
});

it('tells the buyer the courier, the AWB and where to track it when the parcel ships', function () {
    Notification::fake();

    $order = srwShipped();

    Notification::assertSentTo(
        User::findOrFail($order->customer?->user_id),
        OrderStatusChangedNotification::class,
        fn (OrderStatusChangedNotification $n): bool => $n->statusLabel === 'Shipped'
            && $n->carrier === 'Delhivery Surface'
            && $n->awbNo === 'AWB778899'
            && $n->trackingUrl === 'https://shiprocket.co/tracking/AWB778899',
    );
});
