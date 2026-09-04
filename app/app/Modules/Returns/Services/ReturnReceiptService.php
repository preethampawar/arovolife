<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\RedeemPointsService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Services\RazorpayRefundService;
use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The release gate on a cooling-off refund.
 *
 * The buyer's one-click cancellation is instant and unconditional: the
 * ledger is reversed, BV is reversed, the cooling-off clock closes. What
 * waits is everything of value going back to the buyer — the cash, and the
 * points and repurchase credit they paid with — because terms §8 conditions
 * the refund on arovolife receiving the returned product, and a buyer who
 * keeps the goods must not also keep the entitlements (the R-60 shape, one
 * lever over). All three move together, on this one action.
 *
 * Three outcomes, all manual, all audited (product-owner decision
 * 2026-09-04): received or lost by our courier → release; never sent → held,
 * the buyer told, the refund forfeited only by an explicit decision.
 */
final class ReturnReceiptService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly WalletService $wallet,
        private readonly RazorpayRefundService $refunds,
    ) {}

    /** The goods are back (or our courier lost them): restore and release. */
    public function markReceived(ReturnRequest $returnRequest, int $actorUserId, string $outcome, ?string $note = null): void
    {
        if (! in_array($outcome, [ReturnRequest::RECEIPT_RECEIVED, ReturnRequest::RECEIPT_COURIER_LOST], true)) {
            throw new RuntimeException("Unknown receipt outcome: {$outcome}");
        }
        if ($returnRequest->received_at !== null) {
            return; // idempotent
        }
        if ($returnRequest->receipt_outcome === ReturnRequest::RECEIPT_NOT_RETURNED) {
            throw new RuntimeException('This return was closed as not returned.');
        }

        $this->db->transaction(function () use ($returnRequest, $actorUserId, $outcome, $note): void {
            $order = Order::lockForUpdate()->findOrFail($returnRequest->order_id);

            $returnRequest->update([
                'received_at' => Carbon::now(),
                'received_by_user_id' => $actorUserId,
                'receipt_outcome' => $outcome,
                'receipt_note' => $note,
            ]);

            $this->restoreEntitlements($returnRequest, $order);

            $refund = RefundIntent::where('idempotency_key', "refund:{$order->id}")->first();
            if ($refund !== null) {
                $this->refunds->release($refund, $actorUserId, $outcome);
            }

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'return.received',
                'subject_type' => 'return_request',
                'subject_id' => $returnRequest->id,
                'before_hash' => hash('sha256', 'awaiting_receipt'),
                'after_hash' => hash('sha256', $outcome),
                'details' => [
                    'order_no' => $order->order_no,
                    'rma_no' => $returnRequest->rma_no,
                    'outcome' => $outcome,
                    'note' => $note,
                    'points_restored_paise' => $returnRequest->entitlement_points_paise,
                    'repurchase_credit_restored_paise' => $returnRequest->entitlement_credit_paise,
                    'refund_intent_id' => $refund?->id,
                ],
            ]);
        });
    }

    /**
     * The buyer never sent the goods back. The cash stays with the company,
     * the entitlements are not restored, the order reverts to delivered and
     * the cooling-off window — already consumed by the cancellation — does
     * not reopen. An explicit, audited decision; nothing here runs on a timer.
     */
    public function markNotReturned(ReturnRequest $returnRequest, int $actorUserId, string $reason): void
    {
        if ($returnRequest->received_at !== null) {
            throw new RuntimeException('This return was already received.');
        }
        if ($returnRequest->entitlements_held_at === null) {
            // An inspected return's refund is already on its way; only a
            // cooling-off return still waiting for its goods can be closed.
            throw new RuntimeException('Only a cooling-off return awaiting receipt can be closed as not returned.');
        }
        if ($returnRequest->receipt_outcome === ReturnRequest::RECEIPT_NOT_RETURNED) {
            return; // idempotent
        }

        $this->db->transaction(function () use ($returnRequest, $actorUserId, $reason): void {
            $order = Order::lockForUpdate()->findOrFail($returnRequest->order_id);

            $returnRequest->update([
                'received_by_user_id' => $actorUserId,
                'receipt_outcome' => ReturnRequest::RECEIPT_NOT_RETURNED,
                'receipt_note' => $reason,
                'status' => ReturnRequest::STATUS_REJECTED,
            ]);

            // Keyed on the order: a cash-on-delivery return has the same
            // refund entry to write back and no refund intent behind it.
            $this->refunds->forfeit($order, $actorUserId, $reason);

            if ($order->status === Order::STATUS_REFUND_APPROVED) {
                $order->update(['status' => Order::STATUS_DELIVERED]);
            }

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'return.not_returned',
                'subject_type' => 'return_request',
                'subject_id' => $returnRequest->id,
                'before_hash' => hash('sha256', 'awaiting_receipt'),
                'after_hash' => hash('sha256', ReturnRequest::RECEIPT_NOT_RETURNED),
                'details' => [
                    'order_no' => $order->order_no,
                    'rma_no' => $returnRequest->rma_no,
                    'reason' => $reason,
                    'refund_intent_id' => RefundIntent::where('idempotency_key', "refund:{$order->id}")->value('id'),
                    'entitlements_withheld' => [
                        'points_paise' => $returnRequest->entitlement_points_paise,
                        'repurchase_credit_paise' => $returnRequest->entitlement_credit_paise,
                    ],
                ],
            ]);
        });
    }

    /** Give back, in kind, exactly what RefundOrder withheld. Idempotent. */
    private function restoreEntitlements(ReturnRequest $returnRequest, Order $order): void
    {
        if ($returnRequest->entitlements_held_at === null || $returnRequest->entitlements_restored_at !== null) {
            return;
        }

        if ($returnRequest->entitlement_points_paise > 0) {
            app(RedeemPointsService::class)->refundForOrder(
                $order->id,
                'Restored on receipt of return for order '.$order->order_no,
            );
        }

        if ($returnRequest->entitlement_credit_paise > 0) {
            $this->wallet->restoreRepurchaseCreditForOrder(
                $order->id,
                $returnRequest->entitlement_credit_paise,
                'Restored on receipt of return for order '.$order->order_no,
            );
        }

        $returnRequest->update(['entitlements_restored_at' => Carbon::now()]);
    }
}
