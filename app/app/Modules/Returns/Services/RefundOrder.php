<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Commerce\Services\RedeemPointsService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Returns\Events\OrderRefundApproved;
use App\Modules\Returns\Models\ReturnRequest;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Executes the money + state side of a refund (ADR-0009).
 *
 * One DB transaction:
 *   1. Guard: order not already refund_approved (idempotency).
 *   2. Post double-entry ledger reversal (revenue.sales + gst_output? + shipping? → refund_payable).
 *   3. Reverse BV via BvLedgerService::reverse().
 *   4. Close the cooling-off clock when reason = cooling_off.
 *   5. Transition order → refund_approved.
 *   6. Mark ReturnRequest → approved.
 *   7. Audit log.
 *   8. Emit OrderRefundApproved.
 *
 * Ledger reversal amounts per matrix (ADR-0009 §Money):
 *
 *   cooling_off (refund_gst=true, includes shipping):
 *     Dr revenue.sales       [taxable = subtotal - gst]
 *     Dr liability.gst_output [gst_paise]
 *     Dr revenue.shipping    [shipping_paise]   (skipped if 0)
 *     Cr revenue.discounts   [discount_paise]   (skipped if 0; undo contra-revenue)
 *     Cr liability.refund_payable [total_paise]
 *
 *   damage/dissatisfaction, saleable (refund_gst=true, no shipping):
 *     Dr revenue.sales       [taxable]
 *     Dr liability.gst_output [gst_paise]
 *     Cr liability.refund_payable [subtotal_paise]
 *
 *   damage/dissatisfaction, non-saleable (refund_gst=false):
 *   general_buyback / termination_buyback, saleable (refund_gst=false):
 *     Dr revenue.sales       [taxable]
 *     Cr liability.refund_payable [taxable]
 *
 * Redeemed points reduce the payable in every case and are credited back to
 * revenue.discounts, undoing the contra-revenue debit `markShipped` posted when
 * they were spent. When they cover the whole net product value the payable is
 * zero and its line is omitted rather than posted — see below.
 *
 * Every case is balanced by construction (verified by LedgerPoster).
 */
