<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The payout gateway refused a call, or could not be reached.
 *
 * Every property here is non-PII: gateway error code, HTTP status, the
 * gateway's own description. They are written into audit logs, the payout
 * gateway event trail and the line item's failure reason, all of which admins
 * read — the request body, which holds the bank account number, never is.
 *
 * `transport` separates "we never got an answer" (a timeout, a DNS failure)
 * from "the gateway answered no". A transport failure on a POST /payouts is
 * the dangerous case: the transfer may or may not exist, which is why the
 * idempotency key is what recovers it, never a blind resend.
 */
final class PayoutGatewayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $gatewayMeta  the gateway's `error.meta`, when it named a conflicting entity
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $gatewayCode = null,
        public readonly ?string $gatewayDescription = null,
        public readonly bool $transport = false,
        public readonly ?array $gatewayMeta = null,
        public readonly ?string $operation = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
