<?php

declare(strict_types=1);

namespace App\Modules\Shared\Otp;

/**
 * Outcome of an {@see OtpService::verify()} call.
 *
 * @phpstan-type OtpPayload array<string, mixed>
 */
final class OtpResult
{
    /**
     * @param  array<string, mixed>  $payload  the data stashed at issue() time (empty on failure)
     * @param  string|null  $reason  'invalid' | 'expired' | 'too_many_attempts' on failure, null on success
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $payload,
        public readonly ?string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function verified(array $payload): self
    {
        return new self(true, $payload, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, [], $reason);
    }

    public function message(): string
    {
        return match ($this->reason) {
            'expired' => 'That code has expired. Please request a new one.',
            'too_many_attempts' => 'Too many incorrect attempts. Please request a new code.',
            default => 'That code is incorrect. Please try again.',
        };
    }
}
