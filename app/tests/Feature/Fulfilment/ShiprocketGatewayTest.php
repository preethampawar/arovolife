<?php

declare(strict_types=1);

/**
 * Slice 4: the Shiprocket client and gateway (plan T8, T9), and the dispatch
 * rules that exist because a courier booking is a real van at a real address.
 *
 * No test here may reach the network: every file-level request is faked and
 * stray requests are refused. The dev .env holds a real sandbox API user, and
 * phpunit.xml blanks it; the credentials below are fakes set per test.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterDeclaration;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Data\ParcelGap;
use App\Modules\Fulfilment\Exceptions\MissingParcelDetailsException;
use App\Modules\Fulfilment\Exceptions\ShiprocketApiException;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use App\Modules\Fulfilment\Services\CourierGatewayResolver;
use App\Modules\Fulfilment\Services\DispatchService;
use App\Modules\Fulfilment\Services\ShiprocketClient;
use App\Modules\Fulfilment\Services\ShiprocketGateway;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Fulfilment\Support\ShiprocketPayloadScrubber;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Shared\Features\ShiprocketFulfilmentFeature;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

const SR_SANDBOX = 'https://api-sandbox.shiprocket.in/v1/external';
const SR_TOKEN = 'tok-secret-never-stored';
const SR_BUYER_PHONE = '9812345678';
const SR_BUYER_NAME = 'Ravi Kumarswamy';
const SR_BUYER_LINE1 = '42 Lake View Road';

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
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

function srSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
    app()->forgetInstance(FulfilmentSettings::class);
    app()->forgetInstance(ShiprocketGateway::class);
    app()->forgetInstance(CourierGatewayResolver::class);
    app()->forgetInstance(DispatchService::class);
}

/** @param  array<string, mixed>  $overrides */
function srVariant(array $overrides = []): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "SR-{$n}", 'slug' => "sr-{$n}", 'name' => "Aloe Juice {$n}", 'hsn_code' => '2202', 'status' => 'active']);

    return ProductVariant::create(array_merge([
        'product_id' => $product->id, 'variant_sku' => "SR-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
        'weight_g' => 1200, 'length_mm' => 250, 'breadth_mm' => 100, 'height_mm' => 80,
    ], $overrides));
}

function srCentre(): AreteCenter
{
    $n = random_int(100000, 999999);
    $centre = AreteCenter::create([
        'name' => "Centre {$n}", 'type' => AreteCenter::TYPE_COMPANY, 'status' => AreteCenter::STATUS_ACTIVE,
        'address_line_1' => '5 Market Street', 'city' => 'Warangal', 'state' => 'TELANGANA',
        'pincode' => '506002', 'contact_number' => '+918888888888', 'is_company_default' => true,
    ]);
    foreach (array_keys(AreteCenterDeclarations::all()) as $key) {
        AreteCenterDeclaration::create([
            'center_id' => $centre->id, 'declaration_key' => $key,
            'version' => AreteCenterDeclarations::VERSION, 'accepted_at' => now(), 'ip' => '127.0.0.1',
        ]);
    }

    return $centre;
}

function srPaidOrder(?ProductVariant $variant = null, int $qty = 2, ?AreteCenter $centre = null): Order
{
    $variant ??= srVariant();
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_no' => 'B'.random_int(1000, 9999), 'expiry_date' => now()->addYear()->toDateString(),
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => 10,
        'unit_cost_paise' => 60000, 'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);
    $user = User::create([
        'full_name' => SR_BUYER_NAME, 'email' => 'sr-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'sr'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => $qty,
        'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    $shipping = $centre === null
        ? ['name' => SR_BUYER_NAME, 'phone' => '+91'.SR_BUYER_PHONE, 'line1' => SR_BUYER_LINE1, 'line2' => null, 'city' => 'Pune', 'state' => 'MAHARASHTRA', 'pincode' => '411001']
        : ['name' => SR_BUYER_NAME, 'phone' => '+91'.SR_BUYER_PHONE, 'line1' => null, 'line2' => null, 'city' => null, 'state' => null, 'pincode' => null];

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => SR_BUYER_NAME, 'email' => $user->email, 'phone' => '+91'.SR_BUYER_PHONE, 'marketing_opt_in' => false],
        $shipping,
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
        false, null, $centre?->id,
    );

    app(OrderStateMachine::class)->markPaid($order->fresh());

    return $order->fresh();
}

