<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\WalletLedgerEntry;

/**
 * What one bonus reversal did to the two wallets, as decided by
 * WalletService::reverseBonusCredit() — the mirror image of
 * {@see BonusCreditOutcome}.
 *
 * `repurchaseShortfallPaise` is the part of the frozen deduction that could not
 * be taken back because the distributor had already spent it on a repurchase
 * order. It is not an error: the goods left the warehouse and are not being
 * clawed back. It is a figure the reversing admin has to see in the audit row,
 * because it is the company's loss on the reversal.
 */
final class BonusReversalOutcome
{
    public function __construct(
        public readonly int $netReversedPaise,
        public readonly int $repurchaseReversedPaise,
        public readonly int $repurchaseShortfallPaise,
        public readonly WalletLedgerEntry $entry,
    ) {}

    /** The whole bonus that was unwound — main wallet plus repurchase wallet. */
    public function totalReversedPaise(): int
    {
        return $this->netReversedPaise + $this->repurchaseReversedPaise;
    }
}
