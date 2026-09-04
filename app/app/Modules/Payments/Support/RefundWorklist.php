<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Grievance\DTOs\FileGrievanceData;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Services\GrievanceService;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Every refund that is owed and not yet in the buyer's hands, with the two
 * clocks that decide whether someone must act:
 *
 *   cancellation → receipt   ours to chase. Alert at 10 days, escalate to the
 *                            Grievance Officer at 21 (product-owner decision
 *                            2026-09-04) — as a grievance ticket, so it is on
 *                            the statutory register (DSR Rule 12), not in a
 *                            mailbox.
 *   receipt → credited       the contractual seven business days (terms §8).
 *
 * Nothing here pays or forfeits anything; it surfaces and records.
 */
final class RefundWorklist
{
    public const ALERT_AFTER_DAYS = 10;

    public const ESCALATE_AFTER_DAYS = 21;

    public const PROMISE_BUSINESS_DAYS = 7;

    public function __construct(private readonly GrievanceService $grievances) {}

    /** @return Collection<int, RefundIntent> owed and unsettled, oldest first */
    public function outstandingRefunds(): Collection
    {
        return RefundIntent::query()
            ->with(['order.customer', 'paymentIntent'])
            ->where('status', '!=', RefundIntent::STATUS_PROCESSED)
            // A forfeited refund is closed, not failed: nothing is owed.
            ->whereNot(fn ($q) => $q->where('status', RefundIntent::STATUS_FAILED)->where('error_code', RefundIntent::ERROR_GOODS_NOT_RETURNED))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Refunds owed with no gateway payment behind them — cash on delivery, or
     * a payment recorded outside the platform (R-68). The obligation is in the
     * ledger; the only discharge is finance recording the NEFT it made.
     *
     * @return Collection<int, Order> oldest first
     */
    public function manualRefunds(): Collection
    {
        return Order::query()
            ->with('customer')
            ->where('status', Order::STATUS_REFUND_APPROVED)
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('refund_intents')->whereColumn('refund_intents.order_id', 'orders.id'))
            ->orderBy('refund_approved_at')
            ->get();
    }

    /** @return Collection<int, ReturnRequest> cooling-off returns whose goods are still out */
    public function awaitingReceipt(): Collection
    {
        return ReturnRequest::query()
            ->with(['order.customer'])
            ->whereNotNull('entitlements_held_at')
            ->whereNull('received_at')
            ->whereNull('receipt_outcome')
            ->orderBy('entitlements_held_at')
            ->get();
    }

    /**
     * @return array{state: string, label: string, days: int, overdue: bool, escalated: bool}
     */
    public function classify(RefundIntent $refund, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        if ($refund->status === RefundIntent::STATUS_FAILED) {
            $days = (int) ($refund->failed_at ?? $refund->updated_at)->diffInDays($now);

            return ['state' => 'failed', 'label' => 'Needs manual settlement', 'days' => $days, 'overdue' => true, 'escalated' => false];
        }

        if ($refund->isHeld()) {
            $days = (int) $refund->held_at->diffInDays($now);

            return [
                'state' => 'held',
                'label' => 'Awaiting return receipt',
                'days' => $days,
                'overdue' => $days >= self::ALERT_AFTER_DAYS,
                'escalated' => $days >= self::ESCALATE_AFTER_DAYS,
            ];
        }

        $since = $refund->released_at ?? $refund->created_at;
        $businessDays = (int) $since->diffInWeekdays($now);

        return [
            'state' => $refund->gateway_refund_id === null ? 'queued' : 'sent',
            'label' => $refund->gateway_refund_id === null ? 'Queued to send' : 'Sent — awaiting gateway confirmation',
            'days' => $businessDays,
            'overdue' => $businessDays >= self::PROMISE_BUSINESS_DAYS,
            'escalated' => false,
        ];
    }

    /** Held refunds past the alert threshold, failed ones, and refunds owed outside the gateway. */
    public function attentionCount(): int
    {
        $count = RefundIntent::where('status', RefundIntent::STATUS_FAILED)
            ->where(fn ($q) => $q->whereNull('error_code')->orWhere('error_code', '!=', RefundIntent::ERROR_GOODS_NOT_RETURNED))
            ->count();

        $count += Order::query()
            ->where('status', Order::STATUS_REFUND_APPROVED)
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('refund_intents')->whereColumn('refund_intents.order_id', 'orders.id'))
            ->count();

        return $count + ReturnRequest::query()
            ->whereNotNull('entitlements_held_at')
            ->whereNull('received_at')
            ->whereNull('receipt_outcome')
            ->where('entitlements_held_at', '<=', Carbon::now()->subDays(self::ALERT_AFTER_DAYS))
            ->count();
    }

    /**
     * Run the two clocks. Idempotent: each return is alerted once and
     * escalated once, stamped on the row.
     *
     * @return array{alerted: int, escalated: int}
     */
    public function sweep(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $tally = ['alerted' => 0, 'escalated' => 0];

        $this->awaitingReceipt()->each(function (ReturnRequest $return) use ($now, &$tally): void {
            $days = (int) $return->entitlements_held_at->diffInDays($now);

            if ($days >= self::ALERT_AFTER_DAYS && $return->hold_alert_sent_at === null) {
                $return->update(['hold_alert_sent_at' => $now]);
                AuditLog::create([
                    'actor_id' => null,
                    'action' => 'refund.hold_alert',
                    'subject_type' => 'return_request',
                    'subject_id' => $return->id,
                    'details' => ['order_no' => $return->order->order_no, 'rma_no' => $return->rma_no, 'days_without_receipt' => $days],
                ]);
                Log::critical('Cooling-off return not received — refund held', [
                    'return_request_id' => $return->id, 'order_no' => $return->order->order_no, 'days' => $days,
                ]);
                $tally['alerted']++;
            }

            if ($days >= self::ESCALATE_AFTER_DAYS && $return->hold_escalated_at === null) {
                $ticket = $this->grievances->file(new FileGrievanceData(
                    subject: "Cooling-off refund held {$days} days without return receipt — order {$return->order->order_no}",
                    body: "The buyer cancelled order {$return->order->order_no} (RMA {$return->rma_no}) under cooling-off on "
                        .$return->entitlements_held_at->toDateString()
                        .'. The returned goods have not been marked received, so the refund, points and repurchase credit are still held. '
                        .'Decide: mark received / courier lost (releases the refund) or not returned (forfeits it). '
                        .'Opened automatically by the refund worklist at the 21-day threshold.',
                    category: TicketCategory::Refund,
                    channel: TicketChannel::Web,
                    customerId: $return->order->customer_id,
                    orderId: $return->order_id,
                    severity: 'high',
                ));
                $return->update(['hold_escalated_at' => $now]);
                AuditLog::create([
                    'actor_id' => null,
                    'action' => 'refund.hold_escalated',
                    'subject_type' => 'return_request',
                    'subject_id' => $return->id,
                    'details' => ['order_no' => $return->order->order_no, 'rma_no' => $return->rma_no, 'days_without_receipt' => $days, 'ticket_no' => $ticket->ticket_no],
                ]);
                $tally['escalated']++;
            }
        });

        return $tally;
    }
}
