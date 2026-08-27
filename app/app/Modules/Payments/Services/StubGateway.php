<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Development stub gateway — captures without collecting any money.
 *
 * **It refuses to run outside local and testing.** `capture()` calls
 * `markPaid()`, which sets `paid_at`, accrues BV, fires the compensation
 * engines and burns a consecutive GST invoice number. In production that is
 * not a stub, it is a way for anyone with a browser to manufacture commission
 * liability at zero cost — and a commission behind a sale where no
 * consideration passed breaks hard rule 2 whatever the ledger says.
 *
 * The guard lives here rather than in the caller because this class is the
 * thing that must never be reachable in production, and a check in the
 * checkout controller only protects the one caller somebody remembered.
 *
 * Replacing it: implement the same two methods against the real gateway, with
 * `markPaid()` reachable only from a server-side webhook whose signature has
 * been verified. Do not make the webhook trust anything the browser sends.
 */
final class StubGateway
{
    public function __construct(private readonly OrderStateMachine $orderStateMachine) {}

    /**
     * @throws RuntimeException when instantiated anywhere but local/testing
     */
    private function assertNotProduction(): void
    {
        if (app()->environment('local', 'testing')) {
            return;
        }

        throw new RuntimeException(
            'StubGateway captures payment without collecting money and must never run outside '
            .'local/testing. Configure a real payment gateway before taking orders. '
            .'(Security audit T-6.1 finding C-1.)'
        );
    }

    public function createIntent(Order $order, string $idempotencyKey): PaymentIntent
    {
        $this->assertNotProduction();

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
        $this->assertNotProduction();

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
