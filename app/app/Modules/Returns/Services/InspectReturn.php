<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Returns\Models\BuybackDecision;
use App\Modules\Returns\Models\ReturnInspection;
use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Admin: records the physical inspection and then approves or rejects a return
 * (ADR-0009 build steps 4 + 5 — admin gate for non-cooling-off reasons).
 *
 * Only buyback/damage/dissatisfaction returns go through this path.
 * Cooling-off returns are auto-executed by OpenReturn (hard rule #5).
 *
 * record()  — Admin sets condition (saleable/non_saleable/damaged); computes and
 *             stores the BuybackDecision; moves order → refund_inspection.
 * approve() — Validates the BuybackDecision, calls RefundOrder::execute().
 * reject()  — Marks the return rejected; re-asserts the cooling-off clock
 *             (so a mistaken return attempt doesn't consume the remaining window).
 */
final class InspectReturn
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly BuybackMatrix $matrix,
        private readonly RefundOrder $refundOrder,
    ) {}

    /**
     * Record the physical inspection result and pre-compute the refund amounts.
     * Gated by `can:finance.record` (R-17) in the controller.
     *
     * @param  string  $condition  'saleable'|'non_saleable'|'damaged'
     */
    public function record(
        ReturnRequest $returnRequest,
        string $condition,
        ?string $notes,
        ?int $inspectorUserId,
    ): ReturnInspection {
        if ($returnRequest->isCoolingOff()) {
            throw new RuntimeException('Cooling-off returns do not go through inspection — they are non-discretionary.');
        }

        $order = $returnRequest->order;
        if ($order->status !== Order::STATUS_REFUND_REQUESTED) {
            throw new RuntimeException("Cannot inspect: order is in status {$order->status}.");
        }

        $saleable = $condition === 'saleable';
        $reason = $returnRequest->reason;

        $policy = $this->matrix->policy($reason, $saleable);
        $taxable = $order->subtotal_paise - $order->gst_paise;
        $gstAdjustment = $policy['refund_gst'] ? $order->gst_paise : 0;
        $netRefund = $policy['eligible'] ? ($taxable + $gstAdjustment) : 0;

        return $this->db->transaction(function () use (
            $returnRequest, $order, $condition, $notes, $inspectorUserId,
            $taxable, $gstAdjustment, $netRefund,
        ): ReturnInspection {
            $inspection = ReturnInspection::updateOrCreate(
                ['return_request_id' => $returnRequest->id],
                [
                    'received_at' => Carbon::now(),
                    'condition' => $condition,
                    'inspector_user_id' => $inspectorUserId,
                    'notes' => $notes,
                ],
            );

            BuybackDecision::updateOrCreate(
                ['return_request_id' => $returnRequest->id],
                [
                    'decision_matrix_version' => BuybackMatrix::VERSION,
                    'refund_base_paise' => $taxable,
                    'gst_adjustment_paise' => $gstAdjustment,
                    'admin_deduction_paise' => 0,
                    'net_refund_paise' => $netRefund,
                ],
            );

            $order->update(['status' => Order::STATUS_REFUND_INSPECTION]);

            AuditLog::create([
                'actor_id' => $inspectorUserId,
                'action' => 'return.inspected',
                'subject_type' => 'return_request',
                'subject_id' => $returnRequest->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'condition' => $condition,
                    'net_refund_paise' => $netRefund,
                ],
            ]);

            return $inspection;
        });
    }

    /**
     * Approve the return: execute the refund using the pre-computed BuybackDecision.
     * Gated by `can:finance.record` in the controller.
     */
    public function approve(ReturnRequest $returnRequest, int $actorUserId): void
    {
        $inspection = $returnRequest->inspection;
        if ($inspection === null) {
            throw new RuntimeException('Inspection must be recorded before approving.');
        }

        $decision = $returnRequest->buybackDecision;
        if ($decision === null) {
            throw new RuntimeException('No BuybackDecision found for this return request.');
        }

        if ($decision->net_refund_paise <= 0) {
            throw new RuntimeException('Net refund is zero — this return is not eligible for a monetary refund.');
        }

        $order = $returnRequest->order;
        if ($order->status !== Order::STATUS_REFUND_INSPECTION) {
            throw new RuntimeException("Cannot approve: order is in status {$order->status}.");
        }

        $saleable = $inspection->condition === 'saleable';

        $decision->update([
            'approved_by_user_id' => $actorUserId,
            'approved_at' => Carbon::now(),
        ]);

        $this->refundOrder->execute(
            order: $order,
            returnRequest: $returnRequest,
            reason: $returnRequest->reason,
            saleable: $saleable,
            actorUserId: $actorUserId,
        );
    }

    /**
     * Reject the return: order goes back to delivered.
     * Re-asserts the cooling-off clock so the customer keeps any days remaining
     * (ADR-0009: a rejection must not consume the cooling-off window).
     */
    public function reject(ReturnRequest $returnRequest, int $actorUserId): void
    {
        $order = $returnRequest->order;
        if (! in_array($order->status, [Order::STATUS_REFUND_REQUESTED, Order::STATUS_REFUND_INSPECTION], true)) {
            throw new RuntimeException("Cannot reject: order is in status {$order->status}.");
        }

        $this->db->transaction(function () use ($returnRequest, $order, $actorUserId): void {
            $returnRequest->update(['status' => ReturnRequest::STATUS_REJECTED]);

            // Revert order back to delivered — customer keeps remaining cooling-off days.
            $order->update(['status' => Order::STATUS_DELIVERED]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'return.rejected',
                'subject_type' => 'return_request',
                'subject_id' => $returnRequest->id,
                'details' => ['order_no' => $order->order_no],
            ]);
        });
    }
}