/** @param  array<string, mixed>  $overrides  URL pattern => response, replacing the happy-path default */
function srFake(array $overrides = []): void
{
    Http::fake(array_merge([
        SR_SANDBOX.'/auth/login' => Http::response(['token' => SR_TOKEN]),
        SR_SANDBOX.'/orders/create/adhoc' => Http::response(['order_id' => 9001, 'shipment_id' => 7001, 'status' => 'NEW', 'status_code' => 1]),
        SR_SANDBOX.'/courier/assign/awb' => Http::response(['awb_assign_status' => 1, 'response' => ['data' => ['awb_code' => 'AWB778899', 'courier_name' => 'Delhivery Surface', 'shipment_id' => 7001]]]),
        SR_SANDBOX.'/courier/generate/pickup' => Http::response(['pickup_status' => 1, 'response' => ['pickup_scheduled_date' => '2026-09-25 10:00:00']]),
        SR_SANDBOX.'/courier/generate/label' => Http::response(['label_created' => 1, 'label_url' => 'https://labels.example.test/7001.pdf']),
    ], $overrides));
}

function srAvailable(): array
{
    app()->forgetInstance(CourierGatewayResolver::class);

    return array_keys(app(CourierGatewayResolver::class)->available());
}

function srDispatch(Order $order, ?string $route = Shipment::GATEWAY_SHIPROCKET, ?string $carrier = null, bool $confirmed = false): Shipment
{
    return app(DispatchService::class)->dispatch($order, $route, $carrier, null, null, null, $confirmed);
}

// ── T8: availability ────────────────────────────────────────────────────────

it('is not permitted without credentials, and the resolver offers manual only', function () {
    config(['arovolife.fulfilment.shiprocket.email' => '', 'arovolife.fulfilment.shiprocket.password' => '']);

    expect(app(ShiprocketGateway::class)->permitted())->toBeFalse()
        ->and(srAvailable())->toBe([Shipment::GATEWAY_MANUAL]);
});

it('offers Shiprocket only when the flag, the setting, the pickup and the credentials all agree', function () {
    expect(srAvailable())->toBe([Shipment::GATEWAY_MANUAL, Shipment::GATEWAY_SHIPROCKET]);

    Feature::for(null)->deactivate(ShiprocketFulfilmentFeature::class);
    expect(srAvailable())->toBe([Shipment::GATEWAY_MANUAL]);
    Feature::for(null)->activate(ShiprocketFulfilmentFeature::class);

    srSetting(FulfilmentSettings::KEY_SHIPROCKET_ENABLED, 'false');
    expect(srAvailable())->toBe([Shipment::GATEWAY_MANUAL]);
    srSetting(FulfilmentSettings::KEY_SHIPROCKET_ENABLED, 'true');

    srSetting(FulfilmentSettings::KEY_SHIPROCKET_PICKUP_LOCATION, '');
    expect(srAvailable())->toBe([Shipment::GATEWAY_MANUAL]);
});

