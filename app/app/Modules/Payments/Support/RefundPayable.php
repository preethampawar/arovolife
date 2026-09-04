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
     * The cash credited to `liability.refund_payable` by the order's
     * `order.refund_approved` entry — the amount a manual settlement must
     * discharge when no gateway payment exists to refund against (R-68).
     */
    public static function owedOutsideGateway(Order $order): int
    {
        $tx = LedgerTx::where('idempotency_key', "refund:{$order->id}")->first();
        if ($tx === null) {
            return 0;
        }

        return (int) LedgerEntry::query()
            ->where('ledger_tx_id', $tx->id)
            ->where('side', 'credit')
            ->whereHas('account', fn ($q) => $q->where('code', 'liability.refund_payable'))
            ->sum('amount_paise');
    }
}
