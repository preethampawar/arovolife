<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opens a return request on behalf of the customer (ADR-0009 build step 3).
 *
 * Guards:
 *   - order must belong to the customer
 *   - order must be in a returnable state (delivered or confirmed)
 *   - reason must be a known BuybackMatrix reason
 *   - for cooling_off: order must have an open cooling-off clock within 30d
 *   - for damage/dissatisfaction: within the matrix window from delivery
 *   - general/termination buyback: no window limit
 *
 * Cooling-off auto-route (hard rule #5 — non-discretionary):
 *   If reason = cooling_off AND cooling-off clock is open AND within 30 days,
 *   RefundOrder::execute() is called immediately (assuming saleable = true).
 *   No admin gate is inserted before executing the refund right.
 *
 * All other reasons leave the order at refund_requested for admin inspection.
 */
final class OpenReturn
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly BuybackMatrix $matrix,
        private readonly RefundOrder $refundOrder,
    ) {}

    public function execute(
        Order $order,
        Customer $customer,
        string $reason,
        ?string $notes,
        ?int $actorUserId,
    ): ReturnRequest {
        $order->loadMissing('coolingOff');

        $this->guardEligibility($order, $customer, $reason);

        $returnRequest = $this->db->transaction(function () use ($order, $customer, $reason, $notes, $actorUserId): ReturnRequest {
            $returnRequest = ReturnRequest::create([
                'rma_no' => $this->generateRmaNo(),
                'order_id' => $order->id,
                'order_item_id' => null, // order-level return
                'qty' => null,
                'reason' => $reason,
                'opened_by_customer_id' => $customer->id,
                'notes' => $notes,
                'status' => ReturnRequest::STATUS_OPENED,
            ]);

            $order->update(['status' => Order::STATUS_REFUND_REQUESTED]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'return.opened',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'rma_no' => $returnRequest->rma_no,
                    'reason' => $reason,
                ],
            ]);

            return $returnRequest;
        });

        // Cooling-off: auto-execute RefundOrder immediately (one-click, non-discretionary).
        // Hard rule #5 — the customer's right is exercised here; saleability is assumed
        // and may be verified post-fact by an admin, but it cannot block this refund.
        if ($reason === ReturnRequest::REASON_COOLING_OFF) {
            $order->refresh();
            $this->refundOrder->execute(
                order: $order,
                returnRequest: $returnRequest,
                reason: $reason,
                saleable: true,
                actorUserId: $actorUserId,
            );
        }

        return $returnRequest;
    }

    private function guardEligibility(Order $order, Customer $customer, string $reason): void
    {
        if ($order->customer_id !== $customer->id) {
            throw new RuntimeException('Order does not belong to this customer.');
        }

        if (! in_array($reason, BuybackMatrix::REASONS, true)) {
            throw new RuntimeException("Unknown return reason: {$reason}");
        }

        $returnableStatuses = [Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED];
        if (! in_array($order->status, $returnableStatuses, true)) {
            throw new RuntimeException("Order {$order->order_no} is not in a returnable state (status: {$order->status}).");
        }

        $policy = $this->matrix->policy($reason, true); // saleable=true for window check

        if ($reason === ReturnRequest::REASON_COOLING_OFF) {
            $coolingOff = $order->coolingOff;
            if ($coolingOff === null || $coolingOff->status !== OrderCoolingOff::STATUS_OPEN) {
                throw new RuntimeException('The cooling-off window is not open for this order.');
            }
            if ($coolingOff->ends_at->isPast()) {
                throw new RuntimeException('The 30-day cooling-off window has expired for this order.');
            }
        } elseif ($policy['window_days'] !== null && $order->delivered_at !== null) {
            $daysSinceDelivery = (int) Carbon::parse($order->delivered_at)->diffInDays(Carbon::now(), false);
            if ($daysSinceDelivery > $policy['window_days']) {
                throw new RuntimeException(
                    "The {$policy['window_days']}-day return window for reason '{$reason}' has expired ({$daysSinceDelivery} days since delivery)."
                );
            }
        }

        // Guard: no duplicate open return request on this order.
        $existing = ReturnRequest::where('order_id', $order->id)
            ->whereIn('status', [ReturnRequest::STATUS_OPENED, ReturnRequest::STATUS_APPROVED])
            ->exists();
        if ($existing) {
            throw new RuntimeException("A return request is already open or approved for order {$order->order_no}.");
        }

        // GSB disbursement gate (non-cooling-off returns only).
        // Cooling-off is a statutory right (DSR Rule 5(1)(g) + Hard Rule #5) that cannot
        // be blocked. For all other reasons: once a payout batch has been generated after
        // this order was placed, the attributed distributor's GSB for that order's BV has
        // been swept into the payout pipeline — the product can no longer be returned.
        if ($reason !== ReturnRequest::REASON_COOLING_OFF) {
            $gsbDisbursed = PayoutBatch::where('processed_at', '>', $order->created_at)
                ->whereNotIn('status', [PayoutBatch::STATUS_FAILED])
                ->exists();

            if ($gsbDisbursed) {
                throw new RuntimeException(
                    'This order is no longer eligible for return. The GSB commission for this order\'s BV has been disbursed in a payout batch. Only cooling-off cancellations (within 30 days) are permitted after GSB disbursal.'
                );
            }
        }
    }

    private function generateRmaNo(): string
    {
        return 'RMA-'.strtoupper(Str::random(10));
    }
}