it('books against the sandbox outside production and the live host only in production', function () {
    $client = app(ShiprocketClient::class);

    config(['arovolife.fulfilment.shiprocket.base_url' => 'https://apiv2.shiprocket.in/v1/external']);
    expect($client->configured())->toBeTrue()->and($client->hostMatchesEnvironment())->toBeFalse();

    // A look-alike host is not Shiprocket, and plain http is not accepted.
    config(['arovolife.fulfilment.shiprocket.base_url' => 'https://apiv2.shiprocket.in.example.net/v1']);
    expect($client->configured())->toBeFalse();
    config(['arovolife.fulfilment.shiprocket.base_url' => 'http://api-sandbox.shiprocket.in/v1/external']);
    expect($client->configured())->toBeFalse();

    // A blank base URL means the sandbox, never live.
    config(['arovolife.fulfilment.shiprocket.base_url' => '']);
    expect($client->host())->toBe(ShiprocketClient::SANDBOX_HOST);

    app()->detectEnvironment(fn (): string => 'production');
    try {
        expect($client->hostMatchesEnvironment())->toBeFalse();
        config(['arovolife.fulfilment.shiprocket.base_url' => 'https://apiv2.shiprocket.in/v1/external']);
        expect($client->hostMatchesEnvironment())->toBeTrue();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

// ── T9: booking ─────────────────────────────────────────────────────────────

it('books the parcel and stores no buyer details and no token anywhere', function () {
    srFake();
    $order = srPaidOrder();

    $shipment = srDispatch($order);

    expect($shipment->gateway)->toBe(Shipment::GATEWAY_SHIPROCKET)
        ->and($shipment->gateway_shipment_id)->toBe('7001')
        ->and($shipment->awb_no)->toBe('AWB778899')
        ->and($shipment->carrier_code)->toBe('Delhivery Surface')
        ->and($shipment->label_url)->toBe('https://labels.example.test/7001.pdf')
        ->and($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);

    $events = ShipmentEvent::where('gateway', Shipment::GATEWAY_SHIPROCKET)->get();
    expect($events->where('event_type', 'orders.create'))->toHaveCount(1)
        ->and($events->where('event_type', 'courier.assign_awb'))->toHaveCount(1)
        ->and($events->where('event_type', 'auth.login')->sole()->payload)->toBeNull()
        ->and($events->every(fn (ShipmentEvent $e): bool => $e->direction === ShipmentEvent::DIRECTION_OUTBOUND))->toBeTrue();

    $stored = $events->map(fn (ShipmentEvent $e): string => (string) json_encode($e->payload))->implode("\n");
    expect($stored)->toContain('AWB778899')
        ->not->toContain(SR_BUYER_PHONE)
        ->not->toContain(SR_BUYER_NAME)
        ->not->toContain(SR_BUYER_LINE1)
        ->not->toContain('411001')
        ->not->toContain(SR_TOKEN)
        ->not->toContain('not-a-real-password');

    // What Shiprocket was actually sent.
    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/orders/create/adhoc')) {
            return false;
        }

        return $request['billing_phone'] === SR_BUYER_PHONE
            && $request['billing_customer_name'] === 'Ravi' && $request['billing_last_name'] === 'Kumarswamy'
            && $request['payment_method'] === 'Prepaid'
            && $request['billing_email'] === config('arovolife.support_email')
            // Two units at 1.2 kg, stacked: 25 × 10 × (8 + 8) cm.
            && $request['weight'] === 2.4
            && $request['length'] === 25.0 && $request['breadth'] === 10.0 && $request['height'] === 16.0
            && $request['pickup_location'] === 'Arovolife-Hyd-Hub';
    });

    // The cached token is encrypted, not the bearer string itself.
    $cached = collect([Cache::get('fulfilment:shiprocket:token:'.sha1(SR_SANDBOX.'|api@example.test'))])->first();
    expect($cached)->toBeString()->not->toBe(SR_TOKEN);
});

it('sends a collection to the centre and never the collector phone', function () {
    srFake();
    $order = srPaidOrder(centre: srCentre());

    srDispatch($order);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/create/adhoc')
        && $request['billing_address'] === '5 Market Street'
        && $request['billing_pincode'] === '506002'
        && $request['billing_phone'] === '8888888888'
        && str_contains((string) $request['comment'], SR_BUYER_NAME)
        && ! str_contains((string) json_encode($request->data()), SR_BUYER_PHONE));
});

