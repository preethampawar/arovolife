<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
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

            // COD: cash is received at THIS moment (collection), so post the
            // cash-in entry the online flow already posted at placement —
            // Dr bank cash, Cr customer_prepayment. Online orders skip this
            // (their prepayment liability already exists). Either way, by the
            // time an order is PAID the prepayment liability is on the books,
            // so revenue recognition on ship works identically for both.
            if ($order->payment_method === Order::PAYMENT_COD && $order->total_paise > 0) {
                $this->ledger->transfer(
                    sourceModule: 'Commerce',
                    sourceType: 'order.cod_collected',
                    sourceId: $order->id,
                    idempotencyKey: "order.cod_collected:{$order->id}",
                    debitAccount: 'asset.cash.bank.settlement',
                    creditAccount: 'liability.customer_prepayment',
                    amountPaise: $order->total_paise,
                    memo: "COD collected for {$order->order_no}",
                );
            }

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.paid',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => ['order_no' => $order->order_no, 'amount_paise' => $order->total_paise, 'payment_method' => $order->payment_method],
            ]);
        });
    }

    public function markShipped(Order $order, ?int $actorUserId = null): void
    {
        if (! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_READY_TO_SHIP], true)) {
            throw new RuntimeException("Cannot ship from status {$order->status}");
        }

        $this->db->transaction(function () use ($order, $actorUserId): void {
            $order->update([
                'status' => Order::STATUS_SHIPPED,
                'shipped_at' => Carbon::now(),
            ]);

            // Revenue recognition: move customer_prepayment → sales + gst_output.
            if ($order->subtotal_paise > 0) {
                $taxable = $order->subtotal_paise - $order->gst_paise;
                $lines = [
                    ['account' => 'liability.customer_prepayment', 'side' => 'debit',  'amount_paise' => $order->total_paise],
                    ['account' => 'revenue.sales',                 'side' => 'credit', 'amount_paise' => $taxable],
                    ['account' => 'liability.gst_output',          'side' => 'credit', 'amount_paise' => $order->gst_paise],
                ];

                // A coupon discount is recorded as contra-revenue (debit) so
                // gross sales + GST output stay at the documented sale value
                // while the debit side equals the cash actually due
                // (total_paise = subtotal − discount). Without this the entry
                // would be out of balance by the discount amount and the
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
                'details' => ['order_no' => $order->order_no],
            ]);
        });
    }

    public function markDelivered(Order $order, ?int $actorUserId = null): OrderCoolingOff
    {
        if ($order->status !== Order::STATUS_SHIPPED) {
            throw new RuntimeException("Cannot mark delivered from status {$order->status}");
        }

        return $this->db->transaction(function () use ($order, $actorUserId): OrderCoolingOff {
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

            // The cooling-off window has closed, so the order's BV is now
            // firmly counted toward the buyer's personal BV (ADR-0006). This is
            // the ONLY place personal BV is accrued — keeping it after expiry
            // makes the statutory window impossible to bypass. No-op unless the
            // order is a self-consumption purchase and self-purchase BV is on.
            $this->bvLedger->accrue($order);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.cooling_off_expired',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => ['order_no' => $order->order_no],
            ]);
        });
    }

    public function cancel(Order $order, string $reason, ?int $actorUserId = null): void
    {
        if (in_array($order->status, [Order::STATUS_SHIPPED, Order::STATUS_DELIVERED, Order::STATUS_CONFIRMED, Order::STATUS_REFUNDED], true)) {
            throw new RuntimeException("Cannot cancel order in status {$order->status}");
        }

        $order->update([
            'status' => Order::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now(),
        ]);

        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => 'order.cancelled',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'details' => ['order_no' => $order->order_no, 'reason' => $reason],
        ]);
    }
}
