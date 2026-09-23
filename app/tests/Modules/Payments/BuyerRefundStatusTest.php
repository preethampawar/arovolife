<?php

declare(strict_types=1);

/**
 * The buyer's refund line on the order page is read from the refund records,
 * not the order status, and never shows the gateway's error text.
 *
 * BRS-01: a processed gateway refund says processed, to the original method, with the date
 * BRS-02: a refund settled by NEFT says "by bank transfer"
 * BRS-03: a held refund waits for the returned product
 * BRS-04: a gateway failure reads as "being processed", never the error
 * BRS-05: a forfeited refund says no refund is due
 * BRS-06: an order with no refund has no line
 * BRS-07: the cancelled order's page shows the refund line to its owner
 * BRS-08: a forfeited return shows the no-refund line on the delivered order page
 * BRS-09: a cancelled paid order with no gateway payment reads pending, then paid by bank transfer once settled
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Models\User;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Services\RazorpayRefundService;
use App\Modules\Payments\Support\BuyerRefundStatus;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function brsOrder(string $status = Order::STATUS_CANCELLED, ?int $userId = null): Order
{
    $customer = Customer::create(['display_name' => 'BRS Buyer', 'email_enc' => 'brs-buyer@test.com', 'user_id' => $userId]);

    return Order::create([
        'order_no' => 'ORD-BRS-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => $status,
        'subtotal_paise' => 105800, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
        'total_paise' => 105800,
        'ship_name' => 'Asha', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDay(), 'paid_at' => now()->subDay(),
        'idempotency_key' => 'brs-'.uniqid(),
    ]);
}

/** @param  array<string, mixed>  $overrides */
function brsRefund(Order $order, array $overrides = []): RefundIntent
{
    $payment = PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_brs', 'gateway_payment_id' => 'pay_brs',
        'mode' => 'test', 'amount_paise' => $order->total_paise, 'status' => PaymentIntent::STATUS_CAPTURED,
        'captured_at' => now()->subDay(), 'idempotency_key' => 'order:'.$order->id,
    ]);

    return RefundIntent::create([
        'order_id' => $order->id, 'payment_intent_id' => $payment->id, 'gateway' => 'razorpay', 'mode' => 'test',
        'amount_paise' => 105800, 'status' => RefundIntent::STATUS_CREATED, 'reason_code' => 'order_cancelled',
        'idempotency_key' => 'refund:'.$order->id, ...$overrides,
    ]);
}

it('BRS-01: a processed gateway refund says processed, to the original method, with the date', function (): void {
    $order = brsOrder();
    brsRefund($order, ['status' => RefundIntent::STATUS_PROCESSED, 'processed_at' => '2026-09-23 10:00:00', 'settled_via' => RefundIntent::SETTLED_VIA_GATEWAY]);

    $status = BuyerRefundStatus::for($order);

    expect($status['tone'])->toBe(BuyerRefundStatus::TONE_DONE)
        ->and($status['text'])->toContain('₹1,058.00')->toContain('to your original payment method')->toContain('23 Sep 2026');
});

it('BRS-02: a refund settled by NEFT says by bank transfer', function (): void {
    $order = brsOrder(Order::STATUS_REFUNDED);
    brsRefund($order, ['status' => RefundIntent::STATUS_PROCESSED, 'processed_at' => now(), 'settled_via' => RefundIntent::SETTLED_VIA_MANUAL_NEFT]);

    expect(BuyerRefundStatus::for($order)['text'])->toContain('by bank transfer');
});

it('BRS-03: a held refund waits for the returned product', function (): void {
    $order = brsOrder(Order::STATUS_REFUND_APPROVED);
    brsRefund($order, ['held_at' => now(), 'hold_reason' => RefundIntent::HOLD_AWAITING_RETURN]);

    $status = BuyerRefundStatus::for($order);

    expect($status['tone'])->toBe(BuyerRefundStatus::TONE_PENDING)
        ->and($status['text'])->toContain('once we receive the returned product');
});

it('BRS-04: a gateway failure reads as being processed, never the error', function (): void {
    $order = brsOrder();
    brsRefund($order, ['status' => RefundIntent::STATUS_FAILED, 'failed_at' => now(), 'error_code' => 'BAD_REQUEST_ERROR', 'error_description' => 'invalid request sent']);

    $status = BuyerRefundStatus::for($order);

    expect($status['tone'])->toBe(BuyerRefundStatus::TONE_PENDING)
        ->and($status['text'])->toContain('is being processed')
        ->and(str_contains($status['text'], 'invalid request'))->toBeFalse();
});

it('BRS-05: a forfeited refund says no refund is due', function (): void {
    $order = brsOrder(Order::STATUS_REFUND_APPROVED);
    brsRefund($order, ['status' => RefundIntent::STATUS_FAILED, 'error_code' => RefundIntent::ERROR_GOODS_NOT_RETURNED]);

    expect(BuyerRefundStatus::for($order)['tone'])->toBe(BuyerRefundStatus::TONE_CLOSED);
});

it('BRS-06: an order with no refund has no line', function (): void {
    expect(BuyerRefundStatus::for(brsOrder()))->toBeNull();
});

it('BRS-07: the cancelled order page shows the refund line to its owner', function (): void {
    $user = User::create([
        'full_name' => 'BRS Owner', 'email' => 'brs-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $order = brsOrder(Order::STATUS_CANCELLED, $user->id);
    brsRefund($order, ['status' => RefundIntent::STATUS_FAILED, 'failed_at' => now()]);

    $this->actingAs($user)->get(route('orders.show', $order->order_no))
        ->assertOk()
        ->assertSee('Refund status')
        ->assertSee('refund of ₹1,058.00 is being processed', false);
});

it('BRS-08: a forfeited return shows the no-refund line on the delivered order page', function (): void {
    $user = User::create([
        'full_name' => 'BRS Owner', 'email' => 'brs-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $order = brsOrder(Order::STATUS_DELIVERED, $user->id);
    brsRefund($order, ['status' => RefundIntent::STATUS_FAILED, 'error_code' => RefundIntent::ERROR_GOODS_NOT_RETURNED]);

    $this->actingAs($user)->get(route('orders.show', $order->order_no))
        ->assertOk()
        ->assertSee('No refund is due on this order', false)
        ->assertSee('raise a grievance', false);
});

it('BRS-09: a cancelled paid order with no gateway payment reads pending, then paid by bank transfer once settled', function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Notification::fake();
    $order = brsOrder(Order::STATUS_CANCELLED);
    app(LedgerPoster::class)->transfer('Commerce', 'order.cancelled', $order->id, 'order.cancelled:'.$order->id, 'liability.customer_prepayment', 'liability.refund_payable', 105800);

    expect(BuyerRefundStatus::for($order))->toBe([
        'tone' => BuyerRefundStatus::TONE_PENDING,
        'text' => 'Refund of ₹1,058.00 is being paid to you by bank transfer by our team.',
    ]);

    $staff = User::create([
        'full_name' => 'BRS Staff', 'email' => 'brs-staff-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    app(RazorpayRefundService::class)->settleOrderManually($order, $staff->id, 'NEFT-UTR-BRS9', null);

    expect(BuyerRefundStatus::for($order->fresh()))->toBe([
        'tone' => BuyerRefundStatus::TONE_DONE,
        'text' => 'Refund of ₹1,058.00 paid to you by bank transfer.',
    ]);
});