it('resumes a booking instead of creating a second consignment', function () {
    srFake([
        SR_SANDBOX.'/shipments/7001' => Http::response(['data' => ['id' => 7001, 'awb' => 'AWB-EXISTING', 'courier' => 'BlueDart']]),
    ]);
    $order = srPaidOrder();
    app(OrderFulfilmentService::class)->pack($order, null, null);
    Shipment::where('order_id', $order->id)->update(['gateway' => Shipment::GATEWAY_SHIPROCKET, 'gateway_shipment_id' => '7001']);

    $shipment = srDispatch($order->fresh());

    expect($shipment->awb_no)->toBe('AWB-EXISTING')->and($shipment->carrier_code)->toBe('BlueDart');
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/orders/create/adhoc'));
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/courier/assign/awb'));
});

it('finds an order an unanswered create may have made before creating again', function () {
    $order = srPaidOrder();
    srFake([
        SR_SANDBOX.'/orders?search=*' => Http::response(['data' => [
            ['id' => 9001, 'channel_order_id' => $order->order_no, 'shipments' => [['id' => 7001]]],
        ]]),
        SR_SANDBOX.'/shipments/7001' => Http::response(['data' => ['id' => 7001, 'awb' => '']]),
    ]);
    app(OrderFulfilmentService::class)->pack($order, null, null);
    // Claimed by an earlier attempt that never heard back.
    Shipment::where('order_id', $order->id)->update(['gateway' => Shipment::GATEWAY_SHIPROCKET, 'gateway_shipment_id' => null]);

    $shipment = srDispatch($order->fresh());

    expect($shipment->gateway_shipment_id)->toBe('7001')->and($shipment->awb_no)->toBe('AWB778899');
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/orders/create/adhoc'));
});

it('logs in again exactly once when the token has expired', function () {
    srFake([
        SR_SANDBOX.'/courier/assign/awb' => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push(['awb_assign_status' => 1, 'response' => ['data' => ['awb_code' => 'AWB1', 'courier_name' => 'Xpressbees']]]),
    ]);

    srDispatch(srPaidOrder());

    Http::assertSentCount(7); // login, create, assign(401), login, assign, pickup, label
    expect(ShipmentEvent::where('event_type', 'auth.login')->count())->toBe(2);
});

it('keeps the booking and the evidence when no AWB can be assigned', function () {
    srFake([
        SR_SANDBOX.'/courier/assign/awb' => Http::response(['awb_assign_status' => 0, 'response' => ['data' => ['awb_assign_error' => 'Insufficient balance for 411001, call 9812345678']]]),
    ]);
    $order = srPaidOrder();

    expect(fn () => srDispatch($order))->toThrow(ShiprocketApiException::class, 'Insufficient balance');

    $shipment = Shipment::where('order_id', $order->id)->sole();
    // The consignment exists at Shiprocket; a retry must resume it, not re-create it.
    expect($shipment->gateway)->toBe(Shipment::GATEWAY_SHIPROCKET)
        ->and($shipment->gateway_shipment_id)->toBe('7001')
        ->and($order->fresh()->status)->toBe(Order::STATUS_READY_TO_SHIP);

    // The failure row survived — it was not inside a rolled-back transaction.
    $failed = ShipmentEvent::where('event_type', 'courier.assign_awb')->sole();
    expect($failed->error)->toContain('Insufficient balance')
        ->and($failed->error)->not->toContain('9812345678')
        ->and($failed->error)->not->toContain('411001');
});

