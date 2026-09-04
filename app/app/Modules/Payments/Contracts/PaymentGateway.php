<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Data\GatewayPayment;
use App\Modules\Payments\Models\PaymentIntent;

/**
 * A payment gateway the checkout can hand an online order to.
 *
 * Deliberately narrow. Neither method marks anything paid: that is the job
 * of `PaymentConfirmationService`, the single caller of
 * `OrderStateMachine::markPaid()`. A gateway only creates the intent the
 * buyer pays against and reports what the gateway says about it.
 */
interface PaymentGateway
{
    /** Stable identifier persisted on `payment_intents.gateway`. */
    public function name(): string;

    /** Whether this gateway may take an order in the current environment. */
    public function permitted(): bool;

    /**
     * Create (or return the existing) intent for an order. Idempotent on the
     * key: a double-submit or a retried request must never create a second
     * gateway order the buyer could pay twice.
     */
    public function createIntent(Order $order, string $idempotencyKey): PaymentIntent;

    /**
     * Ask the gateway what happened to this intent. Refreshes the intent's
     * gateway facts (payment id, method, last_synced_at) and returns the
     * gateway's view of the payment, or null when nothing has been paid.
     * Never changes the intent's status to captured — see the class docblock.
     */
    public function syncStatus(PaymentIntent $intent): ?GatewayPayment;
}
