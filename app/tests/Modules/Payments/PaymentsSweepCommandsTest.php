<?php

declare(strict_types=1);

/**
 * The scheduled backstops.
 *
 * PSW-01: payments:reconcile confirms an open intent the gateway reports captured, and leaves a young one alone
 * PSW-02: payments:reconcile records a failed attempt without cancelling anything
 * PSW-03: orders:expire-unpaid cancels an old unpaid order, releases stock, closes the intent and audits it
 * PSW-04: orders:expire-unpaid confirms instead of cancelling when the gateway reports captured
 * PSW-05: orders:expire-unpaid leaves an order alone when the gateway cannot be reached
 * PSW-06: orders:expire-unpaid never touches a young order, a paid order, or a COD order
 * PSW-07: an expired-and-cancelled order holds no invoice
 * PSW-08: payments:redact-events drops old payloads and keeps the derived record
 */

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Tax\Models\Invoice;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function pswOrder(int $minutesAgo, string $method = Order::PAYMENT_ONLINE): Order
{
    $customer = Customer::create(['display_name' => 'PSW Buyer']);
    $order = Order::create([
        'order_no' => 'ORD-PSW-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => $method,
        'status' => Order::STATUS_PLACED,
        'subtotal_paise' => 118000, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
        'total_paise' => 118000,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subMinutes($minutesAgo),
        'idempotency_key' => 'psw-'.uniqid(),
    ]);

    $n = random_int(10000, 99999);
    $product = Product::create(['sku' => "PSW-{$n}", 'slug' => "psw-{$n}", 'name' => "PSW {$n}", 'hsn_code' => '3004', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_sku' => "PSW-{$n}-V1", 'name' => 'Default',
        'mrp_paise' => 118000, 'sale_price_paise' => 118000, 'gst_rate_bp' => 0, 'inventory_policy' => 'track', 'status' => 'active',
    ]);
    InventoryLevel::create(['product_variant_id' => $variant->id, 'warehouse_code' => 'DEFAULT', 'on_hand' => 10, 'reserved' => 1]);
    OrderItem::create([
        'order_id' => $order->id, 'product_variant_id' => $variant->id, 'qty' => 1,
        'product_name_snapshot' => $product->name, 'variant_sku_snapshot' => $variant->variant_sku, 'hsn_code_snapshot' => '3004',
        'unit_price_paise' => 118000, 'taxable_value_paise' => 118000, 'line_total_paise' => 118000, 'bv_paise' => 0, 'gst_rate_bp' => 0, 'gst_paise' => 0,
    ]);

    return $order;
}

function pswIntent(Order $order, int $minutesAgo, string $gatewayOrderId): PaymentIntent
{
    $intent = PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => $gatewayOrderId, 'mode' => 'test',
        'amount_paise' => $order->total_paise, 'status' => PaymentIntent::STATUS_CREATED, 'idempotency_key' => 'order:'.$order->id,
    ]);
    PaymentIntent::where('id', $intent->id)->update(['created_at' => now()->subMinutes($minutesAgo)]);

    return $intent->fresh();
}

/** @return array<string, mixed> */
function pswCaptured(string $gatewayOrderId): array
{
    return ['entity' => 'collection', 'count' => 1, 'items' => [
        ['id' => 'pay_'.$gatewayOrderId, 'order_id' => $gatewayOrderId, 'amount' => 118000, 'currency' => 'INR', 'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'upi'],
    ]];
}

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    config()->set('arovolife.payments.razorpay', [
        'key_id' => 'rzp_test_ABCDEF123456', 'key_secret' => 's', 'webhook_secret' => 'w',
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('PSW-01: reconcile confirms an open intent the gateway reports captured and leaves a young one alone', function () {
    $old = pswOrder(10);
    pswIntent($old, 10, 'order_old');
    $young = pswOrder(1);
    pswIntent($young, 1, 'order_young');
    Http::fake(['api.razorpay.com/v1/orders/order_old/payments' => Http::response(pswCaptured('order_old'), 200)]);

    $this->artisan('payments:reconcile', ['--minutes' => 3])->assertSuccessful();

    expect($old->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and($young->fresh()->status)->toBe(Order::STATUS_PLACED);
    Http::assertSentCount(1);
});

it('PSW-02: reconcile records a failed attempt without cancelling anything', function () {
    $order = pswOrder(10);
    $intent = pswIntent($order, 10, 'order_fail');
    Http::fake(['api.razorpay.com/v1/orders/order_fail/payments' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
        ['id' => 'pay_f', 'order_id' => 'order_fail', 'amount' => 118000, 'currency' => 'INR', 'status' => 'failed', 'captured' => false, 'error_code' => 'BAD_REQUEST_ERROR', 'error_description' => 'declined'],
    ]], 200)]);

    $this->artisan('payments:reconcile', ['--minutes' => 3])->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED)
        ->and($intent->fresh()->status)->toBe(PaymentIntent::STATUS_CREATED)
        ->and($intent->fresh()->error_code)->toBe('BAD_REQUEST_ERROR');
});