it('releases the claim when Shiprocket refuses the order outright', function () {
    srFake([
        SR_SANDBOX.'/orders/create/adhoc' => Http::response(['message' => 'Invalid data', 'errors' => ['billing_phone' => ['The billing phone 9812345678 is invalid']]], 422),
    ]);
    $order = srPaidOrder();

    expect(fn () => srDispatch($order))->toThrow(ShiprocketApiException::class);

    $shipment = Shipment::where('order_id', $order->id)->sole();
    expect($shipment->gateway)->toBe(Shipment::GATEWAY_MANUAL)->and($shipment->gateway_shipment_id)->toBeNull();

    $row = ShipmentEvent::where('event_type', 'orders.create')->sole();
    expect($row->payload['response']['errors'])->toBe(['billing_phone' => 'invalid']);
});

it('keeps the claim when the create gets a 5xx, because Shiprocket may have booked it', function () {
    srFake([SR_SANDBOX.'/orders/create/adhoc' => Http::response(['message' => 'Bad gateway'], 502)]);
    $order = srPaidOrder();

    expect(fn () => srDispatch($order))->toThrow(ShiprocketApiException::class);

    $shipment = Shipment::where('order_id', $order->id)->sole();
    expect($shipment->gateway)->toBe(Shipment::GATEWAY_SHIPROCKET)->and($shipment->gateway_shipment_id)->toBeNull();
});

it('will not create again after an unanswered booking until the operator confirms it is absent', function () {
    $order = srPaidOrder();
    srFake([SR_SANDBOX.'/orders?search=*' => Http::response(['data' => []])]);
    app(OrderFulfilmentService::class)->pack($order, null, null);
    Shipment::where('order_id', $order->id)->update(['gateway' => Shipment::GATEWAY_SHIPROCKET, 'gateway_shipment_id' => null]);

    expect(fn () => srDispatch($order->fresh()))->toThrow(ShiprocketApiException::class, 'does not show it yet');
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/orders/create/adhoc'));

    $shipment = srDispatch($order->fresh(), confirmed: true);

    expect($shipment->gateway_shipment_id)->toBe('7001')->and($shipment->awb_no)->toBe('AWB778899');
    $audit = AuditLog::where('action', 'order.dispatched')->sole();
    expect($audit->details['unanswered_booking_confirmed_absent_by_operator'])->toBeTrue()
        ->and($audit->details['remote_booking_cancelled_by_operator'])->toBeNull();
});

it('refuses a zero shipment id instead of storing it', function () {
    srFake([SR_SANDBOX.'/orders/create/adhoc' => Http::response(['order_id' => 9001, 'shipment_id' => 0])]);

    expect(fn () => srDispatch(srPaidOrder()))->toThrow(ShiprocketApiException::class, 'no shipment id');
});

it('still dispatches when the label cannot be generated', function () {
    srFake([SR_SANDBOX.'/courier/generate/label' => Http::response(['message' => 'Label not ready'], 500)]);

    $shipment = srDispatch(srPaidOrder());

    expect($shipment->awb_no)->toBe('AWB778899')->and($shipment->label_url)->toBeNull();
});

// ── Parcel details: refused, never guessed ──────────────────────────────────

it('lists exactly which parcel details each product is missing', function () {
    $order = srPaidOrder(srVariant(['weight_g' => 0, 'height_mm' => null]));

    $gaps = app(ShiprocketGateway::class)->parcelGaps($order);

    expect($gaps)->toHaveCount(1)
        ->and($gaps[0]->missing)->toBe([ParcelGap::WEIGHT, ParcelGap::HEIGHT])
        ->and($gaps[0]->productVariantId)->toBe($order->items->first()->product_variant_id);
});

it('refuses Shiprocket for an order with missing parcel details before calling it', function () {
    srFake();
    $order = srPaidOrder(srVariant(['length_mm' => null]));

    expect(fn () => srDispatch($order))->toThrow(MissingParcelDetailsException::class, 'missing length');

    Http::assertNothingSent();
    expect($order->fresh()->status)->not->toBe(Order::STATUS_SHIPPED);
});

