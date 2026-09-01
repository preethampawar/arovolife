<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use RuntimeException;

/**
 * The distributor's bank_account_enc ciphertext exists but cannot be
 * decrypted (LOG-2). Thrown by PayoutService::bankLast4ForDistributor() so
 * the batch runners hold this distributor's payout instead of exporting a
 * NEFT line with a blank account number.
 */
final class BankDecryptionException extends RuntimeException
{
    public function __construct(public readonly int $distributorId)
    {
        parent::__construct("Bank account ciphertext for distributor {$distributorId} cannot be decrypted.");
    }
}
