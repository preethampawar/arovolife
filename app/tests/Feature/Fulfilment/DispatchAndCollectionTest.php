<?php

declare(strict_types=1);

/**
 * Slice 5: a parcel reaching a collection centre, and staff being able to see
 * that it is going there at all.
 *
 * Before this, `orders.arete_center_id` was read by the ADC commission engine
 * and by nothing else — no shipment routed to a centre, and the centre's name
 * appeared in no admin view and on no buyer-facing page after checkout. That
 * was the substance of R-47.
 */

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartItem;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterDeclaration;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Services\CollectionHandoverService;
use App\Modules\Fulfilment\Services\DispatchService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

function dispatchVariant(): ProductVariant
{
    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "DSP-{$n}", 'slug' => "dsp-{$n}", 'name' => "DSP {$n}", 'hsn_code' => '3004', 'status' => 'active']);

    return ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "DSP-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 100000, 'sale_price_paise' => 100000, 'cost_paise' => 60000,
        'gst_rate_bp' => 1800, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
}

function undeclaredCentre(): AreteCenter
{
    $n = random_int(100000, 999999);

    // Exactly how AdminAreteCenterController::store() makes one: straight to
    // active, no application, and therefore no declaration in any version.
    return AreteCenter::create([
        'name' => "Centre {$n}", 'type' => AreteCenter::TYPE_COMPANY, 'status' => AreteCenter::STATUS_ACTIVE,
        'address_line_1' => '5 Market Street', 'city' => 'Warangal', 'state' => 'TELANGANA',
        'pincode' => '506002', 'contact_number' => '+918888888888', 'is_company_default' => true,
    ]);
}

function withDeclarations(AreteCenter $centre): AreteCenter
{
    foreach (array_keys(AreteCenterDeclarations::all()) as $key) {
        AreteCenterDeclaration::create([
            'center_id' => $centre->id,
            'declaration_key' => $key,
            'version' => AreteCenterDeclarations::VERSION,
            'accepted_at' => now(),
            'ip' => '127.0.0.1',
        ]);
    }

    return $centre;
}

function dispatchCentre(): AreteCenter
{
    return withDeclarations(undeclaredCentre());
}

function stockFor(ProductVariant $variant, int $qty = 5): void
{
    $batch = StockBatch::create([
        'product_variant_id' => $variant->id, 'warehouse_code' => Warehouse::DEFAULT_CODE,
        'batch_no' => 'B'.random_int(1000, 9999), 'expiry_date' => now()->addYear()->toDateString(),
        'unit_cost_paise' => 60000, 'qty_on_hand' => 0, 'received_at' => now(),
    ]);
    app(StockLedger::class)->post([
        'type' => StockMovement::TYPE_PURCHASE_IN, 'variant_id' => $variant->id,
        'warehouse_code' => Warehouse::DEFAULT_CODE, 'batch_id' => $batch->id, 'qty' => $qty,
        'unit_cost_paise' => 60000, 'reference_type' => 'purchase_invoice_item', 'reference_id' => 1,
    ]);
}

