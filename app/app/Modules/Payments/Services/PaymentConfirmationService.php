<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Data\GatewayPayment;
use App\Modules\Payments\Exceptions\PaymentMismatchException;
use App\Modules\Payments\Exceptions\SignatureVerificationException;
use App\Modules\Payments\Jobs\SendRazorpayRefundJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Tax\Services\InvoiceGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The one place an order becomes paid.
 *
 * Every path in — the browser callback, the webhook, the reconciler, an
 * admin sync, the stub, a zero-cash order — ends in `settle()`, which is the
 * only caller of `OrderStateMachine::markPaid()` in the codebase (enforced
 * by `tests/Architecture/MarkPaidChokePointTest.php`). `markPaid()` accrues
 * BV and fires the compensation engines, so anything that reaches it without
 * verified consideration manufactures commission liability (hard rule 2).
 *
 * What "verified" means here:
 *   - the payment was FETCHED from the gateway's API, never taken from a
 *     browser post or a webhook body;
 *   - it belongs to this intent's gateway order, is captured, in INR, for
 *     exactly the order's payable, with nothing refunded;
 *   - the order is still waiting for payment, checked under a row lock so
 *     two confirmations (callback + webhook) cannot both fire, and the expiry
 *     sweeper cannot cancel an order in the same instant it is paid.
 *
 * The GST invoice is issued here too, after `markPaid()` — an invoice number
 * is consecutive and must never be burned on an order that expires unpaid —
 * but AFTER the confirmation commits, not inside it: a failed invoice must
 * never roll back a confirmation whose money is already captured. A paid
 * order left without an invoice surfaces on Admin → Payments and in the
 * `payments:reconcile` tally, where finance issues it by hand.
 */
