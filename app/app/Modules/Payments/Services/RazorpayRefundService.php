<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Payments\Exceptions\RazorpayApiException;
use App\Modules\Payments\Jobs\SendRazorpayRefundJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Support\PaymentSettings;
use App\Modules\Payments\Support\RefundPayable;
use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sending refunds to Razorpay, and settling the ledger on the gateway's word.
 *
 * `liability.refund_payable` is extinguished by exactly two things: a refund
 * the gateway confirms processed (webhook, synchronous response, or
 * reconciliation), or a manual NEFT recorded by finance. Approving a return,
 * cancelling an order, or closing the cooling-off clock never discharges the
 * duty on their own (hard rule 5; compliance C-1).
 *
 * The amount sent is always the CASH the buyer handed over for the order —
 * `RefundOrder::$netRefundPaise` on the returns path, the reversed prepayment
 * on the cancel path — never the order total. Points, repurchase credit and
 * coupons come back in their own form or not at all (R-60). Before sending,
 * the amount is checked against what the gateway still holds for the payment;
 * a shortfall fails loudly onto the worklist rather than trimming silently.
 */
final class RazorpayRefundService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RazorpayClient $client,
        private readonly PaymentSettings $settings,
        private readonly LedgerPoster $ledger,
    ) {}

    // ── Creating ───────────────────────────────────────────────────────

    /**
     * The refund owed on an approved return. Held (not sent) while the goods
     * are still out — the cooling-off path — and released by
     * ReturnReceiptService once they are received.
     */
    public function createForReturn(Order $order, int $cashPaise, string $reasonCode, bool $hold, ?int $actorUserId): ?RefundIntent
    {
        return $this->create($order, "refund:{$order->id}", $cashPaise, $reasonCode, $hold, $actorUserId);
    }

    /** The prepayment reversed when a paid, unshipped order is cancelled. Sent at once. */
    public function createForCancellation(Order $order, int $cashPaise, ?int $actorUserId): ?RefundIntent
    {
        return $this->create($order, "order.cancelled:{$order->id}", $cashPaise, 'cancelled', false, $actorUserId);
    }

    private function create(Order $order, string $idempotencyKey, int $cashPaise, string $reasonCode, bool $hold, ?int $actorUserId): ?RefundIntent
    {
        if ($cashPaise <= 0) {
            return null;
        }

        $payment = $this->capturedIntentFor($order);
        if ($payment === null) {
            // Nothing was collected through a gateway (cash on delivery, or a
            // payment recorded outside the platform): the ledger obligation
            // stands and finance settles it by hand. Logged so it is never
            // mistaken for a refund in flight.
            Log::channel('payments')->warning('refund owed on an order with no captured gateway payment; manual settlement required', [
                'order_id' => $order->id, 'order_no' => $order->order_no, 'amount_paise' => $cashPaise, 'reason' => $reasonCode,
            ]);

            return null;
        }

        $refund = RefundIntent::firstOrCreate(['idempotency_key' => $idempotencyKey], [
            'order_id' => $order->id,
            'payment_intent_id' => $payment->id,
            'gateway' => $payment->gateway,
            'mode' => $payment->mode,
            'amount_paise' => $cashPaise,
            'status' => RefundIntent::STATUS_CREATED,
            'reason_code' => $reasonCode,
            // Frozen now: a retry must send the same body under the same
            // idempotency key, whatever the setting says by then.
            'speed' => $this->settings->refundSpeed(),
            'held_at' => $hold ? Carbon::now() : null,
            'hold_reason' => $hold ? RefundIntent::HOLD_AWAITING_RETURN : null,
        ]);

        if ($refund->wasRecentlyCreated) {
            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => $hold ? 'refund.held' : 'refund.created',
                'subject_type' => 'refund_intent',
                'subject_id' => $refund->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'amount_paise' => $cashPaise,
                    'reason' => $reasonCode,
                    'gateway' => $payment->gateway,
                    'gateway_payment_id' => $payment->gateway_payment_id,
                    'hold_reason' => $refund->hold_reason,
                ],
            ]);

            if (! $hold) {
                $this->dispatch($refund);
            }
        }

        return $refund;
    }

    /** Let a held refund go — the goods are back (or lost by our courier). */
    public function release(RefundIntent $refund, ?int $actorUserId, string $reason): void
    {
        if (! $refund->isHeld()) {
            return;
        }

        $refund->update(['released_at' => Carbon::now(), 'released_by_user_id' => $actorUserId]);

        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => 'refund.released',
            'subject_type' => 'refund_intent',
            'subject_id' => $refund->id,
            'before_hash' => hash('sha256', 'held'),
            'after_hash' => hash('sha256', 'released'),
            'details' => ['order_id' => $refund->order_id, 'amount_paise' => $refund->amount_paise, 'reason' => $reason],
        ]);

        $this->dispatch($refund);
    }

    private function dispatch(RefundIntent $refund): void
    {
        SendRazorpayRefundJob::dispatch($refund->id)->afterCommit();
    }

    // ── Sending ────────────────────────────────────────────────────────

    /**
     * The job body. Idempotent at every step: an existing gateway refund
     * carrying our receipt is adopted, never duplicated.
     *
     * @throws RazorpayApiException on transport failure, so the queue retries
     */
    public function send(RefundIntent $refund): void
    {
        $refund->refresh();
        if ($refund->status !== RefundIntent::STATUS_CREATED || $refund->isHeld()) {
            return;
        }

        if ($refund->gateway === PaymentIntent::GATEWAY_STUB) {
            // Development: no money to move; settle the books so the flow
            // can be exercised end to end. Refused in production by the stub.
            $this->settleProcessed($refund, 'stub');

            return;
        }

        $payment = $refund->paymentIntent;
        if ($payment === null || $payment->gateway_payment_id === null) {
            $this->markFailed($refund, 'no_gateway_payment', 'the captured payment has no gateway payment id');

            return;
        }

        // Adopt a refund already created for this key (a timeout after success).
        foreach ($this->client->fetchRefundsForPayment($payment->gateway_payment_id, $refund->order_id, $refund->id) as $existing) {
            if (($existing['receipt'] ?? null) === $refund->idempotency_key) {
                $this->adopt($refund, $existing);

                return;
            }
        }

        // Never more than the gateway still holds for this payment.
        $gatewayPayment = $this->client->fetchPayment($payment->gateway_payment_id, $refund->order_id, $payment->id);
        $available = (int) ($gatewayPayment['amount'] ?? 0) - (int) ($gatewayPayment['amount_refunded'] ?? 0);
        if ($refund->amount_paise > $available) {
            $this->markFailed($refund, 'exceeds_captured', sprintf('refund %d paise exceeds the %d paise still held for payment %s', $refund->amount_paise, $available, $payment->gateway_payment_id));

            return;
        }

        if ($refund->speed === null) {
            $refund->update(['speed' => $this->settings->refundSpeed()]);
        }

        try {
            $response = $this->client->createRefund(
                paymentId: $payment->gateway_payment_id,
                amountPaise: $refund->amount_paise,
                speed: (string) $refund->speed,
                receipt: $refund->idempotency_key,
                idempotencyKey: self::gatewayIdempotencyKey($refund),
                notes: ['arovolife_order_id' => (string) $refund->order_id, 'arovolife_refund_intent_id' => (string) $refund->id],
                orderId: $refund->order_id,
                refundIntentId: $refund->id,
            );
        } catch (RazorpayApiException $e) {
            $refund->update([
                'attempt_count' => $refund->attempt_count + 1,
                'error_code' => $e->gatewayCode !== null ? mb_substr($e->gatewayCode, 0, 64) : 'transport',
                'error_description' => mb_substr($e->gatewayDescription ?? $e->getMessage(), 0, 255),
                'last_synced_at' => Carbon::now(),
            ]);

            if ($e->httpStatus !== null && $e->httpStatus < 500) {
                // The gateway's answer; asking again will not change it.
                $this->markFailed($refund, $e->gatewayCode ?? 'rejected', $e->gatewayDescription ?? $e->getMessage());

                return;
            }

            throw $e;
        }

        $this->adopt($refund, $response);
    }

    /**
     * The key sent as `X-Refund-Idempotency`. Razorpay accepts only letters,
     * digits, hyphens and underscores, 10–100 characters — our ledger keys
     * (`refund:{id}`) are neither, so they travel as the `receipt` instead
     * and this derived key, fixed by the row id, guards the create.
     */
    public static function gatewayIdempotencyKey(RefundIntent $refund): string
    {
        return sprintf('arv-refund-%d', $refund->id);
    }

    /** @param  array<string, mixed>  $entity  a Razorpay refund entity */
    private function adopt(RefundIntent $refund, array $entity): void
    {
        $refund->update([
            'gateway_refund_id' => (string) ($entity['id'] ?? ''),
            'speed' => isset($entity['speed_requested']) ? (string) $entity['speed_requested'] : $this->settings->refundSpeed(),
            'attempt_count' => $refund->attempt_count + 1,
            'last_synced_at' => Carbon::now(),
            'error_code' => null,
            'error_description' => null,
        ]);

        $this->applyGatewayStatus($refund->fresh(), (string) ($entity['status'] ?? 'pending'), 'sync');
    }

    // ── Settling ───────────────────────────────────────────────────────

    private function applyGatewayStatus(RefundIntent $refund, string $status, string $via): string
    {
        return match ($status) {
            'processed' => $this->settleProcessed($refund, $via) ? 'settled' : 'already settled',
            'failed' => $this->markFailed($refund, 'gateway_failed', 'the gateway reports the refund failed') ? 'failed' : 'already failed',
            default => 'pending',
        };
    }

    /**
     * The gateway has moved the money: extinguish the payable and close the
     * order. The one place `refund_payable` is settled by the gateway.
     */
    public function settleProcessed(RefundIntent $refund, string $via): bool
    {
        return $this->db->transaction(function () use ($refund, $via): bool {
            /** @var RefundIntent $locked */
            $locked = RefundIntent::lockForUpdate()->findOrFail($refund->id);
            if ($locked->status === RefundIntent::STATUS_PROCESSED) {
                return false;
            }

            $before = (string) $locked->status;

            // The stub settles against the same gateway cash account: it only
            // runs where no money exists, and a second account would leave
            // the dev books unreadable for no gain.
            $this->ledger->transfer(
                sourceModule: 'Payments',
                sourceType: 'refund.settled',
                sourceId: $locked->order_id,
                idempotencyKey: "refund.settled:{$locked->id}",
                debitAccount: 'liability.refund_payable',
                creditAccount: 'asset.cash.gateway.razorpay',
                amountPaise: $locked->amount_paise,
                memo: "Refund {$locked->idempotency_key} settled via {$via}",
            );

            $locked->update([
                'status' => RefundIntent::STATUS_PROCESSED,
                'processed_at' => Carbon::now(),
                'settled_via' => $via === 'stub' ? 'stub' : RefundIntent::SETTLED_VIA_GATEWAY,
                'last_synced_at' => Carbon::now(),
                'failed_at' => null,
            ]);

            $this->closeOrder($locked);

            AuditLog::create([
                'actor_id' => null,
                'action' => 'refund.settled',
                'subject_type' => 'refund_intent',
                'subject_id' => $locked->id,
                'before_hash' => hash('sha256', $before),
                'after_hash' => hash('sha256', RefundIntent::STATUS_PROCESSED),
                'details' => [
                    'order_id' => $locked->order_id,
                    'amount_paise' => $locked->amount_paise,
                    'gateway_refund_id' => $locked->gateway_refund_id,
                    'via' => $via,
                ],
            ]);

            $refund->setRawAttributes($locked->fresh()->getAttributes(), true);

            return true;
        });
    }

    /**
     * Finance paid the buyer by NEFT because the gateway could not (balance
     * settled out, payment older than the gateway's refund window). Same
     * ledger discharge, different cash account, and the reference is kept.
     */
    public function settleManually(RefundIntent $refund, int $actorUserId, string $reference, ?string $note): void
    {
        $this->db->transaction(function () use ($refund, $actorUserId, $reference, $note): void {
            /** @var RefundIntent $locked */
            $locked = RefundIntent::lockForUpdate()->findOrFail($refund->id);
            if ($locked->status === RefundIntent::STATUS_PROCESSED) {
                return;
            }
            // Finance cannot pay out around the return-receipt gate, and a
            // forfeited refund has no payable left to discharge — settling it
            // would pay a buyer who kept the goods and drive the payable
            // negative.
            if ($locked->isHeld()) {
                throw new RuntimeException("Refund {$locked->idempotency_key} is held until the return is received; release it from the return first.");
            }
            if ($locked->isForfeited()) {
                throw new RuntimeException("Refund {$locked->idempotency_key} was forfeited — the goods never came back; nothing is owed.");
            }

            $this->ledger->transfer(
                sourceModule: 'Payments',
                sourceType: 'refund.manual_settlement',
                sourceId: $locked->order_id,
                idempotencyKey: "refund.manual:{$locked->id}",
                debitAccount: 'liability.refund_payable',
                creditAccount: 'asset.cash.bank.settlement',
                amountPaise: $locked->amount_paise,
                memo: "Refund {$locked->idempotency_key} settled by NEFT {$reference}",
                createdByUserId: $actorUserId,
            );

            $locked->update([
                'status' => RefundIntent::STATUS_PROCESSED,
                'processed_at' => Carbon::now(),
                'settled_via' => RefundIntent::SETTLED_VIA_MANUAL_NEFT,
                'released_at' => $locked->released_at ?? Carbon::now(),
                'released_by_user_id' => $locked->released_by_user_id ?? $actorUserId,
                'error_code' => null,
                'error_description' => null,
                'failed_at' => null,
            ]);

            $this->closeOrder($locked);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'refund.manual_settlement',
                'subject_type' => 'refund_intent',
                'subject_id' => $locked->id,
                'before_hash' => hash('sha256', (string) $refund->status),
                'after_hash' => hash('sha256', RefundIntent::STATUS_PROCESSED),
                'details' => [
                    'order_id' => $locked->order_id,
                    'amount_paise' => $locked->amount_paise,
                    'reference' => $reference,
                    'note' => $note,
                ],
            ]);

            $refund->setRawAttributes($locked->fresh()->getAttributes(), true);
        });
    }

    public function markFailed(RefundIntent $refund, string $code, string $description): bool
    {
        $refund->refresh();
        if ($refund->status === RefundIntent::STATUS_PROCESSED) {
            return false;
        }
        $alreadyFailed = $refund->status === RefundIntent::STATUS_FAILED;

        $refund->update([
            'status' => RefundIntent::STATUS_FAILED,
            'failed_at' => Carbon::now(),
            'error_code' => mb_substr($code, 0, 64),
            'error_description' => mb_substr($description, 0, 255),
            'last_synced_at' => Carbon::now(),
        ]);

        if (! $alreadyFailed) {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'refund.failed',
                'subject_type' => 'refund_intent',
                'subject_id' => $refund->id,
                'details' => ['order_id' => $refund->order_id, 'amount_paise' => $refund->amount_paise, 'code' => $code, 'description' => $description],
            ]);

            Log::critical('Gateway refund failed — on the unsettled-refunds worklist', [
                'refund_intent_id' => $refund->id,
                'order_id' => $refund->order_id,
                'amount_paise' => $refund->amount_paise,
                'code' => $code,
                'description' => $description,
            ]);
        }

        return ! $alreadyFailed;
    }

    /**
     * A refund that will never be made: the goods never came back.
     *
     * The sale stands exactly as it was first recorded, so the
     * `order.refund_approved` entry is mirrored line for line — revenue,
     * output GST, shipping and the discount contras all return to their
     * pre-refund position and the payable is extinguished with them. Keyed
     * on the ORDER, because a cash-on-delivery return carries the same entry
     * with no refund intent behind it. BV is left reversed on purpose: paying
     * less commission cannot break hard rule 2, and ADR-0010 forbids taking
     * anything back from an upline that was already reversed.
     */
    public function forfeit(Order $order, int $actorUserId, string $reason): void
    {
        $this->db->transaction(function () use ($order, $actorUserId, $reason): void {
            /** @var RefundIntent|null $refund */
            $refund = RefundIntent::lockForUpdate()->where('idempotency_key', "refund:{$order->id}")->first();
            if ($refund !== null && ($refund->status === RefundIntent::STATUS_PROCESSED || $refund->gateway_refund_id !== null)) {
                throw new RuntimeException("The refund on {$order->order_no} has already gone to the gateway; it cannot be forfeited.");
            }

            /** @var LedgerTx|null $approved */
            $approved = LedgerTx::with('entries.account')->where('idempotency_key', "refund:{$order->id}")->first();
            if ($approved === null) {
                throw new RuntimeException("No refund entry exists for {$order->order_no}; nothing to forfeit.");
            }

            $lines = [];
            foreach ($approved->entries as $entry) {
                /** @var LedgerEntry $entry */
                $lines[] = [
                    'account' => (string) $entry->account->code,
                    'side' => $entry->side === 'debit' ? 'credit' : 'debit',
                    'amount_paise' => (int) $entry->amount_paise,
                ];
            }

            $this->ledger->post(
                sourceModule: 'Payments',
                sourceType: 'refund.forfeited',
                sourceId: $order->id,
                idempotencyKey: "refund.forfeited:{$order->id}",
                lines: $lines,
                memo: "Refund on {$order->order_no} forfeited — goods not returned: {$reason}",
                createdByUserId: $actorUserId,
            );

            $before = $refund?->status;
            $refund?->update([
                'status' => RefundIntent::STATUS_FAILED,
                'failed_at' => Carbon::now(),
                'error_code' => RefundIntent::ERROR_GOODS_NOT_RETURNED,
                'error_description' => mb_substr($reason, 0, 255),
            ]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'refund.forfeited',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'before_hash' => hash('sha256', (string) ($before ?? 'no_refund_intent')),
                'after_hash' => hash('sha256', 'forfeited'),
                'details' => [
                    'order_id' => $order->id,
                    'refund_intent_id' => $refund?->id,
                    'lines' => $lines,
                    'reason' => $reason,
                ],
            ]);
        });
    }

    /**
     * Finance paid a buyer by NEFT for a refund that never had a gateway
     * payment behind it — cash on delivery, or a payment recorded outside the
     * platform (R-68). The payable is read from the `order.refund_approved`
     * entry itself, so the amount discharged is exactly the amount owed, and
     * the same settlement bank account is credited as for a gateway refund
     * settled by hand.
     */
    public function settleOrderManually(Order $order, int $actorUserId, string $reference, ?string $note): void
    {
        $this->db->transaction(function () use ($order, $actorUserId, $reference, $note): void {
            /** @var Order $locked */
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== Order::STATUS_REFUND_APPROVED) {
                throw new RuntimeException("Order {$locked->order_no} is not awaiting a refund.");
            }
            if (RefundIntent::where('order_id', $locked->id)->exists()) {
                throw new RuntimeException("Order {$locked->order_no} has a gateway refund; settle that refund, not the order.");
            }

            $owed = RefundPayable::owedOutsideGateway($locked);
            if ($owed > 0) {
                $this->ledger->transfer(
                    sourceModule: 'Payments',
                    sourceType: 'refund.manual_settlement',
                    sourceId: $locked->id,
                    idempotencyKey: "refund.manual.order:{$locked->id}",
                    debitAccount: 'liability.refund_payable',
                    creditAccount: 'asset.cash.bank.settlement',
                    amountPaise: $owed,
                    memo: "Refund on {$locked->order_no} settled by NEFT {$reference} (no gateway payment)",
                    createdByUserId: $actorUserId,
                );
            }

            $this->closeOrderRecord($locked);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'refund.manual_settlement',
                'subject_type' => 'order',
                'subject_id' => $locked->id,
                'before_hash' => hash('sha256', Order::STATUS_REFUND_APPROVED),
                'after_hash' => hash('sha256', Order::STATUS_REFUNDED),
                'details' => [
                    'order_id' => $locked->id,
                    'amount_paise' => $owed,
                    'reference' => $reference,
                    'note' => $note,
                    'gateway' => 'none',
                ],
            ]);

            $order->setRawAttributes($locked->fresh()->getAttributes(), true);
        });
    }

    private function closeOrder(RefundIntent $refund): void
    {
        $order = Order::find($refund->order_id);
        if ($order === null) {
            return;
        }

        $this->closeOrderRecord($order);
    }

    private function closeOrderRecord(Order $order): void
    {
        if ($order->status === Order::STATUS_REFUND_APPROVED) {
            $order->update(['status' => Order::STATUS_REFUNDED, 'refunded_at' => Carbon::now()]);
            ReturnRequest::where('order_id', $order->id)
                ->where('status', ReturnRequest::STATUS_APPROVED)
                ->update(['status' => ReturnRequest::STATUS_REFUNDED]);
        }
        // Cancelled and late-capture orders stay cancelled: nothing else to close.
    }

    // ── Gateway → us ───────────────────────────────────────────────────

    /** refund.processed / refund.failed from the webhook queue. */
    public function applyWebhook(PaymentEvent $event): string
    {
        $entity = $event->payload['payload']['refund']['entity'] ?? null;
        if (! is_array($entity)) {
            return 'ignored: no refund entity';
        }

        $refund = null;
        if (isset($entity['id'])) {
            $refund = RefundIntent::where('gateway_refund_id', (string) $entity['id'])->first();
        }
        if ($refund === null && isset($entity['receipt'])) {
            $refund = RefundIntent::where('idempotency_key', (string) $entity['receipt'])->first();
        }
        if ($refund === null) {
            return 'ignored: unknown refund '.(string) ($entity['id'] ?? '?');
        }

        if ($event->refund_intent_id === null) {
            $event->update(['refund_intent_id' => $refund->id, 'order_id' => $refund->order_id]);
        }
        if ($refund->gateway_refund_id === null && isset($entity['id'])) {
            $refund->update(['gateway_refund_id' => (string) $entity['id']]);
        }

        return $this->applyGatewayStatus($refund->fresh(), (string) ($entity['status'] ?? ''), 'webhook');
    }

    /**
     * Ask the gateway about every refund sent but not yet confirmed, and
     * re-queue any released intent that was never sent.
     *
     * @return array{checked: int, settled: int, failed: int}
     */
    public function reconcileOutstanding(): array
    {
        $tally = ['checked' => 0, 'settled' => 0, 'failed' => 0];
        if (! $this->client->configured()) {
            return $tally;
        }

        $stale = Carbon::now()->subMinutes(10);

        RefundIntent::query()
            ->where('gateway', PaymentIntent::GATEWAY_RAZORPAY)
            ->where('status', RefundIntent::STATUS_CREATED)
            // Sendable: never held, or held and since released.
            ->where(fn ($q) => $q->whereNull('held_at')->orWhereNotNull('released_at'))
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $stale))
            ->orderBy('id')
            ->chunkById(100, function ($refunds) use (&$tally): void {
                foreach ($refunds as $refund) {
                    /** @var RefundIntent $refund */
                    $tally['checked']++;
                    try {
                        if ($refund->gateway_refund_id === null) {
                            // Stamped so a job still in its backoff is not
                            // shadowed by a fresh one every five minutes.
                            $refund->update(['last_synced_at' => Carbon::now()]);
                            $this->dispatch($refund);

                            continue;
                        }
                        $entity = $this->client->fetchRefund($refund->gateway_refund_id, $refund->order_id, $refund->id);
                        $refund->update(['last_synced_at' => Carbon::now()]);
                        $outcome = $this->applyGatewayStatus($refund->fresh(), (string) ($entity['status'] ?? ''), 'reconcile');
                        if ($outcome === 'settled') {
                            $tally['settled']++;
                        } elseif ($outcome === 'failed') {
                            $tally['failed']++;
                        }
                    } catch (RazorpayApiException $e) {
                        Log::channel('payments')->warning('refund reconcile: gateway error', ['refund_intent_id' => $refund->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $tally;
    }

    private function capturedIntentFor(Order $order): ?PaymentIntent
    {
        return PaymentIntent::where('order_id', $order->id)
            ->where('status', PaymentIntent::STATUS_CAPTURED)
            ->orderByDesc('id')
            ->first();
    }
}
