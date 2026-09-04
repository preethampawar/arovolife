<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\GatewayPayment;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Support\PaymentSettings;
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
final class StubGateway implements PaymentGateway
{
    public function __construct(
        private readonly OrderStateMachine $orderStateMachine,
        private readonly PaymentSettings $settings,
    ) {}

    public function name(): string
    {
        return PaymentIntent::GATEWAY_STUB;
    }

    /**
     * Whether the stub may run in the current environment.
     *
     * `arovolife.payments.stub.allowed_environments` (PAYMENT_STUB_ENVIRONMENTS)
     * widens the stub to UAT builds such as staging. Production is refused
     * whatever the list says — the allow-list exists to let the client test
     * checkout, not to give an operator a way to switch the guard off.
     */
    public function permitted(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        // Second, independent guard (compliance C-2): the moment Razorpay is
        // switched on, or a live key is present at all, the stub is out of
        // the picture whatever the environment allow-list says. There is no
        // fallback from the real gateway to the free one.
        if ($this->settings->razorpayEnabled()) {
            return false;
        }
        if (str_starts_with((string) config('arovolife.payments.razorpay.key_id', ''), 'rzp_live_')) {
            return false;
        }

        /** @var list<string> $allowed */
        $allowed = config('arovolife.payments.stub.allowed_environments', ['local', 'testing']);

        return app()->environment($allowed);
    }

    /**
     * @throws RuntimeException when used in an environment not on the allow-list
     */
    private function assertNotProduction(): void
    {
        if ($this->permitted()) {
            return;
        }

        throw new RuntimeException(
            'StubGateway captures payment without collecting money and must never run outside '
            .'local/testing (or an environment named in PAYMENT_STUB_ENVIRONMENTS). '
            .'Configure a real payment gateway before taking orders. '
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
            'gateway' => $this->name(),
            'gateway_intent_id' => 'STUB-'.strtoupper(Str::random(12)),
            'mode' => 'test',
            'amount_paise' => $order->total_paise,
            'status' => PaymentIntent::STATUS_CREATED,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /** The stub has nothing to ask: whatever it captured, it captured. */
    public function syncStatus(PaymentIntent $intent): ?GatewayPayment
    {
        return null;
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
            'confirmed_via' => PaymentIntent::CONFIRMED_VIA_STUB,
        ]);

        $this->orderStateMachine->markPaid($intent->order);

        return $intent->fresh();
    }
}
