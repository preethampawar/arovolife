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

    /** Running balance ledger with cumulative sum, ordered by created_at. */
    public function ledgerWithRunningBalance(int $distributorId): Collection
    {
        $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $running = 0;

        return $entries->map(function (WalletLedgerEntry $e) use (&$running) {
            $running += $e->amount_paise;

            return ['entry' => $e, 'running_balance_paise' => $running];
        });
    }
}
