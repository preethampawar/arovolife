<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Returns\Models\BuybackDecision;
use App\Modules\Returns\Models\ReturnInspection;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\InspectReturn;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(
        ['key' => 'commerce.self_purchase.earns_bv'],
        ['value' => 'false', 'version' => 1, 'updated_at' => now()],
    );
});

function inspectReturnFixture(string $reason = ReturnRequest::REASON_DAMAGE): array
{
    $userId = DB::table('users')->insertGetId([
        'full_name' => 'IRT Inspector', 'email' => 'irt-'.uniqid().'@test.com',
        'phone_e164' => '+9160000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $customer = Customer::create([
        'display_name' => 'IRT Customer',
        'user_id' => $userId,
        'email_hash' => 'irt-'.uniqid(),
        'email_enc' => null,
        'claimed_at' => now(),
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-IRT-'.rand(10000, 99999),
        'customer_id' => $customer->id,
        'payment_method' => 'online',
        'status' => Order::STATUS_REFUND_REQUESTED,
        'self_consumption' => false,
        'subtotal_paise' => 118000,
        'gst_paise' => 18000,
        'discount_paise' => 0,
        'shipping_paise' => 5000,
        'total_paise' => 123000,
        'ship_name' => 'T', 'ship_phone_e164' => '+919000000000',
        'ship_line1' => '1', 'ship_city' => 'H', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDays(20), 'delivered_at' => now()->subDays(8),
        'idempotency_key' => 'irt-'.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $order = Order::findOrFail($orderId);

    OrderCoolingOff::create([
        'order_id' => $orderId,
        'opened_at' => now()->subDays(8),
        'ends_at' => now()->addDays(22),
        'status' => OrderCoolingOff::STATUS_OPEN,
    ]);

    $rq = ReturnRequest::create([
        'rma_no' => 'RMA-IRT-'.rand(10000, 99999),
        'order_id' => $orderId,
        'order_item_id' => null,
        'qty' => null,
        'reason' => $reason,
        'opened_by_customer_id' => $customer->id,
        'status' => ReturnRequest::STATUS_OPENED,
    ]);

    return ['order' => $order, 'returnRequest' => $rq, 'userId' => $userId];
}

it('IRT-01: record() creates ReturnInspection and BuybackDecision', function (): void {
    ['order' => $order, 'returnRequest' => $rq, 'userId' => $userId] = inspectReturnFixture();

    app(InspectReturn::class)->record($rq, 'saleable', 'Good condition', $userId);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_REFUND_INSPECTION);

    expect(ReturnInspection::where('return_request_id', $rq->id)->exists())->toBeTrue();
    $decision = BuybackDecision::where('return_request_id', $rq->id)->first();
    expect($decision)->not->toBeNull();
    expect($decision->net_refund_paise)->toBe(118000); // subtotal for damage saleable
});

it('IRT-02: record() non-saleable → GST not refunded', function (): void {
    ['returnRequest' => $rq] = inspectReturnFixture();

    app(InspectReturn::class)->record($rq, 'non_saleable', null, null);

    $decision = BuybackDecision::where('return_request_id', $rq->id)->first();
    expect($decision->net_refund_paise)->toBe(100000); // taxable only
    expect($decision->gst_adjustment_paise)->toBe(0);
});

it('IRT-03: approve() executes RefundOrder and moves order to refund_approved', function (): void {
    Event::fake();
    ['order' => $order, 'returnRequest' => $rq, 'userId' => $userId] = inspectReturnFixture();

    $service = app(InspectReturn::class);
    $service->record($rq, 'saleable', null, $userId);
    $service->approve($rq, $userId);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_REFUND_APPROVED);
    $rq->refresh();
    expect($rq->status)->toBe(ReturnRequest::STATUS_APPROVED);
});

it('IRT-04: reject() reverts order to delivered and marks return rejected', function (): void {
    Event::fake();
    ['order' => $order, 'returnRequest' => $rq, 'userId' => $userId] = inspectReturnFixture();

    app(InspectReturn::class)->reject($rq, $userId);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_DELIVERED);
    $rq->refresh();
    expect($rq->status)->toBe(ReturnRequest::STATUS_REJECTED);
});

it('IRT-05: record() on a cooling-off request throws', function (): void {
    ['returnRequest' => $rq] = inspectReturnFixture(ReturnRequest::REASON_COOLING_OFF);

    expect(fn () => app(InspectReturn::class)->record($rq, 'saleable', null, null))
        ->toThrow(RuntimeException::class);
});

it('IRT-06: approve() throws without inspection first', function (): void {
    Event::fake();
    ['returnRequest' => $rq, 'userId' => $userId] = inspectReturnFixture();

    expect(fn () => app(InspectReturn::class)->approve($rq, $userId))
        ->toThrow(RuntimeException::class);
});

it('IRT-07: BuybackDecision stamped with approved_by_user_id + approved_at on approve', function (): void {
    Event::fake();
    ['returnRequest' => $rq, 'userId' => $userId] = inspectReturnFixture();

    $service = app(InspectReturn::class);
    $service->record($rq, 'saleable', null, $userId);
    $service->approve($rq, $userId);

    $decision = BuybackDecision::where('return_request_id', $rq->id)->first();
    expect($decision->approved_by_user_id)->toBe($userId);
    expect($decision->approved_at)->not->toBeNull();
});
