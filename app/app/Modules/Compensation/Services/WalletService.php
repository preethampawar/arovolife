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
