<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\DTOs\BvBreakdown;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for personal Business Volume accumulation
 * (ADR-0006, revised 2026-06-02). Personal BV is a distributor's own-purchase
 * (self-consumption) BV. It accrues when the order is paid
 * ({@see OrderStateMachine::markPaid()}) and is reversed if the order is later
 * refunded OR cancelled ({@see OrderStateMachine::cancel()}), so a non-sale
 * never leaves net BV behind (hard rule #2). BV is only ever attached to a
 * paid product sale — never to registration or recruitment.
 */
final class BvLedgerService
{
    /**
     * Credit the order's BV to the attributing distributor's personal ledger.
     * Idempotent (UNIQUE(order_id, type)) and a no-op when the order is not a
     * self-consumption purchase, BV is zero, or self-purchase BV is disabled.
     */
    public function accrue(Order $order): void
    {
        if (! $this->shouldAccrue($order)) {
            return;
        }

        $order->loadMissing('items');
        $bvPaise = $order->bvTotalPaise();
        if ($bvPaise <= 0) {
            return;
        }

        $entry = BvLedgerEntry::firstOrCreate(
            ['order_id' => $order->id, 'type' => BvLedgerEntry::TYPE_ACCRUAL],
            [
                'distributor_id' => $order->attributed_distributor_id,
                'bv_paise' => $bvPaise,
                'effective_at' => Carbon::now(),
            ],
        );

        $this->audit('bv.accrued', $entry);
    }

    /**
     * Reverse a previously accrued order's BV. Writes a negative entry that nets
     * the order's BV back to zero. A no-op if nothing was accrued (e.g. an
     * unpaid COD order or one with no BV). Idempotent. Called when an order is
     * cancelled ({@see OrderStateMachine::cancel()}) and by the Phase-3 refund
     * pipeline — so a non-sale never leaves BV behind (hard rule #2).
     */
    public function reverse(Order $order): void
    {
        $accrual = BvLedgerEntry::where('order_id', $order->id)
            ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
            ->first();

        if ($accrual === null) {
            return;
        }

        $entry = BvLedgerEntry::firstOrCreate(
            ['order_id' => $order->id, 'type' => BvLedgerEntry::TYPE_REVERSAL],
            [
                'distributor_id' => $accrual->distributor_id,
                'bv_paise' => -$accrual->bv_paise,
                'effective_at' => Carbon::now(),
            ],
        );

        $this->audit('bv.reversed', $entry);
    }

    /**
     * Audit a BV ledger write (before/after traceability for the sale-linked
     * credit store, R-22). Only logs an actual write, not an idempotent no-op.
     */
    private function audit(string $action, BvLedgerEntry $entry): void
    {
        if (! $entry->wasRecentlyCreated) {
            return;
        }

        AuditLog::create([
            'action' => $action,
            'subject_type' => 'bv_ledger_entry',
            'subject_id' => $entry->id,
            'details' => [
                'order_id' => $entry->order_id,
                'distributor_id' => $entry->distributor_id,
                'bv_paise' => $entry->bv_paise,
            ],
        ]);
    }

    /** A distributor's total accumulated personal BV (in paise). */
    public function totalPersonalBvPaise(int $distributorId): int
    {
        return (int) BvLedgerEntry::query()->forDistributor($distributorId)->sum('bv_paise');
    }

    /**
     * A distributor's accrued / reversed / net personal BV over an optional
     * date window (in paise). The single source of the accrued/reversed/net
     * definition for the admin BV-ledger report.
     */
    public function breakdownForDistributor(int $distributorId, ?Carbon $from = null, ?Carbon $to = null): BvBreakdown
    {
        $base = BvLedgerEntry::query()->forDistributor($distributorId)->dateRange($from, $to);

        $accrued = (int) (clone $base)->where('type', BvLedgerEntry::TYPE_ACCRUAL)->sum('bv_paise');
        $reversed = (int) (clone $base)->where('type', BvLedgerEntry::TYPE_REVERSAL)->sum('bv_paise');

        return new BvBreakdown($accrued, $reversed, $accrued + $reversed);
    }

    private function shouldAccrue(Order $order): bool
    {
        if ($order->attributed_distributor_id === null) {
            return false;
        }

        // Self-consumption (distributor buying for themselves) is gated by the
        // admin setting — the company may disable self-purchase BV to prevent
        // artificial volume accumulation.
        if ($order->self_consumption) {
            return $this->selfPurchaseEarnsBv();
        }

        // Customer sale via Easy Purchase / shared-cart link — the attributed
        // distributor always earns BV (hard rule #2: BV tied to product sale).
        return true;
    }

    private function selfPurchaseEarnsBv(): bool
    {
        return DB::table('settings')
            ->where('key', 'commerce.self_purchase.earns_bv')
            ->value('value') === 'true';
    }
}
