<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\WalletLedgerEntry;
use Illuminate\Support\Collection;

class WalletService
{
    public function balancePaise(int $distributorId): int
    {
        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->sum('amount_paise');
    }

    /**
     * Positive (credit) totals grouped by entry type, optionally from a date.
     *
     * The income dashboard's per-bonus summary reads the wallet ledger rather
     * than the per-engine result tables so that "credited to wallet" means
     * exactly that for every bonus, from one source — held/suspended engine
     * rows never appear here because they were never credited.
     *
     * @return array<string, int> type => total paise
     */
    public function creditTotalsByType(int $distributorId, ?\DateTimeInterface $from = null): array
    {
        return WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('amount_paise', '>', 0)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->groupBy('type')
            ->selectRaw('type, SUM(amount_paise) as total_paise')
            ->pluck('total_paise', 'type')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    public function credit(
        int $distributorId,
        int $amountPaise,
        string $type,
        ?int $referenceId = null,
        ?string $referenceType = null,
        ?string $memo = null,
    ): WalletLedgerEntry {
        return WalletLedgerEntry::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'amount_paise' => abs($amountPaise),  // always positive for credits
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'memo' => $memo,
        ]);
    }

    public function debit(
        int $distributorId,
        int $amountPaise,
        string $type,
        ?int $referenceId = null,
        ?string $referenceType = null,
        ?string $memo = null,
    ): WalletLedgerEntry {
        return WalletLedgerEntry::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'amount_paise' => -abs($amountPaise),  // always negative for debits
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'memo' => $memo,
        ]);
    }

    /**
     * Running balance of the repurchase wallet for a distributor.
     *
     * Credits: every `repurchase_deduction` entry (positive amount_paise).
     * Debits:  every `repurchase_wallet_used` entry (negative amount_paise,
     *          stored as abs() by WalletService::debit()).
     *
     * Returns the net balance, floored at 0. Cannot go negative.
     */
    public function repurchaseWalletBalancePaise(int $distributorId): int
    {
        $credits = (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', 'repurchase_deduction')
            ->sum('amount_paise');

        $debits = abs((int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', 'repurchase_wallet_used')
            ->sum('amount_paise'));

        return max(0, $credits - $debits);
    }

    /**
     * The absolute amount of repurchase credit that was applied to a specific
     * order (reference_type = 'order', reference_id = order id).
     * Returns 0 if none was applied.
     */
    public function repurchaseCreditAppliedToOrder(int $orderId): int
    {
        return abs((int) WalletLedgerEntry::where('reference_id', $orderId)
            ->where('reference_type', 'order')
            ->where('type', 'repurchase_wallet_used')
            ->sum('amount_paise'));
    }

    /**
     * Sum of positive unswept credits for a distributor filtered to specific entry types.
     * Used by PayoutService to compute per-stream gross before sweeping.
     *
     * @param  string[]  $types
     */
    public function sumUnsweptByTypes(int $distributorId, array $types): int
    {
        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', $types)
            ->whereNull('swept_by_payout_batch_id')
            ->where('amount_paise', '>', 0)
            ->sum('amount_paise');
    }

    /**
     * Running balance ledger with cumulative sum, ordered by created_at.
     * Capped at the most recent 500 entries to prevent unbounded memory use.
     */
    public function ledgerWithRunningBalance(int $distributorId, int $limit = 500): Collection
    {
        $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $running = 0;

        return $entries->map(function (WalletLedgerEntry $e) use (&$running) {
            $running += $e->amount_paise;

            return ['entry' => $e, 'running_balance_paise' => $running];
        });
    }
}
