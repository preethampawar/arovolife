<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\PaymentsUnreconciledProvider;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(PaymentsUnreconciledProvider::class);
});

it('counts a captured intent whose order is still placed', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_PLACED]);
    $intent = Helpers::paymentIntent($order->id, [
        'status' => PaymentIntent::STATUS_CAPTURED,
        'captured_at' => now()->subHours(2),
    ]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($intent->id);
});

it('ignores a captured intent whose order was marked paid', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_PAID]);
    Helpers::paymentIntent($order->id, ['status' => PaymentIntent::STATUS_CAPTURED]);

    expect($this->provider->count())->toBe(0);
});

it('ignores an intent that is not captured', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_PLACED]);
    Helpers::paymentIntent($order->id, ['status' => PaymentIntent::STATUS_AUTHORISED]);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed intent', function (): void {
    $order = Helpers::order(['status' => Order::STATUS_PLACED]);
    $intent = Helpers::paymentIntent($order->id, ['status' => PaymentIntent::STATUS_CAPTURED]);

    ActionCenterSnooze::create([
        'action_key' => 'payments.unreconciled',
        'subject_type' => 'payment_intent',
        'subject_id' => $intent->id,
        'snoozed_until' => now()->addDay(),
        'reason' => 'Gateway support confirmed the mismatch is expected.',
    ]);

    expect($this->provider->count())->toBe(0);
});
