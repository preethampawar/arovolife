<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Commerce\Models\Order;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerTx;

/**
 * What the books say is owed back to a buyer, read from the ledger itself.
 */
final class RefundPayable
{
    /**
     * The cash credited to `liability.refund_payable` for the order — the
     * amount a manual settlement must discharge when no gateway payment exists
     * to refund against (R-68). A refund-approved order owes what its
     * `order.refund_approved` entry (`refund:{id}`) credited; a cancelled paid
     * order owes what its cancellation entry (`order.cancelled:{id}`) credited.
     */
    public static function owedOutsideGateway(Order $order): int
    {
        $key = $order->status === Order::STATUS_CANCELLED
            ? "order.cancelled:{$order->id}"
            : "refund:{$order->id}";

        $tx = LedgerTx::where('idempotency_key', $key)->first();
        if ($tx === null) {
            return 0;
        }

        return (int) LedgerEntry::query()
            ->where('ledger_tx_id', $tx->id)
            ->where('side', 'credit')
            ->whereHas('account', fn ($q) => $q->where('code', 'liability.refund_payable'))
            ->sum('amount_paise');
    }

    /** Finance has already recorded the NEFT that discharged this order's payable. */
    public static function settledManually(Order $order): bool
    {
        return LedgerTx::where('idempotency_key', "refund.manual.order:{$order->id}")->exists();
    }
}