it('PSW-03: expire cancels an old unpaid order, releases stock, closes the intent and audits it', function () {
    $order = pswOrder(45);
    $intent = pswIntent($order, 45, 'order_exp');
    Http::fake(['api.razorpay.com/v1/orders/order_exp/payments' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []], 200)]);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_CANCELLED)
        ->and($intent->fresh()->status)->toBe(PaymentIntent::STATUS_CANCELLED)
        ->and($intent->fresh()->cancel_reason)->toBe('payment_expired')
        ->and($order->items->first()->variant->inventory->reserved)->toBe(0);

    $audit = AuditLog::where('action', 'order.expired_by_sweeper')->where('subject_id', $order->id)->sole();
    expect($audit->actor_id)->toBeNull()
        ->and($audit->details['final_gateway_check'])->toBe('pending')
        ->and($audit->details['payment_intent_id'])->toBe($intent->id);
});

it('PSW-04: expire confirms instead of cancelling when the gateway reports captured', function () {
    $order = pswOrder(45);
    pswIntent($order, 45, 'order_late');
    Http::fake(['api.razorpay.com/v1/orders/order_late/payments' => Http::response(pswCaptured('order_late'), 200)]);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and(AuditLog::where('action', 'order.expired_by_sweeper')->count())->toBe(0);
});

it('PSW-05: expire leaves an order alone when the gateway cannot be reached', function () {
    $order = pswOrder(45);
    pswIntent($order, 45, 'order_down');
    Http::fake(['api.razorpay.com/v1/orders/order_down/payments' => Http::response(['error' => ['code' => 'SERVER_ERROR']], 503)]);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('PSW-06: expire never touches a young order, a paid order, or a COD order', function () {
    $young = pswOrder(5);
    pswIntent($young, 5, 'order_y');
    $paid = pswOrder(60);
    $paid->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);
    $cod = pswOrder(600, 'cod');
    Http::fake();

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($young->fresh()->status)->toBe(Order::STATUS_PLACED)
        ->and($paid->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and($cod->fresh()->status)->toBe(Order::STATUS_PLACED);
    Http::assertNothingSent();
});

it('PSW-07: an expired-and-cancelled order holds no invoice', function () {
    $order = pswOrder(45);
    pswIntent($order, 45, 'order_noinv');
    Http::fake(['api.razorpay.com/v1/orders/order_noinv/payments' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []], 200)]);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and(Invoice::where('order_id', $order->id)->exists())->toBeFalse();
});

it('PSW-08: redact drops old payloads and keeps the derived record', function () {
    $old = PaymentEvent::create(['gateway' => 'razorpay', 'direction' => 'webhook', 'event_type' => 'payment.captured', 'gateway_payment_id' => 'pay_old', 'payload' => ['id' => 'pay_old', 'card' => ['last4' => '1111']]]);
    PaymentEvent::where('id', $old->id)->update(['created_at' => now()->subDays(200)]);
    $recent = PaymentEvent::create(['gateway' => 'razorpay', 'direction' => 'webhook', 'event_type' => 'payment.captured', 'gateway_payment_id' => 'pay_new', 'payload' => ['id' => 'pay_new']]);

    $this->artisan('payments:redact-events', ['--days' => 180])->assertSuccessful();

    expect($old->fresh()->payload)->toBeNull()
        ->and($old->fresh()->gateway_payment_id)->toBe('pay_old')
        ->and($old->fresh()->event_type)->toBe('payment.captured')
        ->and($recent->fresh()->payload)->toBe(['id' => 'pay_new']);
});
