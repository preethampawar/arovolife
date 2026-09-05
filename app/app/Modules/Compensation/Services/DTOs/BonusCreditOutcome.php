<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\WalletLedgerEntry;

/**
 * What one bonus credit did to the wallet, as decided by
 * WalletService::creditWithRepurchaseDeduction() — the only place the
 * repurchase deduction is ever computed.
 *
 * The engine that asked for the credit copies these figures onto its result
 * row (repurchase_deduction_paise + the row's net column) so every bonus page
 * reads the same stored fact instead of re-deriving it from the ledger. The
 * admin charge and TDS are not here on purpose: they are decided at payout
 * time and live only on payout_line_items.
 */
final class BonusCreditOutcome
{
    public function __construct(
        public readonly int $grossPaise,
        public readonly int $repurchaseDeductionPaise,
        public readonly WalletLedgerEntry $entry,
    ) {}

    /** Gross minus the repurchase deduction — the net effect on the main wallet. */
    public function creditedPaise(): int
    {
        return $this->grossPaise - $this->repurchaseDeductionPaise;
    }
}
