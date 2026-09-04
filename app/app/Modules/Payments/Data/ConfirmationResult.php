<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

/**
 * What a confirmation attempt did. Callers branch on `$status`; the message
 * is for logs, never for the buyer.
 */
final readonly class ConfirmationResult
{
    public const CONFIRMED = 'confirmed';

    public const ALREADY_CONFIRMED = 'already_confirmed';

    /** Authorised or attempted but not captured yet — ask again later. */
    public const PENDING = 'pending';

    /** Captured against an order that had already been cancelled: refunded. */
    public const LATE_CAPTURE = 'late_capture';

    /** The gateway reports the attempt failed. The order stays placed. */
    public const FAILED = 'failed';

    public function __construct(
        public string $status,
        public string $message = '',
    ) {}

    public function paid(): bool
    {
        return in_array($this->status, [self::CONFIRMED, self::ALREADY_CONFIRMED], true);
    }
}
