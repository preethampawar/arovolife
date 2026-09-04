<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\GatewayPayment;
use App\Modules\Payments\Exceptions\RazorpayApiException;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Support\PaymentSettings;
use App\Modules\Payments\Support\RazorpayPayloadScrubber;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Razorpay Standard Checkout, server side.
 *
 * `createIntent()` makes the Razorpay Order the modal pays against.
 * `syncStatus()` asks Razorpay what happened. Neither marks anything paid —
 * `PaymentConfirmationService` does that, and only from a payment fetched
 * through this client, never from anything the browser posted.
 */
final class RazorpayGateway implements PaymentGateway
{
    /** Razorpay's floor. Below it the order is not a gateway order at all. */
    public const MIN_AMOUNT_PAISE = 100;

    public function __construct(
        private readonly RazorpayClient $client,
        private readonly RazorpayPayloadScrubber $scrubber,
        private readonly PaymentSettings $settings,
    ) {}

    public function name(): string
    {
        return PaymentIntent::GATEWAY_RAZORPAY;
    }

    public function permitted(): bool
    {
        return $this->client->configured() && $this->client->modeMatchesEnvironment();
    }

    public function createIntent(Order $order, string $idempotencyKey): PaymentIntent
    {
        if ($order->total_paise < self::MIN_AMOUNT_PAISE) {
            // A zero-cash order is confirmed by PaymentConfirmationService
            // without a gateway; a 1–99 paise residue is eliminated at
            // checkout. Neither may reach here.
            throw new InvalidArgumentException("Order {$order->order_no} payable {$order->total_paise} paise is below the gateway minimum.");
        }

        $intent = PaymentIntent::where('idempotency_key', $idempotencyKey)->first();
        if ($intent !== null && $intent->gateway_order_id !== null) {
            return $intent;
        }

        // Row first, gateway second: if the gateway call succeeds and the row
        // write fails we would have an orphan Razorpay order nobody can find.
        // With the row first, a retry finds it by idempotency key, and the
        // receipt lookup below finds the Razorpay order it may have created.
        $intent ??= PaymentIntent::create([
            'order_id' => $order->id,
            'gateway' => $this->name(),
            'mode' => $this->client->mode(),
            'amount_paise' => $order->total_paise,
            'status' => PaymentIntent::STATUS_CREATED,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => Carbon::now()->addMinutes($this->settings->unpaidExpiryMinutes()),
        ]);

        $gatewayOrder = $this->client->fetchOrderByReceipt($order->order_no, $order->id, $intent->id);

        if ($gatewayOrder === null) {
            try {
                $gatewayOrder = $this->client->createOrder(
                    amountPaise: $order->total_paise,
                    receipt: $order->order_no,
                    // Our own reference only. No name, no phone, no ADN —
                    // the Privacy Policy's processor table lists what the
                    // gateway receives, and it is not on it.
                    notes: ['arovolife_order_id' => (string) $order->id, 'arovolife_order_no' => $order->order_no],
                    refundSpeed: $this->settings->refundSpeed(),
                    orderId: $order->id,
                    intentId: $intent->id,
                );
            } catch (RazorpayApiException $e) {
                if (! $e->isDuplicateReceipt()) {
                    throw $e;
                }
                // Receipt validation is on and the order already exists —
                // a create that succeeded but never reached us. Use it.
                $gatewayOrder = $this->client->fetchOrderByReceipt($order->order_no, $order->id, $intent->id);
                if ($gatewayOrder === null) {
                    throw $e;
                }
            }
        }

        if ((int) ($gatewayOrder['amount'] ?? -1) !== $order->total_paise) {
            // A stale Razorpay order for a receipt whose amount has since
            // changed can never be paid against this order.
            throw new RazorpayApiException(sprintf(
                'Razorpay order %s carries %d paise, order %s is %d paise',
                (string) ($gatewayOrder['id'] ?? '?'), (int) ($gatewayOrder['amount'] ?? -1), $order->order_no, $order->total_paise,
            ));
        }

        $intent->update([
            'gateway_order_id' => (string) $gatewayOrder['id'],
            'gateway_intent_id' => (string) $gatewayOrder['id'],
            'raw_payload' => $this->scrubber->scrub($gatewayOrder),
        ]);

        return $intent->fresh();
    }

    public function syncStatus(PaymentIntent $intent): ?GatewayPayment
    {
        if ($intent->gateway_order_id === null) {
            return null;
        }

        $attempts = $this->client->fetchPaymentsForOrder($intent->gateway_order_id, $intent->order_id, $intent->id);

        // Prefer a captured attempt, then an authorised one, then the most
        // recent failure — so a failed retry never hides a success.
        $chosen = null;
        foreach ($attempts as $attempt) {
            $status = (string) ($attempt['status'] ?? '');
            if ($status === 'captured') {
                $chosen = $attempt;
                break;
            }
            if ($status === 'authorized' && ($chosen === null || ($chosen['status'] ?? '') !== 'authorized')) {
                $chosen = $attempt;
            } elseif ($chosen === null) {
                $chosen = $attempt;
            }
        }

        $update = [
            'attempt_count' => count($attempts),
            'last_synced_at' => Carbon::now(),
        ];

        if ($chosen === null) {
            $intent->update($update);

            return null;
        }

        $payment = GatewayPayment::fromEntity($chosen, $this->scrubber->scrub($chosen));

        $update['gateway_payment_id'] = $payment->id;
        $update['method'] = $payment->method;
        $update['raw_payload'] = $payment->scrubbed;
        $update['error_code'] = $payment->errorCode;
        $update['error_description'] = $payment->errorDescription !== null ? mb_substr($payment->errorDescription, 0, 255) : null;
        if ($payment->status === 'authorized' && $intent->authorised_at === null) {
            $update['authorised_at'] = Carbon::now();
        }

        $intent->update($update);

        return $payment;
    }

    /**
     * Fetch one payment by id, for confirmation. The caller must still
     * assert it belongs to the intent — this only proves what Razorpay holds.
     */
    public function fetchPayment(PaymentIntent $intent, string $paymentId): GatewayPayment
    {
        $entity = $this->client->fetchPayment($paymentId, $intent->order_id, $intent->id);

        return GatewayPayment::fromEntity($entity, $this->scrubber->scrub($entity));
    }

    public function publicKeyId(): string
    {
        return $this->client->keyId();
    }
}