final class RefundOrder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LedgerPoster $ledger,
        private readonly BvLedgerService $bvLedger,
        private readonly BuybackMatrix $matrix,
    ) {}

    public function execute(
        Order $order,
        ReturnRequest $returnRequest,
        string $reason,
        bool $saleable,
        ?int $actorUserId,
    ): void {
        if ($order->status === Order::STATUS_REFUND_APPROVED) {
            return; // idempotent
        }

        if (! in_array($order->status, [
            Order::STATUS_DELIVERED,
            Order::STATUS_CONFIRMED,
            Order::STATUS_REFUND_REQUESTED,
            Order::STATUS_REFUND_INSPECTION,
        ], true)) {
            throw new RuntimeException("Cannot refund order in status {$order->status}");
        }

        $policy = $this->matrix->policy($reason, $saleable);
        if (! $policy['eligible']) {
            throw new RuntimeException("Refund not eligible for reason={$reason}, saleable=".($saleable ? 'true' : 'false'));
        }

        $taxable = $order->subtotal_paise - $order->gst_paise;
        $isCoolingOff = $reason === ReturnRequest::REASON_COOLING_OFF;
        $gstRefundPaise = $policy['refund_gst'] ? $order->gst_paise : 0;
        $shippingRefundPaise = $isCoolingOff ? $order->shipping_paise : 0;
        $discountPaise = $isCoolingOff ? $order->discount_paise : 0;
        // Redeem points were never cash, so they must never come back as cash.
        // Without this line a buyer could pay ₹180 cash plus 1,000 points on a
        // ₹1,180 order, cancel inside the cooling-off window and receive ₹1,180
        // — turning a non-withdrawable discount entitlement into a cash-out
        // route, and moving company money against consideration never received.
        // The points side is returned in points below.
        $redeemPointsPaise = (int) ($order->redeem_points_paise ?? 0);
        $netRefundPaise = $taxable + $gstRefundPaise + $shippingRefundPaise - $discountPaise - $redeemPointsPaise;

        $idempotencyKey = "refund:{$order->id}";

        $this->db->transaction(function () use (
            $order, $returnRequest, $reason, $isCoolingOff,
            $taxable, $gstRefundPaise, $shippingRefundPaise, $discountPaise, $netRefundPaise,
            $redeemPointsPaise, $idempotencyKey, $actorUserId,
        ): void {
            // Give the points back, in points, inside the same transaction that
            // reverses the money. Idempotent, so a retried refund cannot mint
            // them. Published §11.2 promises restoration; before this nothing
            // called it and the points were simply destroyed.
            if ($redeemPointsPaise > 0) {
                app(RedeemPointsService::class)->refundForOrder(
                    $order->id,
                    'Restored on refund of order '.$order->order_no,
                );
            }

            // Build balanced ledger reversal lines.
            $lines = [
                ['account' => 'revenue.sales', 'side' => 'debit', 'amount_paise' => $taxable],
            ];

            if ($gstRefundPaise > 0) {
                $lines[] = ['account' => 'liability.gst_output', 'side' => 'debit', 'amount_paise' => $gstRefundPaise];
            }

            if ($shippingRefundPaise > 0) {
                $lines[] = ['account' => 'revenue.shipping', 'side' => 'debit', 'amount_paise' => $shippingRefundPaise];
            }

            if ($discountPaise > 0) {
                // Undo the contra-revenue (reverse the debit → credit it back).
                $lines[] = ['account' => 'revenue.discounts', 'side' => 'credit', 'amount_paise' => $discountPaise];
            }

            // The same reversal for points. `markShipped` debited
            // revenue.discounts when they were spent, so the refund credits it
            // back — and the asymmetry with $discountPaise above is deliberate:
            // the coupon is only unwound inside the cooling-off window, while
            // the cash reduction for points is unconditional, so its
            // contra-reversal must be unconditional too.
            //
            // Without this line debits and credits differ by exactly
            // $redeemPointsPaise, LedgerPoster throws inside the transaction,
            // and a distributor who paid partly in points cannot cancel at all
            // — the statutory one-click cooling-off refund would fail outright
            // for those orders.
            if ($redeemPointsPaise > 0) {
                $lines[] = ['account' => 'revenue.discounts', 'side' => 'credit', 'amount_paise' => $redeemPointsPaise];
            }

            // No cash went out, so no cash comes back. Not a corner case:
            // checkout caps points at the net product value and the checkout
            // screen offers exactly that cap as the input's max, so a
            // distributor who redeems the maximum offered pays nothing in cash
            // and lands here. The LedgerPoster rejects a zero-amount line, so
            // an unguarded credit rolls the whole refund back — including the
            // points restoration — and a fully points-settled order could never
            // be bought back at all (T&C §8, R-05). The entry still balances
            // without it: Dr revenue.sales against Cr revenue.discounts.
            if ($netRefundPaise > 0) {
                $lines[] = ['account' => 'liability.refund_payable', 'side' => 'credit', 'amount_paise' => $netRefundPaise];
            }

            $this->ledger->post(
                sourceModule: 'Returns',
                sourceType: 'order.refund_approved',
                sourceId: $order->id,
                idempotencyKey: $idempotencyKey,
                lines: $lines,
                memo: "Refund approved for {$order->order_no} (reason: {$reason})",
                createdByUserId: $actorUserId,
            );

            // Reverse BV — a refunded order must leave no BV behind (hard rule #2).
            $this->bvLedger->reverse($order);

            // Close the per-order cooling-off clock (if applicable).
            if ($isCoolingOff) {
                $coolingOff = $order->coolingOff;
                if ($coolingOff !== null && $coolingOff->status === OrderCoolingOff::STATUS_OPEN) {
                    $coolingOff->update(['status' => OrderCoolingOff::STATUS_CANCELLED]);
                }
            }

            // Advance order to refund_approved (Phase-2 terminal: ledger moved,
            // gateway settlement deferred to Phase 3).
            $order->update([
                'status' => Order::STATUS_REFUND_APPROVED,
                'refund_approved_at' => Carbon::now(),
            ]);

            // Mark the return request as approved.
            $returnRequest->update(['status' => ReturnRequest::STATUS_APPROVED]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.refund_approved',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'reason' => $reason,
                    'net_refund_paise' => $netRefundPaise,
                    'idempotency_key' => $idempotencyKey,
                ],
            ]);
        });

        event(new OrderRefundApproved(
            orderId: $order->id,
            returnRequestId: $returnRequest->id,
            reason: $reason,
            netRefundPaise: $netRefundPaise,
            idempotencyKey: $idempotencyKey,
        ));
    }
}
