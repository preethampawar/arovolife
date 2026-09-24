<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Shiprocket said no, or could not be reached. Carries the HTTP status and
 * Shiprocket's own (sanitised) message so the event row and the admin screen
 * can show a reason without anyone parsing a message string.
 *
 * Never carries a consignee's name, phone or address — the message reaches the
 * admin UI and the exception log.
 */
final class ShiprocketApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $gatewayMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Shiprocket answered and refused, so nothing was created: a 4xx, or a
     * refusal inside a 2xx body. No answer at all, or a 5xx (which a proxy can
     * return after Shiprocket processed the request), is indeterminate.
     */
    public function isDefiniteRefusal(): bool
    {
        return $this->httpStatus !== null && $this->httpStatus < 500;
    }
}