function paidOrderFor(?AreteCenter $centre): Order
{
    $variant = dispatchVariant();
    stockFor($variant);
    $user = User::create([
        'full_name' => 'Dsp Buyer', 'email' => 'dsp-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $cart = Cart::create(['anonymous_key' => 'dsp'.uniqid(), 'expires_at' => now()->addDay()]);
    CartItem::create([
        'cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'unit_price_paise' => 100000, 'bv_paise' => 0, 'gst_rate_bp' => 1800,
    ]);

    $shipping = $centre === null
        ? ['name' => 'Dsp', 'phone' => '+919800000000', 'line1' => '1 St', 'line2' => null, 'city' => 'Pune', 'state' => 'MAHARASHTRA', 'pincode' => '411001']
        : ['name' => 'Dsp', 'phone' => '+919800000000', 'line1' => null, 'line2' => null, 'city' => null, 'state' => null, 'pincode' => null];

    $order = app(CheckoutService::class)->place(
        $cart->load('items.variant.product'),
        ['name' => 'Dsp', 'email' => $user->email, 'phone' => '+919800000000', 'marketing_opt_in' => false],
        $shipping,
        [], null, 'direct', Order::PAYMENT_ONLINE, null, $user->id, null,
        false, null, $centre?->id,
    );

    app(OrderStateMachine::class)->markPaid($order->fresh());

    return $order->fresh();
}

it('consigns a collection to the centre, not to the buyer', function () {
    $centre = dispatchCentre();
    $order = paidOrderFor($centre);

    $shipment = app(DispatchService::class)->dispatch($order, null, 'Delhivery', 'AWB123', null);

    expect($shipment->arete_center_id)->toBe($centre->id)
        ->and($shipment->gateway)->toBe(Shipment::GATEWAY_MANUAL)
        ->and($shipment->carrier_code)->toBe('Delhivery')
        ->and($shipment->consigned_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

it('leaves a home delivery with no centre on its shipment', function () {
    $order = paidOrderFor(null);

    $shipment = app(DispatchService::class)->dispatch($order, null, 'BlueDart', 'AWB999', null);

    expect($shipment->arete_center_id)->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

it('refuses to dispatch a collection whose centre has been deleted', function () {
    $centre = dispatchCentre();
    $order = paidOrderFor($centre);
    $centre->delete();

    // delivery_type survived the FK nulling, so we know this was never meant
    // to be a home delivery. There is no address to fall back to, and quietly
    // inventing one is how the buyer gets a parcel they never asked for.
    app(DispatchService::class)->dispatch($order->fresh(), null, 'Delhivery', 'AWB1', null);
})->throws(RuntimeException::class, 'no longer exists');

it('records arrival at the centre without opening cooling-off', function () {
    $order = paidOrderFor(dispatchCentre());
    app(DispatchService::class)->dispatch($order, null, 'Delhivery', 'AWB123', null);

    app(OrderStateMachine::class)->markAwaitingCollection($order->fresh(), null);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_AWAITING_COLLECTION)
        ->and($order->delivered_at)->toBeNull()
        // The buyer has not received anything yet. Opening the statutory clock
        // here would burn days of their 30 before they had the goods.
        ->and(DB::table('order_cooling_off')->where('order_id', $order->id)->count())->toBe(0)
        ->and(Shipment::where('order_id', $order->id)->sole()->status)->toBe(Shipment::STATUS_AT_CENTRE);
});

it('will not mark a home delivery as awaiting collection', function () {
    $order = paidOrderFor(null);
    app(DispatchService::class)->dispatch($order, null, 'BlueDart', 'AWB9', null);

    app(OrderStateMachine::class)->markAwaitingCollection($order->fresh(), null);
})->throws(RuntimeException::class, 'home delivery');

it('starts cooling-off when the buyer actually collects', function () {
    $order = paidOrderFor(dispatchCentre());
    app(DispatchService::class)->dispatch($order, null, 'Delhivery', 'AWB123', null);
    app(OrderStateMachine::class)->markAwaitingCollection($order->fresh(), null);

    $coolingOff = app(OrderStateMachine::class)->markDelivered($order->fresh(), null);

    expect($order->fresh()->status)->toBe(Order::STATUS_DELIVERED)
        ->and($coolingOff->opened_at->diffInDays($coolingOff->ends_at))->toBeGreaterThanOrEqual(29);
});

it('shows the centre to staff on the order page', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $centre = dispatchCentre();
    $order = paidOrderFor($centre);

    $admin = User::create([
        'full_name' => 'Ops', 'email' => 'ops-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999), 'password_hash' => bcrypt('x'),
        'status' => 'active', 'email_verified_at' => now(),
    ]);
    $admin->assignRole('admin');

    $html = $this->actingAs($admin)
        ->get(route('admin.commerce.orders.show', $order))
        ->assertOk()
        ->getContent();

    // The centre's name appeared in no admin view at all before this.
    expect($html)->toContain($centre->name)
        ->toContain('Collection')
        ->toContain('Consign the parcel to this centre');
});

it('tells the buyer where to collect, not where it is being shipped', function () {
    $centre = dispatchCentre();
    $order = paidOrderFor($centre);
    $customer = Customer::find($order->customer_id);
    $user = User::find($customer->user_id);

    $html = $this->actingAs($user)
        ->get(route('orders.show', $order->order_no))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Collect from')
        ->toContain($centre->name)
        // The heading that made R-47 visible to buyers.
        ->not->toContain('Shipping to');
});

it('refuses to consign to a centre that has accepted no declaration', function () {
    // R-95: this is how AdminAreteCenterController::store() leaves a centre.
    // Routing a parcel to an operator who has undertaken not to receive one
    // is the company inducing breach of its own undertaking.
    $order = paidOrderFor(undeclaredCentre());

    app(DispatchService::class)->dispatch($order, null, 'Delhivery', 'AWB1', null);
})->throws(RuntimeException::class, 'has not accepted the current centre declarations');

it('names what a centre still owes', function () {
    $centre = undeclaredCentre();

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeFalse()
        ->and(AreteCenterDeclaration::outstandingFor($centre->id))
        ->toBe(array_keys(AreteCenterDeclarations::all()));

    withDeclarations($centre);

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeTrue()
        ->and(AreteCenterDeclaration::outstandingFor($centre->id))->toBe([]);
});

it('treats a partial acceptance as no acceptance', function () {
    $centre = undeclaredCentre();

    // Four of five is not the bargain.
    foreach (array_slice(array_keys(AreteCenterDeclarations::all()), 0, 4) as $key) {
        AreteCenterDeclaration::create([
            'center_id' => $centre->id, 'declaration_key' => $key,
            'version' => AreteCenterDeclarations::VERSION, 'accepted_at' => now(),
        ]);
    }

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeFalse();
});

/** @return array{0: Order, 1: string} */
function parcelAwaitingCollection(): array
{
    $order = paidOrderFor(dispatchCentre());
    app(DispatchService::class)->dispatch($order, null, 'Delhivery', 'AWB123', null);
    app(OrderStateMachine::class)->markAwaitingCollection($order->fresh(), null);

    $shipment = Shipment::where('order_id', $order->id)->sole();
    $code = app(CollectionHandoverService::class)->issueCode($shipment);

    return [$order->fresh(), $code];
}

it('records a handover against the code the buyer presents', function () {
    [$order, $code] = parcelAwaitingCollection();

    $shipment = app(CollectionHandoverService::class)->recordCollection($order, $code, null);

    expect($shipment->collected_at)->not->toBeNull()
        // The receipt R-47 asks for, and what the ADC bonus now reads.
        ->and($shipment->pod_hash_sha256)->not->toBeNull()
        // Spent: a code that still verified would release a second parcel on
        // the same authority.
        ->and($shipment->handover_code_hash)->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_DELIVERED);
});

it('never stores the collection code in the clear', function () {
    [, $code] = parcelAwaitingCollection();

    $row = (array) DB::table('shipments')->latest('id')->first();

    foreach ($row as $value) {
        expect((string) $value)->not->toContain($code);
    }
});

it('refuses a wrong code and counts the attempt', function () {
    [$order, $code] = parcelAwaitingCollection();
    $wrong = $code === '000000' ? '111111' : '000000';

    expect(fn () => app(CollectionHandoverService::class)->recordCollection($order, $wrong, null))
        ->toThrow(RuntimeException::class, 'not correct');

    expect(Shipment::where('order_id', $order->id)->sole()->handover_attempts)->toBe(1)
        ->and($order->fresh()->status)->toBe(Order::STATUS_AWAITING_COLLECTION);
});

it('locks a parcel after five wrong codes', function () {
    [$order, $code] = parcelAwaitingCollection();
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < CollectionHandoverService::MAX_ATTEMPTS; $i++) {
        try {
            app(CollectionHandoverService::class)->recordCollection($order, $wrong, null);
        } catch (RuntimeException) {
            // counted
        }
    }

    // Even the right code no longer works: staff have to release it.
    expect(fn () => app(CollectionHandoverService::class)->recordCollection($order, $code, null))
        ->toThrow(RuntimeException::class, 'locked');
});

it('pays a centre only for parcels it actually handed over', function () {
    $centre = dispatchCentre();

    $collected = paidOrderFor($centre);
    app(DispatchService::class)->dispatch($collected, null, 'Delhivery', 'A1', null);
    app(OrderStateMachine::class)->markAwaitingCollection($collected->fresh(), null);
    $shipment = Shipment::where('order_id', $collected->id)->sole();
    $code = app(CollectionHandoverService::class)->issueCode($shipment);
    app(CollectionHandoverService::class)->recordCollection($collected->fresh(), $code, null);

    // A second order names the same centre but was never handed over.
    $uncollected = paidOrderFor($centre);
    app(DispatchService::class)->dispatch($uncollected, null, 'Delhivery', 'A2', null);

    $counted = DB::table('orders')
        ->join('shipments', 'shipments.order_id', '=', 'orders.id')
        ->where('orders.arete_center_id', $centre->id)
        ->whereNotNull('shipments.collected_at')
        ->count();

    // H5: paying on arete_center_id alone would have counted both — 3% for a
    // parcel the centre never released.
    expect($counted)->toBe(1);
});
