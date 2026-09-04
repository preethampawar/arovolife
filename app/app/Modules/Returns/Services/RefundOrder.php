<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Commerce\Services\RedeemPointsService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Payments\Services\RazorpayRefundService;
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
 * Repurchase-wallet credit applied at checkout is treated identically: out of
 * the cash payable, credited back to revenue.discounts, and returned to the
 * repurchase wallet in repurchase credit (R-60).
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
        private readonly WalletService $wallet,
        private readonly RazorpayRefundService $refunds,
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
        // The coupon comes off for every reason: a discount the buyer never
        // paid cannot be refunded as cash. It used to be unwound only inside
        // cooling-off, which on a damage or dissatisfaction return refunded
        // `discount − shipping` more cash than was ever collected — the same
        // conversion of a non-cash element into cash that R-60 closed for the
        // repurchase wallet.
        $discountPaise = $order->discount_paise;
        // Redeem points were never cash, so they must never come back as cash.
        // Without this line a buyer could pay ₹180 cash plus 1,000 points on a
        // ₹1,180 order, cancel inside the cooling-off window and receive ₹1,180
        // — turning a non-withdrawable discount entitlement into a cash-out
        // route, and moving company money against consideration never received.
        // The points side is returned in points below.
        $redeemPointsPaise = (int) ($order->redeem_points_paise ?? 0);
        // Same reasoning for the repurchase wallet, and the stakes are higher:
        // that balance was withheld from a bonus payout to fund the mandatory
        // monthly repurchase and is non-withdrawable by design. Refunding it as
        // cash would convert it into a withdrawal route and pay out money the
        // buyer never handed over (R-60). It goes back as repurchase credit
        // inside the transaction below.
        //
        // Capped at what is left of the refundable value after the points,
        // because the credit may have paid for things this policy does not
        // refund — most often the shipping, which only cooling-off returns.
        // Uncapped, a fully credit-paid order refunded without its shipping
        // would drive the payable negative, its line would be omitted, and the
        // whole entry would fail the LedgerPoster's balance check.
        $refundableGrossPaise = $taxable + $gstRefundPaise + $shippingRefundPaise - $discountPaise;
        $repurchaseCreditPaise = min(
            $this->wallet->repurchaseCreditAppliedToOrder($order->id),
            max(0, $refundableGrossPaise - $redeemPointsPaise),
        );
        $netRefundPaise = $refundableGrossPaise - $redeemPointsPaise - $repurchaseCreditPaise;

        $idempotencyKey = "refund:{$order->id}";

        $this->db->transaction(function () use (
            $order, $returnRequest, $reason, $isCoolingOff,
            $taxable, $gstRefundPaise, $shippingRefundPaise, $discountPaise, $netRefundPaise,
            $redeemPointsPaise, $repurchaseCreditPaise, $idempotencyKey, $actorUserId,
        ): void {
            // Everything of value goes back to the buyer in the form it was
            // paid: points as points, repurchase credit as credit, cash as
            // cash. For a cooling-off cancellation all three wait for the
            // returned goods (terms §8; ReturnReceiptService releases them
            // together) — the cancellation itself, the ledger and BV reversal
            // below, and the closing of the clock are instant. For every
            // inspected return the goods are already in hand, so the
            // entitlements are restored here. Idempotent either way.
            if ($isCoolingOff) {
                $returnRequest->update([
                    'entitlement_points_paise' => $redeemPointsPaise,
                    'entitlement_credit_paise' => $repurchaseCreditPaise,
                    'entitlements_held_at' => Carbon::now(),
                ]);
            } else {
                if ($redeemPointsPaise > 0) {
                    app(RedeemPointsService::class)->refundForOrder(
                        $order->id,
                        'Restored on refund of order '.$order->order_no,
                    );
                }

                if ($repurchaseCreditPaise > 0) {
                    $this->wallet->restoreRepurchaseCreditForOrder(
                        $order->id,
                        $repurchaseCreditPaise,
                        'Restored on refund of order '.$order->order_no,
                    );
                }
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

            // The same reversal for points: `markShipped` debited
            // revenue.discounts when they were spent, so the refund credits it
            // back. Coupon, points and credit are all unconditional here, as
            // their cash reductions are — a missing contra line leaves the
            // entry short by exactly that amount, LedgerPoster throws inside
            // the transaction, and the refund cannot be executed at all.
            if ($redeemPointsPaise > 0) {
                $lines[] = ['account' => 'revenue.discounts', 'side' => 'credit', 'amount_paise' => $redeemPointsPaise];
            }

            // The repurchase credit's contra-reversal, unconditional for the
            // same reason as the points one: `markShipped` debited
            // revenue.discounts when it settled part of the sale, and the cash
            // reduction above is unconditional, so the credit must be too.
            if ($repurchaseCreditPaise > 0) {
                $lines[] = ['account' => 'revenue.discounts', 'side' => 'credit', 'amount_paise' => $repurchaseCreditPaise];
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

            // The cash back through the gateway it came in by — the net cash
            // only, never the order total (I4). Held for a cooling-off return
            // until the goods are received; sent at once after an inspection.
            // Null when nothing was collected through a gateway (COD): the
            // refund_payable stands and finance settles it by hand.
            $this->refunds->createForReturn($order, $netRefundPaise, $reason, hold: $isCoolingOff, actorUserId: $actorUserId);

            // Close the per-order cooling-off clock (if applicable).
            if ($isCoolingOff) {
                $coolingOff = $order->coolingOff;
                if ($coolingOff !== null && $coolingOff->status === OrderCoolingOff::STATUS_OPEN) {
                    $coolingOff->update(['status' => OrderCoolingOff::STATUS_CANCELLED]);
                }
            }

            // Advance order to refund_approved: the ledger has moved; the
            // order becomes `refunded` only when the gateway (or a manual
            // NEFT) settles the payable.
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
                    'redeem_points_paise' => $redeemPointsPaise,
                    'repurchase_credit_paise' => $repurchaseCreditPaise,
                    'discount_paise' => $discountPaise,
                    'entitlements' => $isCoolingOff ? 'held_pending_receipt' : 'restored',
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
