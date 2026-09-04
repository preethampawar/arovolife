<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use RuntimeException;

/**
 * The RazorpayX Payouts credentials (or the debit account number) are absent
 * from the environment.
 *
 * A configuration fault, not a payment fault: no call was attempted and no
 * money moved. Callers surface it to ops rather than marking a line item
 * failed — nothing about the distributor's details is wrong.
 */
final class PayoutGatewayNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'RazorpayX Payouts credentials are not configured.')
    {
        parent::__construct($message);
    }
}