// ── Dispatch rules ──────────────────────────────────────────────────────────

it('refuses a route that is not available instead of dispatching manually', function () {
    Feature::for(null)->deactivate(ShiprocketFulfilmentFeature::class);
    $order = srPaidOrder();

    expect(fn () => srDispatch($order))->toThrow(RuntimeException::class, 'not available');
    expect(Shipment::where('order_id', $order->id)->exists())->toBeFalse();
});

it('refuses a second dispatch of the same order while one is running', function () {
    $order = srPaidOrder();
    $lock = Cache::lock('fulfilment:dispatch:order:'.$order->id, 120);
    $lock->get();

    try {
        expect(fn () => srDispatch($order, Shipment::GATEWAY_MANUAL, 'Delhivery'))
            ->toThrow(RuntimeException::class, 'already being dispatched');
    } finally {
        $lock->release();
    }
});

it('will not hand-dispatch a parcel booked with Shiprocket until the operator confirms it was cancelled', function () {
    $order = srPaidOrder();
    app(OrderFulfilmentService::class)->pack($order, null, null);
    Shipment::where('order_id', $order->id)->update(['gateway' => Shipment::GATEWAY_SHIPROCKET, 'gateway_shipment_id' => '7001']);

    expect(fn () => srDispatch($order->fresh(), Shipment::GATEWAY_MANUAL, 'DTDC'))
        ->toThrow(RuntimeException::class, 'already booked with shiprocket (shipment 7001)');

    $shipment = srDispatch($order->fresh(), Shipment::GATEWAY_MANUAL, 'DTDC', confirmed: true);

    // The cancelled booking's reference survives; the carrier actually used is on the row.
    expect($shipment->gateway)->toBe(Shipment::GATEWAY_SHIPROCKET)
        ->and($shipment->gateway_shipment_id)->toBe('7001')
        ->and($shipment->carrier_code)->toBe('DTDC');

    $audit = AuditLog::where('action', 'order.dispatched')->where('subject_id', $order->id)->sole();
    expect($audit->details['remote_booking_cancelled_by_operator'])
        ->toBe(['gateway' => Shipment::GATEWAY_SHIPROCKET, 'gateway_shipment_id' => '7001']);
});

// ── Scrubber ────────────────────────────────────────────────────────────────

it('keeps product names in order lines but no person anywhere', function () {
    $scrubbed = app(ShiprocketPayloadScrubber::class)->scrub([
        'name' => SR_BUYER_NAME,
        'billing_customer_name' => SR_BUYER_NAME,
        'billing_phone' => SR_BUYER_PHONE,
        'order_items' => [['name' => 'Aloe Juice', 'sku' => 'AJ-1', 'units' => 2, 'selling_price' => 1000, 'customer_name' => SR_BUYER_NAME]],
        'message' => 'Pincode 411001 not serviceable',
        'shipment_id' => [7001],
    ]);

    expect($scrubbed)->toBe([
        'order_items' => [['name' => 'Aloe Juice', 'sku' => 'AJ-1', 'units' => 2, 'selling_price' => 1000]],
        'message' => 'Pincode # not serviceable',
        'shipment_id' => [7001],
    ]);
});

it('masks spaced phone numbers, pincodes and emails in free text', function () {
    $text = app(ShiprocketPayloadScrubber::class)->sanitise('Call +91-98123-45678 or 98123 45678, pin 411 001, mail a.b@example.com');

    expect($text)->not->toContain('45678')->not->toContain('411 001')->not->toContain('example.com')
        ->toContain('Call');
});

it('sends a one-word name with a placeholder last name, which Shiprocket requires', function () {
    srFake();
    $order = srPaidOrder();
    $order->forceFill(['ship_name' => 'Ravi'])->save();

    srDispatch($order->fresh());

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders/create/adhoc')
        && $request['billing_customer_name'] === 'Ravi' && $request['billing_last_name'] === '.');
});
