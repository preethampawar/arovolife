<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use RuntimeException;

final class UnbalancedLedgerException extends RuntimeException
{
    public function __construct(int $debitsPaise, int $creditsPaise)
    {
        parent::__construct(sprintf(
            'Ledger posting rejected: debits=%d paise, credits=%d paise. Must be equal.',
            $debitsPaise,
            $creditsPaise,
        ));
    }
}
