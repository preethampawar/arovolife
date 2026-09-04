<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Exceptions\PaymentMismatchException;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Services\RazorpayGateway;
use App\Modules\Payments\Services\RazorpayRefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Apply one stored webhook event.
 *
 * The event body is treated as a hint about WHICH payment to look at, never
 * as the truth about it: every payment event ends in a fresh fetch from the
 * API and the confirmation service's checks. "Captured" is sticky — a late
 * `payment.failed` for an earlier attempt never touches an intent that is
 * already captured, and an event for an intent we do not know is recorded
 * and left alone.
 *
 * On the `default` queue (database driver, ADR-0011): a dropped job here is
 * an order that stays placed until the reconciler finds it, not a lost credit.
 */
final class ProcessRazorpayWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly int $eventId)
    {
        $this->onQueue('default');
    }

    public function handle(PaymentConfirmationService $confirmation, RazorpayGateway $gateway, RazorpayRefundService $refunds): void
    {
        $event = PaymentEvent::find($this->eventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        try {
            $outcome = match (true) {
                str_starts_with($event->event_type, 'payment.') , $event->event_type === 'order.paid' => $this->applyPaymentEvent($event, $confirmation, $gateway),
                str_starts_with($event->event_type, 'refund.') => $refunds->applyWebhook($event),
                default => 'ignored: '.$event->event_type,
            };

            $event->update(['processed_at' => Carbon::now(), 'processing_error' => null]);
            Log::channel('payments')->info('razorpay webhook applied', ['event_id' => $event->id, 'event' => $event->event_type, 'outcome' => $outcome]);
        } catch (PaymentMismatchException $e) {
            // Alerted and recorded by the service; retrying will not change
            // the gateway's answer.
            $event->update(['processed_at' => Carbon::now(), 'processing_error' => 'refused: '.$e->getMessage()]);
        } catch (Throwable $e) {
            $event->update(['processing_error' => $e->getMessage()]);

            throw $e; // let the queue retry with backoff
        }
    }

    private function applyPaymentEvent(PaymentEvent $event, PaymentConfirmationService $confirmation, RazorpayGateway $gateway): string
    {
        $payload = $event->payload ?? [];
        $paymentEntity = $payload['payload']['payment']['entity'] ?? null;
        $orderEntity = $payload['payload']['order']['entity'] ?? null;

        $gatewayOrderId = is_array($paymentEntity) && isset($paymentEntity['order_id'])
            ? (string) $paymentEntity['order_id']
            : (is_array($orderEntity) && isset($orderEntity['id']) ? (string) $orderEntity['id'] : null);

        if ($gatewayOrderId === null) {
            return 'ignored: no gateway order in event';
        }

        $intent = PaymentIntent::where('gateway_order_id', $gatewayOrderId)->first();
        if ($intent === null) {
            return 'ignored: unknown gateway order '.$gatewayOrderId;
        }

        if ($event->payment_intent_id === null) {
            $event->update(['payment_intent_id' => $intent->id, 'order_id' => $intent->order_id]);
        }

        if ($intent->status === PaymentIntent::STATUS_CAPTURED) {
            return 'already captured';
        }

        if ($event->event_type === 'payment.failed') {
            // Attempt-level information only; the intent stays open so a
            // later attempt — or a capture we have not seen yet — still wins.
            $intent->update([
                'attempt_count' => $intent->attempt_count + 1,
                'error_code' => isset($paymentEntity['error_code']) ? mb_substr((string) $paymentEntity['error_code'], 0, 64) : $intent->error_code,
                'error_description' => isset($paymentEntity['error_description']) ? mb_substr((string) $paymentEntity['error_description'], 0, 255) : $intent->error_description,
            ]);

            return 'failed attempt recorded';
        }

        // payment.authorized / payment.captured / order.paid: fetch the truth.
        $paymentId = is_array($paymentEntity) && isset($paymentEntity['id']) ? (string) $paymentEntity['id'] : null;
        if ($paymentId !== null) {
            $payment = $gateway->fetchPayment($intent, $paymentId);
            $result = $confirmation->confirmPayment($intent, $payment, PaymentIntent::CONFIRMED_VIA_WEBHOOK);
        } else {
            $result = $confirmation->syncAndConfirm($intent, PaymentIntent::CONFIRMED_VIA_WEBHOOK);
        }

        return $result->status.($result->message !== '' ? ': '.$result->message : '');
    }
}
