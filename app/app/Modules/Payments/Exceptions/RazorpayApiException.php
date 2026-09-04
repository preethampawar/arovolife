<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/**
 * The gateway said no, or could not be reached. Carries Razorpay's own error
 * code and description so the intent, the event row and the admin screen can
 * show the buyer-safe reason without anyone parsing a message string.
 */
final class RazorpayApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $gatewayCode = null,
        public readonly ?string $gatewayDescription = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Razorpay's duplicate-receipt rejection, when receipt validation is on. */
    public function isDuplicateReceipt(): bool
    {
        return $this->httpStatus === 400
            && $this->gatewayDescription !== null
            && str_contains(strtolower($this->gatewayDescription), 'receipt');
    }
}
