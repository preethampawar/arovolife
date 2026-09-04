<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Payments\Services\RazorpayRefundService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Owns the state transitions of Order.
 *
 * Phase 2: ship → deliver → 30-day cooling-off → confirm
 * Phase 4 will add commission accrual on ship and unlock on cooling-off expiry.
 */
final class OrderStateMachine
{
    public const COOLING_OFF_DAYS = 30;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LedgerPoster $ledger,
        private readonly BvLedgerService $bvLedger,
        private readonly WalletService $wallet,
        private readonly RazorpayRefundService $refunds,
    ) {}

    /**
     * The only entry point that turns an order into a sale: it accrues BV and
     * fires the compensation engines behind it. Its sole caller is
     * `PaymentConfirmationService` (an architecture test enforces this), and
     * `$evidence` is what that service verified — the intent, the gateway
     * payment id, the confirming event — so the audit row ties the BV to a
     * verified payment rather than to a status flip (DSR Rule 5(1)(c)).
     *
     * @param  array<string, mixed>  $evidence
     */
    public function markPaid(Order $order, ?int $actorUserId = null, array $evidence = []): void
    {
        $this->db->transaction(function () use ($order, $actorUserId, $evidence): void {
            // Re-read under a row lock: the callback, the webhook, the
            // reconciler and the expiry sweeper can all reach this order
            // within the same second, and the status on the caller's model
            // may be seconds old.
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== Order::STATUS_PLACED) {
                throw new RuntimeException("Cannot mark paid from status {$locked->status}");
            }

            $order->update([
                'status' => Order::STATUS_PAID,
                'paid_at' => Carbon::now(),
            ]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.paid',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'before_hash' => hash('sha256', Order::STATUS_PLACED),
                'after_hash' => hash('sha256', Order::STATUS_PAID),
                'details' => array_merge(
                    ['order_no' => $order->order_no, 'amount_paise' => $order->total_paise, 'payment_method' => $order->payment_method],
                    $evidence,
                ),
            ]);

            // BV accrues as soon as payment is received (product-owner decision,
            // 2026-06-02 — ADR-0006 revised). No cooling-off gating on accrual;
            // a refund still reverses it via BvLedgerService::reverse(). No-op
            // unless the order is a self-consumption purchase with BV and
            // self-purchase BV is enabled.
            $this->bvLedger->accrue($order);
        });

        event(new OrderStatusChanged($order->id, Order::STATUS_PLACED, Order::STATUS_PAID));
    }

    public function markShipped(Order $order, ?int $actorUserId = null, ?string $carrier = null, ?string $trackingNo = null): void
    {
        if (! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_READY_TO_SHIP], true)) {
            throw new RuntimeException("Cannot ship from status {$order->status}");
        }

        $oldStatus = $order->status;

        $this->db->transaction(function () use ($order, $actorUserId, $carrier, $trackingNo): void {
            $order->update([
                'status' => Order::STATUS_SHIPPED,
                'shipped_at' => Carbon::now(),
                'ship_carrier' => $carrier,
                'ship_tracking_no' => $trackingNo,
            ]);

            // Revenue recognition: move customer_prepayment → sales + gst_output.
            if ($order->subtotal_paise > 0) {
                $taxable = $order->subtotal_paise - $order->gst_paise;
                $lines = [
                    ['account' => 'revenue.sales', 'side' => 'credit', 'amount_paise' => $taxable],
                ];

                // Only when cash was actually collected. An order settled
                // entirely in points and repurchase credit has total_paise = 0
                // and no prepayment was ever posted; the LedgerPoster rejects a
                // zero-amount line, so unguarded this threw and a zero-cash
                // order could never leave the warehouse. The entry balances
                // without it: the contra-revenue debits below carry the value.
                if ($order->total_paise > 0) {
                    $lines[] = ['account' => 'liability.customer_prepayment', 'side' => 'debit', 'amount_paise' => $order->total_paise];
                }

                // Only when GST was actually charged. A zero-rated cart (every
                // line at gst_rate_bp = 0 — exempt agricultural goods, for
                // instance) produces gst_paise = 0, and the LedgerPoster rejects
                // a zero-amount line, so posting this unguarded meant such an
                // order could never be marked shipped. The entry balances
                // without it: taxable already equals the full subtotal when
                // there is no tax. Mirrors the guard RefundOrder applies to the
                // same account on the way back out.
                if ($order->gst_paise > 0) {
                    $lines[] = ['account' => 'liability.gst_output', 'side' => 'credit', 'amount_paise' => $order->gst_paise];
                }

                // Shipping the customer paid is recognised as shipping revenue
                // on the credit side. It sits inside total_paise (the debit), so
                // without this credit the entry is out of balance by exactly the
                // shipping amount and the LedgerPoster (correctly) rejects it —
                // which surfaced as a 500 when marking any order with shipping as
                // shipped. Free-shipping orders (shipping_paise = 0) skip it.
                if ($order->shipping_paise > 0) {
                    $lines[] = ['account' => 'revenue.shipping', 'side' => 'credit', 'amount_paise' => $order->shipping_paise];
                }

                // A coupon discount is recorded as contra-revenue (debit) so
                // gross sales + GST output stay at the documented sale value
                // while the debit side equals the cash actually due
                // (total_paise = subtotal − discount + shipping). Without this the
                // entry would be out of balance by the discount amount and the
                // LedgerPoster would (correctly) reject it.
                if ($order->discount_paise > 0) {
                    $lines[] = ['account' => 'revenue.discounts', 'side' => 'debit', 'amount_paise' => $order->discount_paise];
                }

                // Redeem points settle part of the sale without cash, exactly
                // as a coupon does, so they need the same contra-revenue debit.
                // They also sit inside total_paise on the debit side, so without
                // this the entry is out of balance by the points amount, the
                // LedgerPoster rejects it, and a points-paid order can never be
                // marked shipped at all — the same failure the shipping-revenue
                // note above records having already happened once.
                if (($order->redeem_points_paise ?? 0) > 0) {
                    $lines[] = ['account' => 'revenue.discounts', 'side' => 'debit', 'amount_paise' => $order->redeem_points_paise];
                }

                // Repurchase-wallet credit settles part of the sale without
                // cash exactly as points do, and CheckoutService already took it
                // out of total_paise, so it needs the same contra-revenue debit.
                // Without it the entry is short by the credit amount, the
                // LedgerPoster rejects it, and an order paid partly from the
                // repurchase wallet can never be marked shipped — the third
                // instance of the failure the two notes above record.
                $repurchaseCreditPaise = $this->wallet->repurchaseCreditAppliedToOrder($order->id);
                if ($repurchaseCreditPaise > 0) {
                    $lines[] = ['account' => 'revenue.discounts', 'side' => 'debit', 'amount_paise' => $repurchaseCreditPaise];
                }

                $this->ledger->post(
                    sourceModule: 'Commerce',
                    sourceType: 'order.shipped',
                    sourceId: $order->id,
                    idempotencyKey: "order.shipped:{$order->id}",
                    lines: $lines,
                    memo: "Revenue recognised for {$order->order_no}",
                    createdByUserId: $actorUserId,
                );
            }

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.shipped',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'carrier' => $carrier,
                    'tracking_no' => $trackingNo,
                ],
            ]);
        });

        event(new OrderStatusChanged($order->id, $oldStatus, Order::STATUS_SHIPPED));
    }

    public function markDelivered(Order $order, ?int $actorUserId = null): OrderCoolingOff
    {
        if ($order->status !== Order::STATUS_SHIPPED) {
            throw new RuntimeException("Cannot mark delivered from status {$order->status}");
        }

        $coolingOff = $this->db->transaction(function () use ($order, $actorUserId): OrderCoolingOff {
            $deliveredAt = Carbon::now();

            $order->update([
                'status' => Order::STATUS_DELIVERED,
                'delivered_at' => $deliveredAt,
            ]);

            // Open the per-order cooling-off clock (ADR-0005)
            $coolingOff = OrderCoolingOff::create([
                'order_id' => $order->id,
                'opened_at' => $deliveredAt,
                'ends_at' => $deliveredAt->copy()->addDays(self::COOLING_OFF_DAYS),
                'status' => OrderCoolingOff::STATUS_OPEN,
            ]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.delivered',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'cooling_off_ends_at' => $coolingOff->ends_at->toIso8601String(),
                ],
            ]);

            return $coolingOff;
        });

        event(new OrderStatusChanged($order->id, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED));

        return $coolingOff;
    }

    public function expireCoolingOff(Order $order, ?int $actorUserId = null): void
    {
        $coolingOff = $order->coolingOff;
        if ($coolingOff === null || $coolingOff->status !== OrderCoolingOff::STATUS_OPEN) {
            return;
        }
        if ($coolingOff->ends_at->isFuture()) {
            return;
        }

        $this->db->transaction(function () use ($order, $coolingOff, $actorUserId): void {
            $coolingOff->update(['status' => OrderCoolingOff::STATUS_EXPIRED]);
            $order->update(['status' => Order::STATUS_CONFIRMED]);

            // BV is NOT accrued here — it was already accrued on payment
            // (markPaid), per ADR-0006 (revised 2026-06-02). This transition
            // only closes the statutory refund window and confirms the order.

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.cooling_off_expired',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => ['order_no' => $order->order_no],
            ]);
        });

        event(new OrderStatusChanged($order->id, Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED));
    }

    public function cancel(Order $order, string $reason, ?int $actorUserId = null): void
    {
        // Only pre-shipment orders can be cancelled. Once shipped/delivered the
        // statutory return/refund path applies instead (Phase 3). Nothing is
        // owed for COD (nothing was collected), so cancel releases the goods,
        // returns the non-cash settlements (points, repurchase credit) and
        // unwinds the online prepayment liability.
        if (in_array($order->status, [Order::STATUS_SHIPPED, Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED, Order::STATUS_REFUNDED, Order::STATUS_CANCELLED], true)) {
            throw new RuntimeException("Cannot cancel order in status {$order->status}");
        }

        $oldStatus = $order->status;

        $this->db->transaction(function () use ($order, $reason, $actorUserId, &$oldStatus): void {
            // Re-read under a row lock. The expiry sweeper decides "still
            // unpaid" from a model it loaded a moment ago, and a webhook can
            // mark the order paid in between; without the lock the sweeper
            // would cancel a paid order and reverse the prepayment as though
            // nothing had been collected, with the money still at the gateway.
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if (in_array($locked->status, [Order::STATUS_SHIPPED, Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED, Order::STATUS_REFUNDED, Order::STATUS_CANCELLED], true)) {
                throw new RuntimeException("Cannot cancel order in status {$locked->status}");
            }
            $order->setRawAttributes($locked->getAttributes(), true);
            $oldStatus = $locked->status;

            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now(),
            ]);

            // Reverse any BV accrued at payment — a cancelled order is not a
            // completed sale, so no BV may remain against it (hard rule #2).
            // Idempotent + a no-op when nothing was accrued (e.g. unpaid COD).
            $this->bvLedger->reverse($order);

            // Give back whatever was settled without cash, in the form it was
            // spent — the same restoration RefundOrder performs, for the same
            // reason. A cancelled order is not a sale, so the buyer must not be
            // left out of pocket in points or repurchase credit; before this,
            // cancelling silently destroyed both. Nothing is unwound in the
            // general ledger here because nothing was posted: the
            // contra-revenue debits are written at ship time, and cancel is
            // only reachable pre-shipment.
            //
            // Both helpers are idempotent and no-op when the order used none.
            $redeemPointsPaise = (int) ($order->redeem_points_paise ?? 0);
            if ($redeemPointsPaise > 0) {
                app(RedeemPointsService::class)->refundForOrder(
                    $order->id,
                    'Restored on cancellation of order '.$order->order_no,
                );
            }

            $repurchaseCreditPaise = $this->wallet->repurchaseCreditAppliedToOrder($order->id);
            if ($repurchaseCreditPaise > 0) {
                $this->wallet->restoreRepurchaseCreditForOrder(
                    $order->id,
                    $repurchaseCreditPaise,
                    'Restored on cancellation of order '.$order->order_no,
                );
            }

            // Unwind the prepayment liability. Placement posts
            // Dr asset.cash.gateway.razorpay / Cr liability.customer_prepayment
            // for every online order; without this the credit stood for ever,
            // so the books read "money held, goods still owed" against an order
            // that will never ship. Where it goes depends on whether the money
            // actually arrived:
            //
            //   paid   -> Cr liability.refund_payable. The cash really is at the
            //             gateway, so the obligation becomes a refund pending
            //             settlement — the same account, and the same Phase-3
            //             hand-off, that RefundOrder uses.
            //   unpaid -> Cr asset.cash.gateway.razorpay. Checkout posts the
            //             entry at placement, before the gateway confirms, so
            //             nothing was ever collected: the placement entry is
            //             simply undone and there is nothing to refund.
            //
            // The amount is read back from the placement transaction rather than
            // recomputed, so the reversal can never disagree with what was
            // posted; its absence (COD, or a total fully covered by points and
            // repurchase credit) means there is nothing to unwind.
            $placementTx = LedgerTx::where('idempotency_key', "order.placed:{$order->id}")->first();
            $prepaymentPaise = $placementTx === null
                ? 0
                : (int) LedgerEntry::where('ledger_tx_id', $placementTx->id)
                    ->where('side', 'debit')
                    ->sum('amount_paise');

            $wasPaid = $order->getAttribute('paid_at') !== null;
            if ($prepaymentPaise > 0) {
                $this->ledger->transfer(
                    sourceModule: 'Commerce',
                    sourceType: 'order.cancelled',
                    sourceId: $order->id,
                    idempotencyKey: "order.cancelled:{$order->id}",
                    debitAccount: 'liability.customer_prepayment',
                    creditAccount: $wasPaid
                        ? 'liability.refund_payable'
                        : 'asset.cash.gateway.razorpay',
                    amountPaise: $prepaymentPaise,
                    memo: "Cancelled order {$order->order_no}",
                    createdByUserId: $actorUserId,
                );

                // The money really was collected: send it back through the
                // gateway, for exactly the prepayment read back above — the
                // cash the buyer handed over, never the order total.
                if ($wasPaid) {
                    $this->refunds->createForCancellation($order, $prepaymentPaise, $actorUserId);
                }
            }

            // Release the inventory reserved at placement so the stock is
            // available again (tracked variants only; mirrors CheckoutService).
            $order->loadMissing('items.variant.inventory');
            foreach ($order->items as $item) {
                /** @var OrderItem $item */
                $variant = $item->variant;
                if ($variant !== null && $variant->inventory_policy === 'track' && $variant->inventory !== null) {
                    $release = min($item->qty, (int) $variant->inventory->reserved);
                    if ($release > 0) {
                        $variant->inventory->decrement('reserved', $release);
                    }
                }
            }

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.cancelled',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'reason' => $reason,
                    'redeem_points_restored_paise' => $redeemPointsPaise,
                    'repurchase_credit_restored_paise' => $repurchaseCreditPaise,
                    'prepayment_reversed_paise' => $prepaymentPaise,
                ],
            ]);
        });

        event(new OrderStatusChanged($order->id, $oldStatus, Order::STATUS_CANCELLED));
    }
}
