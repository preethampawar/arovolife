<?php

declare(strict_types=1);

/**
 * The buyer is told when their refund is paid — once, only after the
 * settlement commits, and never on an idempotent replay.
 *
 * RSN-01: a gateway settlement notifies the buyer once with the amount, method and gateway refund id
 * RSN-02: replaying settleProcessed on an already-processed refund sends nothing
 * RSN-03: a manual NEFT settlement notifies with bank transfer and the UTR
 * RSN-04: a refused settlement (rolled back) sends nothing
 * RSN-05: an order settled by hand with nothing owed sends nothing
 * RSN-06: the mail renders the amount, order number, method and reference
 * RSN-07: the greeting uses the account holder's name, falling back to the ship-to name
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Notifications\RefundSettledNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Services\RazorpayRefundService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(RefreshDatabase::class);

function rsnStaff(): int
{
    return User::create([
        'full_name' => 'RSN Staff', 'email' => 'rsn-staff-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ])->id;
}

function rsnOrder(): Order
{
    $customer = Customer::create(['display_name' => 'RSN Buyer', 'email_enc' => 'rsn-buyer@test.com']);

    return Order::create([
        'order_no' => 'ORD-RSN-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_REFUND_APPROVED,
        'subtotal_paise' => 118000, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
        'total_paise' => 118000,
        'ship_name' => 'Asha', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDays(10), 'paid_at' => now()->subDays(10),
        'idempotency_key' => 'rsn-'.uniqid(),
    ]);
}

function rsnRefund(Order $order, bool $held = false): RefundIntent
{
    $payment = PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_rsn', 'gateway_payment_id' => 'pay_rsn',
        'mode' => 'test', 'amount_paise' => $order->total_paise, 'status' => PaymentIntent::STATUS_CAPTURED,
        'captured_at' => now()->subDays(10), 'idempotency_key' => 'order:'.$order->id,
    ]);
    app(LedgerPoster::class)->transfer('Test', 'test.owed', $order->id, 'test.owed:'.$order->id, 'revenue.sales', 'liability.refund_payable', 118000);

    return RefundIntent::create([
        'order_id' => $order->id, 'payment_intent_id' => $payment->id, 'gateway' => 'razorpay', 'mode' => 'test',
        'amount_paise' => 118000, 'status' => RefundIntent::STATUS_CREATED, 'reason_code' => 'cooling_off',
        'idempotency_key' => 'refund:'.$order->id,
        'held_at' => $held ? now() : null, 'hold_reason' => $held ? RefundIntent::HOLD_AWAITING_RETURN : null,
    ]);
}

beforeEach(function () {
    /** @var TestCase $this */
    $this->seed(LedgerAccountSeeder::class);
    Notification::fake();
});

it('RSN-01: a gateway settlement notifies the buyer once with the amount, method and gateway refund id', function () {
    $order = rsnOrder();
    $refund = rsnRefund($order);
    $refund->update(['gateway_refund_id' => 'rfnd_rsn']);

    expect(app(RazorpayRefundService::class)->settleProcessed($refund, 'webhook'))->toBeTrue();

    Notification::assertSentOnDemandTimes(RefundSettledNotification::class, 1);
    Notification::assertSentOnDemand(RefundSettledNotification::class, function (RefundSettledNotification $n, array $channels, AnonymousNotifiable $notifiable) use ($order) {
        return $notifiable->routes['mail'] === 'rsn-buyer@test.com'
            && $n->orderNo === $order->order_no
            && $n->buyerName === 'RSN Buyer'
            && $n->amountPaise === 118000
            && $n->method === RefundSettledNotification::METHOD_GATEWAY
            && $n->reference === 'rfnd_rsn';
    });
});

it('RSN-02: replaying settleProcessed on an already-processed refund sends nothing', function () {
    $refund = rsnRefund(rsnOrder());
    $refund->update(['status' => RefundIntent::STATUS_PROCESSED, 'processed_at' => now()]);

    expect(app(RazorpayRefundService::class)->settleProcessed($refund, 'webhook'))->toBeFalse();

    Notification::assertNothingSent();
});

it('RSN-03: a manual NEFT settlement notifies with bank transfer and the UTR', function () {
    $refund = rsnRefund(rsnOrder());
    $refund->update(['status' => RefundIntent::STATUS_FAILED, 'error_code' => 'BAD_REQUEST_ERROR']);

    app(RazorpayRefundService::class)->settleManually($refund, rsnStaff(), 'NEFT-UTR-RSN', null);

    Notification::assertSentOnDemandTimes(RefundSettledNotification::class, 1);
    Notification::assertSentOnDemand(RefundSettledNotification::class, fn (RefundSettledNotification $n) => $n->method === RefundSettledNotification::METHOD_BANK_TRANSFER
        && $n->reference === 'NEFT-UTR-RSN'
        && $n->amountPaise === 118000);
});

it('RSN-04: a refused settlement sends nothing', function () {
    $refund = rsnRefund(rsnOrder(), held: true);

    expect(fn () => app(RazorpayRefundService::class)->settleManually($refund, rsnStaff(), 'NEFT-UTR-HELD', null))->toThrow(RuntimeException::class);

    Notification::assertNothingSent();
});

it('RSN-05: an order settled by hand with nothing owed sends nothing', function () {
    $order = rsnOrder();

    app(RazorpayRefundService::class)->settleOrderManually($order, rsnStaff(), 'NEFT-UTR-ZERO', null);

    expect($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
    Notification::assertNothingSent();
});

it('RSN-06: the mail renders the amount, order number, method and reference', function () {
    $notification = new RefundSettledNotification('ORD-RSN-777', 'Asha', 118050, RefundSettledNotification::METHOD_BANK_TRANSFER, 'NEFT-UTR-777', '23 Sep 2026');

    $html = (string) $notification->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('ORD-RSN-777')
        ->toContain('₹1,180.50')
        ->toContain('by bank transfer (NEFT)')
        ->toContain('NEFT-UTR-777')
        ->toContain('23 Sep 2026')
        ->toContain('/orders/ORD-RSN-777');
    expect(str_contains($html, 'earn'))->toBeFalse();
});

it("RSN-07: the greeting uses the account holder's name, falling back to the ship-to name", function () {
    $owner = User::create([
        'full_name' => 'Kavya Rao', 'email' => 'rsn-owner-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $linked = rsnOrder();
    Customer::whereKey($linked->customer_id)->update(['user_id' => $owner->id]);
    $unlinked = rsnOrder();
    $guest = rsnOrder();
    Customer::whereKey($guest->customer_id)->update(['display_name' => 'Guest']);

    foreach ([$linked, $unlinked, $guest] as $i => $order) {
        $refund = rsnRefund($order);
        $refund->update(['gateway_refund_id' => 'rfnd_rsn7_'.$i]);
        app(RazorpayRefundService::class)->settleProcessed($refund, 'webhook');
    }

    // The linked order is sent to the account; the others on demand to the customer's email.
    Notification::assertSentTo($owner, RefundSettledNotification::class, fn (RefundSettledNotification $n) => $n->orderNo === $linked->order_no && $n->buyerName === 'Kavya Rao');
    Notification::assertSentOnDemand(RefundSettledNotification::class, fn (RefundSettledNotification $n) => $n->orderNo === $unlinked->order_no && $n->buyerName === 'RSN Buyer');
    Notification::assertSentOnDemand(RefundSettledNotification::class, fn (RefundSettledNotification $n) => $n->orderNo === $guest->order_no && $n->buyerName === 'Asha');
});
