<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phase 2 stub gateway — auto-captures on create. Real Razorpay integration
 * lives behind the same interface in Phase 3.
 */
final class StubGateway
{
    public function __construct(private readonly OrderStateMachine $orderStateMachine) {}

    public function createIntent(Order $order, string $idempotencyKey): PaymentIntent
    {
        $existing = PaymentIntent::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        return PaymentIntent::create([
            'order_id' => $order->id,
            'gateway' => 'stub',
            'gateway_intent_id' => 'STUB-'.strtoupper(Str::random(12)),
            'amount_paise' => $order->total_paise,
            'status' => PaymentIntent::STATUS_CREATED,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function capture(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->status === PaymentIntent::STATUS_CAPTURED) {
            return $intent;
        }

        $intent->update([
            'status' => PaymentIntent::STATUS_CAPTURED,
            'captured_at' => Carbon::now(),
        ]);

        $this->orderStateMachine->markPaid($intent->order);

        return $intent->fresh();
    }
}
