<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use RuntimeException;

/**
 * The bank details on file are structurally wrong — the gateway rejected the
 * IFSC or the account number before any money moved.
 *
 * Distinct from {@see RazorpayPayoutException} because it is not a transport
 * or gateway problem: retrying changes nothing until ops re-capture the
 * distributor's bank details, so an auto-retry would only burn attempts.
 *
 * `field` names the offending field ('ifsc' or 'account_number'). The value
 * itself is never carried — this message reaches the admin UI and audit log.
 */
final class BankValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
