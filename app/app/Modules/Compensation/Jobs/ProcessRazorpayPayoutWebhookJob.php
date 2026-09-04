<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Models\PayoutGatewayEvent;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\RazorpayPayoutDispatchService;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Apply one stored payout webhook.
 *
 * On the `default` queue (database driver, ADR-0011): this never moves money,
 * it records what the bank did with money that already left. A dropped job
 * here is a line item stuck on `pending` until the next webhook or a manual
 * reconciliation — not a lost credit.
 *
 * `transferred` is sticky. Razorpay re-orders deliveries, so a late
 * `payout.initiated` for a transfer whose `payout.processed` already landed
 * must not walk the line item backwards. Only `payout.reversed` may overturn
 * a settled transfer, because that is the bank genuinely taking the money
 * back.
 */
final class ProcessRazorpayPayoutWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly int $eventId)
    {
        $this->onQueue('default');
    }

    public function handle(RazorpayPayoutDispatchService $dispatcher): void
    {
        $event = PayoutGatewayEvent::find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        try {
            $outcome = $this->apply($event, $dispatcher);
        } catch (Throwable $e) {
            $event->forceFill(['processing_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            throw $e;
        }

        $event->forceFill([
            'processed_at' => now(),
            'processing_error' => null,
        ])->save();

        Log::channel('payments')->info('razorpayx payout webhook applied', [
            'payout_gateway_event_id' => $event->id,
            'event' => $event->event_type,
            'outcome' => $outcome,
        ]);
    }

    private function apply(PayoutGatewayEvent $event, RazorpayPayoutDispatchService $dispatcher): string
    {
        $entity = $event->payload['payload']['payout']['entity'] ?? null;
        $entity = is_array($entity) ? $entity : [];

        $payoutId = (string) ($entity['id'] ?? $event->gateway_payout_id ?? '');
        if ($payoutId === '') {
            return 'ignored: no payout id in the event';
        }

        $line = PayoutLineItem::where('razorpay_payout_id', $payoutId)->first();
        if ($line === null) {
            // A payout we never recorded — a transfer made from the Razorpay
            // dashboard, or an event for another environment sharing the
            // account. Stored as evidence, applied to nothing.
            return 'ignored: no line item for payout '.$payoutId;
        }

        $event->forceFill([
            'payout_line_item_id' => (int) $line->id,
            'payout_batch_id' => (int) $line->payout_batch_id,
            'gateway_payout_id' => $payoutId,
        ])->save();

        $outcome = match ($event->event_type) {
            'payout.processed' => $this->markTransferred($line, $entity, $event),
            'payout.failed', 'payout.rejected' => $this->markFailed($line, $entity, $event,
                $this->errorDescription($entity) ?? 'Razorpay reported the transfer as failed.'),
            'payout.reversed' => $this->markFailed($line, $entity, $event, 'Payment reversed by bank.', allowOverturn: true),
            // Interim states. The transfer is in flight; nothing to record on
            // the line item beyond the event row already written.
            'payout.queued', 'payout.pending', 'payout.initiated', 'payout.updated' => 'noted: '.$event->event_type,
            default => 'ignored: '.$event->event_type,
        };

        $batch = $line->payoutBatch()->first();
        if ($batch !== null) {
            $dispatcher->refreshBatchStatus($batch);
        }

        return $outcome;
    }

    /** @param  array<string, mixed>  $entity */
    private function markTransferred(PayoutLineItem $line, array $entity, PayoutGatewayEvent $event): string
    {
        $utr = isset($entity['utr']) && $entity['utr'] !== '' ? (string) $entity['utr'] : null;

        if ($line->status === PayoutLineItem::STATUS_TRANSFERRED) {
            // Idempotent redelivery. The UTR may only now be present, so fill
            // it if it is still missing — but never change one already set.
            if ($line->utr_number === null && $utr !== null) {
                $line->forceFill(['utr_number' => $utr])->save();
            }

            return 'skipped: already transferred';
        }

        $before = $line->status;

        $line->forceFill([
            'status' => PayoutLineItem::STATUS_TRANSFERRED,
            'utr_number' => $utr ?? $line->utr_number,
            'failure_reason' => null,
        ])->save();

        $this->audit($line, $event, 'payout.line_item.transferred', $before, PayoutLineItem::STATUS_TRANSFERRED, [
            'utr_number' => $utr,
        ]);

        return 'transferred';
    }

    /** @param  array<string, mixed>  $entity */
    private function markFailed(
        PayoutLineItem $line,
        array $entity,
        PayoutGatewayEvent $event,
        string $reason,
        bool $allowOverturn = false,
    ): string {
        // A settled transfer is only walked back by a genuine reversal.
        if ($line->status === PayoutLineItem::STATUS_TRANSFERRED && ! $allowOverturn) {
            return 'skipped: already transferred, late failure event ignored';
        }

        if ($line->status === PayoutLineItem::STATUS_FAILED && $line->failure_reason === $reason) {
            return 'skipped: already failed';
        }

        $before = $line->status;

        $line->forceFill([
            'status' => PayoutLineItem::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 500),
        ])->save();

        $this->audit($line, $event, 'payout.line_item.failed', $before, PayoutLineItem::STATUS_FAILED, [
            'failure_reason' => mb_substr($reason, 0, 500),
            'gateway_status' => isset($entity['status']) ? (string) $entity['status'] : null,
        ]);

        return $allowOverturn ? 'reversed' : 'failed';
    }

    /** @param  array<string, mixed>  $entity */
    private function errorDescription(array $entity): ?string
    {
        $error = is_array($entity['error'] ?? null) ? $entity['error'] : [];
        $description = (string) ($error['description'] ?? '');

        if ($description !== '') {
            return $description;
        }

        $details = is_array($entity['status_details'] ?? null) ? $entity['status_details'] : [];
        $detail = (string) ($details['description'] ?? $details['reason'] ?? '');

        return $detail !== '' ? $detail : null;
    }

    /** @param  array<string, mixed>  $extra */
    private function audit(PayoutLineItem $line, PayoutGatewayEvent $event, string $action, string $before, string $after, array $extra): void
    {
        AuditLog::create([
            // A webhook has no human actor; the event row is the provenance.
            'actor_id' => null,
            'action' => $action,
            'subject_type' => 'payout_line_item',
            'subject_id' => (int) $line->id,
            'details' => [
                'payout_batch_id' => $line->payout_batch_id,
                'distributor_id' => $line->distributor_id,
                'razorpay_payout_id' => $line->razorpay_payout_id,
                'payout_gateway_event_id' => $event->id,
                'event' => $event->event_type,
                'before' => $before,
                'after' => $after,
                ...$extra,
            ],
            'ip' => null,
        ]);
    }
}
