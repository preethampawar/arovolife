<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Ledger\Services\LedgerPoster;
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
    ) {}

    public function markPaid(Order $order, ?int $actorUserId = null): void
    {
        if ($order->status !== Order::STATUS_PLACED) {
            throw new RuntimeException("Cannot mark paid from status {$order->status}");
        }

        $this->db->transaction(function () use ($order, $actorUserId): void {
            $order->update([
                'status' => Order::STATUS_PAID,
                'paid_at' => Carbon::now(),
            ]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.paid',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => ['order_no' => $order->order_no, 'amount_paise' => $order->total_paise, 'payment_method' => $order->payment_method],
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
                    ['account' => 'liability.customer_prepayment', 'side' => 'debit',  'amount_paise' => $order->total_paise],
                    ['account' => 'revenue.sales',                 'side' => 'credit', 'amount_paise' => $taxable],
                    ['account' => 'liability.gst_output',          'side' => 'credit', 'amount_paise' => $order->gst_paise],
                ];

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
        // statutory return/refund path applies instead (Phase 3). No money has
        // moved yet for COD (unpaid) and the online prepayment liability is
        // settled by the refund flow later, so cancel only releases the goods.
        if (in_array($order->status, [Order::STATUS_SHIPPED, Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED, Order::STATUS_REFUNDED, Order::STATUS_CANCELLED], true)) {
            throw new RuntimeException("Cannot cancel order in status {$order->status}");
        }

        $oldStatus = $order->status;

        $this->db->transaction(function () use ($order, $reason, $actorUserId): void {
            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now(),
            ]);

            // Reverse any BV accrued at payment — a cancelled order is not a
            // completed sale, so no BV may remain against it (hard rule #2).
            // Idempotent + a no-op when nothing was accrued (e.g. unpaid COD).
            $this->bvLedger->reverse($order);

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
                'details' => ['order_no' => $order->order_no, 'reason' => $reason],
            ]);
        });

        event(new OrderStatusChanged($order->id, $oldStatus, Order::STATUS_CANCELLED));
    }
}
