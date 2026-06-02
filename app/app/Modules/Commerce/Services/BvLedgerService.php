<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for personal Business Volume accumulation
 * (ADR-0006). Personal BV is a distributor's own-purchase (self-consumption)
 * BV, counted only once an order's 30-day cooling-off has closed and reversed
 * if the order is later refunded.
 *
 * Nothing here ever writes a credit before cooling-off expiry — `accrue()` is
 * called from {@see OrderStateMachine::expireCoolingOff()} — so the statutory
 * window cannot be bypassed by construction.
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

        BvLedgerEntry::firstOrCreate(
            ['order_id' => $order->id, 'type' => BvLedgerEntry::TYPE_ACCRUAL],
            [
                'distributor_id' => $order->attributed_distributor_id,
                'bv_paise' => $bvPaise,
                'effective_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Reverse a previously accrued order (refund). Writes a negative entry that
     * nets the order's BV back to zero. A no-op if nothing was accrued (e.g. a
     * refund during cooling-off, where BV was never counted) — preserving the
     * cooling-off guarantee. Idempotent. Called by the Phase-3 refund pipeline.
     */
    public function reverse(Order $order): void
    {
        $accrual = BvLedgerEntry::where('order_id', $order->id)
            ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
            ->first();

        if ($accrual === null) {
            return;
        }

        BvLedgerEntry::firstOrCreate(
            ['order_id' => $order->id, 'type' => BvLedgerEntry::TYPE_REVERSAL],
            [
                'distributor_id' => $accrual->distributor_id,
                'bv_paise' => -$accrual->bv_paise,
                'effective_at' => Carbon::now(),
            ],
        );
    }

    /** A distributor's total accumulated personal BV (in paise). */
    public function totalPersonalBvPaise(int $distributorId): int
    {
        return (int) BvLedgerEntry::where('distributor_id', $distributorId)->sum('bv_paise');
    }

    private function shouldAccrue(Order $order): bool
    {
        return $order->self_consumption
            && $order->attributed_distributor_id !== null
            && $this->selfPurchaseEarnsBv();
    }

    private function selfPurchaseEarnsBv(): bool
    {
        return DB::table('settings')
            ->where('key', 'commerce.self_purchase.earns_bv')
            ->value('value') === 'true';
    }
}