final class PaymentConfirmationService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OrderStateMachine $orders,
        private readonly InvoiceGenerator $invoices,
        private readonly RazorpayGateway $razorpay,
        private readonly RazorpayClient $client,
        private readonly WalletService $wallet,
        private readonly LedgerPoster $ledger,
    ) {}

    /**
     * The browser handed back what Razorpay's modal returned. Verify the
     * signature, bind it to the intent, then confirm from the API — the
     * signature proves Razorpay issued the pair, not that it pays this order.
     *
     * @throws SignatureVerificationException
     * @throws PaymentMismatchException
     */
    public function confirmFromCallback(PaymentIntent $intent, string $gatewayOrderId, string $paymentId, string $signature): ConfirmationResult
    {
        $verified = $this->client->verifyCheckoutSignature($gatewayOrderId, $paymentId, $signature);

        $this->event($intent, PaymentEvent::DIRECTION_CALLBACK, 'checkout.callback', [
            'razorpay_order_id' => $gatewayOrderId,
            'razorpay_payment_id' => $paymentId,
        ], signatureVerified: $verified, gatewayPaymentId: $paymentId,
            error: $verified ? null : 'signature did not verify');

        if (! $verified) {
            throw new SignatureVerificationException('Payment callback signature did not verify.');
        }

        if ($gatewayOrderId !== $intent->gateway_order_id) {
            $this->refuse($intent, 'callback order '.$gatewayOrderId.' is not this intent\'s order '.$intent->gateway_order_id);
        }

        $intent->update(['signature_verified_at' => Carbon::now()]);

        $payment = $this->razorpay->fetchPayment($intent, $paymentId);

        return $this->confirmPayment($intent, $payment, PaymentIntent::CONFIRMED_VIA_CALLBACK);
    }

    /**
     * Ask the gateway what happened and confirm if it has been captured.
     * Used by the status poll, the reconciler, the expiry sweeper and the
     * admin "sync" action.
     */
    public function syncAndConfirm(PaymentIntent $intent, string $via, ?int $actorUserId = null): ConfirmationResult
    {
        if ($intent->status === PaymentIntent::STATUS_CAPTURED) {
            return new ConfirmationResult(ConfirmationResult::ALREADY_CONFIRMED);
        }

        $payment = $this->razorpay->syncStatus($intent);
        if ($payment === null) {
            return new ConfirmationResult(ConfirmationResult::PENDING, 'no payment attempt yet');
        }

        return $this->confirmPayment($intent, $payment, $via, $actorUserId);
    }

    /**
     * Confirm from a payment fetched from the gateway. Idempotent.
     *
     * @throws PaymentMismatchException when the payment cannot be this order's
     */
    public function confirmPayment(PaymentIntent $intent, GatewayPayment $payment, string $via, ?int $actorUserId = null): ConfirmationResult
    {
        if ($payment->orderId !== $intent->gateway_order_id) {
            $this->refuse($intent, "payment {$payment->id} belongs to gateway order {$payment->orderId}, not {$intent->gateway_order_id}");
        }

        if ($payment->isFailed()) {
            $intent->update([
                'error_code' => $payment->errorCode,
                'error_description' => $payment->errorDescription !== null ? mb_substr($payment->errorDescription, 0, 255) : null,
            ]);

            return new ConfirmationResult(ConfirmationResult::FAILED, (string) $payment->errorCode);
        }

        if (! $payment->isCaptured()) {
            // Authorised but not captured: with automatic capture this is a
            // window of seconds, and an uncaptured authorisation is refunded
            // by Razorpay itself. Never paid on the strength of it.
            return new ConfirmationResult(ConfirmationResult::PENDING, 'payment '.$payment->id.' is '.$payment->status);
        }

        if ($payment->currency !== 'INR') {
            $this->refuse($intent, "payment {$payment->id} is in {$payment->currency}");
        }

        if ($payment->amountRefundedPaise !== 0) {
            $this->refuse($intent, "payment {$payment->id} already has {$payment->amountRefundedPaise} paise refunded");
        }

        try {
            return $this->confirmLocked($intent, $payment, $via, $actorUserId);
        } catch (PaymentMismatchException $e) {
            // The refusal inside the transaction rolled its own evidence row
            // back with everything else; write it again now that nothing can.
            $this->event($intent, PaymentEvent::DIRECTION_SYSTEM, 'confirmation.refused', [], error: $e->getMessage());

            throw $e;
        }
    }

    /** @throws PaymentMismatchException */
    private function confirmLocked(PaymentIntent $intent, GatewayPayment $payment, string $via, ?int $actorUserId): ConfirmationResult
    {
        return $this->db->transaction(function () use ($intent, $payment, $via, $actorUserId): ConfirmationResult {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::lockForUpdate()->findOrFail($intent->id);
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($locked->order_id);

            if ($locked->status === PaymentIntent::STATUS_CAPTURED) {
                return new ConfirmationResult(ConfirmationResult::ALREADY_CONFIRMED);
            }

            if ($payment->amountPaise !== $order->total_paise) {
                $this->refuse($locked, "payment {$payment->id} is {$payment->amountPaise} paise, order {$order->order_no} payable is {$order->total_paise}");
            }

            if ($order->status !== Order::STATUS_PLACED) {
                if ($order->status === Order::STATUS_CANCELLED) {
                    return $this->lateCapture($locked, $order, $payment, $via);
                }
                // Paid through another intent, or further along already:
                // nothing to do, and never a second markPaid.
                if ($order->paid_at !== null) {
                    return new ConfirmationResult(ConfirmationResult::ALREADY_CONFIRMED, 'order already paid');
                }
                $this->refuse($locked, "order {$order->order_no} is {$order->status}");
            }

            $confirmingEvent = $this->event($locked, PaymentEvent::DIRECTION_SYSTEM, 'payment.confirmed', $payment->scrubbed,
                signatureVerified: $via === PaymentIntent::CONFIRMED_VIA_CALLBACK, gatewayPaymentId: $payment->id);

            $locked->update([
                'status' => PaymentIntent::STATUS_CAPTURED,
                'captured_at' => Carbon::now(),
                'gateway_payment_id' => $payment->id,
                'method' => $payment->method,
                'confirmed_via' => $via,
                'raw_payload' => $payment->scrubbed,
                'error_code' => null,
                'error_description' => null,
            ]);

            $this->settle($order, $actorUserId, [
                'payment_intent_id' => $locked->id,
                'gateway' => $locked->gateway,
                'gateway_order_id' => $locked->gateway_order_id,
                'gateway_payment_id' => $payment->id,
                'mode' => $locked->mode,
                'confirmed_via' => $via,
                'confirming_event_id' => $confirmingEvent?->id,
            ]);

            $intent->setRawAttributes($locked->fresh()->getAttributes(), true);

            return new ConfirmationResult(ConfirmationResult::CONFIRMED);
        });
    }

    /**
     * An order whose payable is exactly zero: the whole price was settled in
     * redeemed points and repurchase credit — entitlements the company
     * already owed the buyer. Goods are supplied and an invoice raised, so the
     * sale is real and BV is due; what must be evidenced is the consideration,
     * re-derived here from the ledgers, never from the request.
     */
    public function confirmZeroCash(Order $order, ?int $actorUserId = null): ConfirmationResult
    {
        return $this->db->transaction(function () use ($order, $actorUserId): ConfirmationResult {
            /** @var Order $locked */
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if ($locked->paid_at !== null) {
                return new ConfirmationResult(ConfirmationResult::ALREADY_CONFIRMED);
            }
            if ($locked->status !== Order::STATUS_PLACED) {
                throw new RuntimeException("Cannot confirm zero-cash order {$locked->order_no} in status {$locked->status}");
            }
            if ($locked->total_paise !== 0) {
                throw new RuntimeException("Order {$locked->order_no} has {$locked->total_paise} paise payable; it is not a zero-cash order");
            }

            $points = (int) ($locked->redeem_points_paise ?? 0);
            $credit = $this->wallet->repurchaseCreditAppliedToOrder($locked->id);
            $discount = (int) $locked->discount_paise;
            $gross = (int) $locked->subtotal_paise + (int) $locked->shipping_paise;

            if ($points + $credit + $discount !== $gross) {
                throw new RuntimeException(sprintf(
                    'Order %s: points %d + credit %d + discount %d do not cover gross %d',
                    $locked->order_no, $points, $credit, $discount, $gross,
                ));
            }

            $event = PaymentEvent::create([
                'order_id' => $locked->id,
                'gateway' => 'none',
                'direction' => PaymentEvent::DIRECTION_SYSTEM,
                'event_type' => 'zero_cash.confirmed',
                'signature_verified' => false,
                'payload' => ['redeem_points_paise' => $points, 'repurchase_credit_paise' => $credit, 'discount_paise' => $discount, 'gross_paise' => $gross],
            ]);

            $this->settle($locked, $actorUserId, [
                'settlement' => 'zero_cash',
                'redeem_points_paise' => $points,
                'repurchase_credit_paise' => $credit,
                'discount_paise' => $discount,
                'confirmed_via' => PaymentIntent::CONFIRMED_VIA_ZERO_CASH,
                'confirming_event_id' => $event->id,
            ]);

            $order->setRawAttributes($locked->fresh()->getAttributes(), true);

            return new ConfirmationResult(ConfirmationResult::CONFIRMED);
        });
    }

    /** The development stub: no gateway, no money, refused outside its allow-list. */
    public function confirmStub(PaymentIntent $intent): ConfirmationResult
    {
        return $this->db->transaction(function () use ($intent): ConfirmationResult {
            /** @var PaymentIntent $locked */
            $locked = PaymentIntent::lockForUpdate()->findOrFail($intent->id);
            if ($locked->status === PaymentIntent::STATUS_CAPTURED) {
                return new ConfirmationResult(ConfirmationResult::ALREADY_CONFIRMED);
            }
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($locked->order_id);

            $locked->update([
                'status' => PaymentIntent::STATUS_CAPTURED,
                'captured_at' => Carbon::now(),
                'confirmed_via' => PaymentIntent::CONFIRMED_VIA_STUB,
            ]);

            $this->settle($order, null, [
                'payment_intent_id' => $locked->id,
                'gateway' => $locked->gateway,
                'confirmed_via' => PaymentIntent::CONFIRMED_VIA_STUB,
            ]);

            $intent->setRawAttributes($locked->fresh()->getAttributes(), true);

            return new ConfirmationResult(ConfirmationResult::CONFIRMED);
        });
    }

    /**
     * markPaid inside the caller's transaction; the invoice once it commits.
     *
     * The invoice is issued only for an order that is already paid, so a
     * consecutive invoice number is never burned on one that expires unpaid.
     * It is deliberately NOT inside the transaction: a failure there would
     * roll the confirmation back with the money already captured at the
     * gateway, and a persistent failure (a sequence lock, a template bug)
     * would leave every paid order unpaid until it expired and was refunded.
     * Instead the failure is logged critical for ops to regenerate — the sale
     * stands, the invoice follows.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function settle(Order $order, ?int $actorUserId, array $evidence): void
    {
        $this->orders->markPaid($order, $actorUserId, $evidence);

        $this->db->afterCommit(function () use ($order): void {
            try {
                $order->load('items');
                $this->invoices->generate($order);
            } catch (\Throwable $e) {
                Log::critical('Invoice generation failed for a paid order — regenerate manually', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * Money arrived for an order the expiry sweeper (or an admin) had already
     * cancelled — the buyer completed the modal a moment after the cut-off.
     * The books must say the cash is here and owed back, and the buyer must
     * get it back without anyone noticing first (product-owner decision
     * 2026-09-04: auto-refund in full, with an alert).
     */
    private function lateCapture(PaymentIntent $intent, Order $order, GatewayPayment $payment, string $via): ConfirmationResult
    {
        $intent->update([
            'gateway_payment_id' => $payment->id,
            'method' => $payment->method,
            'raw_payload' => $payment->scrubbed,
            'cancel_reason' => $intent->cancel_reason ?? 'late_capture',
        ]);

        // Dr gateway cash / Cr refund_payable. cancel() already reversed the
        // placement entry as "nothing collected"; this says: it was, after
        // all, and it is owed back. Nets to zero once the refund settles.
        $this->ledger->transfer(
            sourceModule: 'Payments',
            sourceType: 'order.late_capture',
            sourceId: $order->id,
            idempotencyKey: "order.late_capture:{$order->id}",
            debitAccount: 'asset.cash.gateway.razorpay',
            creditAccount: 'liability.refund_payable',
            amountPaise: $payment->amountPaise,
            memo: "Late capture on cancelled order {$order->order_no}",
        );

        $refund = RefundIntent::firstOrCreate(
            ['idempotency_key' => "order.late_capture:{$order->id}"],
            [
                'order_id' => $order->id,
                'payment_intent_id' => $intent->id,
                'gateway' => $intent->gateway,
                'mode' => $intent->mode,
                'amount_paise' => $payment->amountPaise,
                'status' => RefundIntent::STATUS_CREATED,
                'reason_code' => 'late_capture',
            ],
        );

        if (! $refund->wasRecentlyCreated) {
            // A redelivered event or a later sync for the same capture: the
            // books and the refund already exist; do not alert twice.
            return new ConfirmationResult(ConfirmationResult::LATE_CAPTURE, 'refund '.$refund->id.' already queued');
        }

        $this->event($intent, PaymentEvent::DIRECTION_SYSTEM, 'payment.late_capture', $payment->scrubbed,
            signatureVerified: $via === PaymentIntent::CONFIRMED_VIA_CALLBACK, gatewayPaymentId: $payment->id,
            error: 'captured after cancellation; refund '.$refund->id.' created');

        AuditLog::create([
            'actor_id' => null,
            'action' => 'payment.late_capture',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'details' => [
                'order_no' => $order->order_no,
                'payment_intent_id' => $intent->id,
                'gateway_payment_id' => $payment->id,
                'amount_paise' => $payment->amountPaise,
                'refund_intent_id' => $refund->id,
            ],
        ]);

        Log::critical('Payment captured after the order was cancelled — full refund queued', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'gateway_payment_id' => $payment->id,
            'amount_paise' => $payment->amountPaise,
            'refund_intent_id' => $refund->id,
        ]);

        // Sent once this transaction commits; the reconciler re-queues any
        // intent that was never sent, so the books stay right either way.
        SendRazorpayRefundJob::dispatch($refund->id)->afterCommit();

        return new ConfirmationResult(ConfirmationResult::LATE_CAPTURE, 'refund '.$refund->id.' queued');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(PaymentIntent $intent, string $direction, string $type, array $payload, bool $signatureVerified = false, ?string $gatewayPaymentId = null, ?string $error = null): ?PaymentEvent
    {
        try {
            return PaymentEvent::create([
                'order_id' => $intent->order_id,
                'payment_intent_id' => $intent->id,
                'gateway' => $intent->gateway,
                'direction' => $direction,
                'event_type' => $type,
                'gateway_payment_id' => $gatewayPaymentId,
                'signature_verified' => $signatureVerified,
                'payload' => $payload,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            Log::channel('payments')->error('payment_events write failed', ['event_type' => $type, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Record why, alert, and stop. Never marks anything. */
    private function refuse(PaymentIntent $intent, string $reason): never
    {
        $this->event($intent, PaymentEvent::DIRECTION_SYSTEM, 'confirmation.refused', [], error: $reason);

        Log::critical('Payment confirmation refused', [
            'order_id' => $intent->order_id,
            'payment_intent_id' => $intent->id,
            'reason' => $reason,
        ]);

        throw new PaymentMismatchException($reason);
    }
}
