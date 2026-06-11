<?php

declare(strict_types=1);

namespace App\Modules\Shared\Otp;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Generic, reusable one-time-password engine — the single source of truth for
 * OTP issue / verify across the application (profile contact change today; any
 * step-up verification tomorrow). It is channel-agnostic: it generates, stores
 * and verifies codes; DELIVERY (email / SMS) is the caller's concern, so the
 * same code can be sent over any channel.
 *
 * A code is scoped by ({@see $purpose}, {@see $key}) — e.g.
 * ('profile_contact_change', "userId:42") — and carries an arbitrary payload
 * that is returned verbatim on successful verification (e.g. the new email /
 * phone to apply). Codes are stored only as a SHA-256 hash; the plaintext is
 * returned once from {@see issue()} for the caller to deliver and is never
 * persisted or logged.
 */
final class OtpService
{
    public const DEFAULT_TTL_SECONDS = 600; // 10 minutes

    public const MAX_ATTEMPTS = 5;

    public const CODE_DIGITS = 6;

    public function __construct(private readonly Cache $cache) {}

    /**
     * Issue a fresh code for ($purpose, $key), replacing any previous one.
     * Returns the plaintext code for the caller to deliver.
     *
     * @param  array<string, mixed>  $payload  returned verbatim on successful verify()
     */
    public function issue(string $purpose, string $key, array $payload = [], ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;
        $code = $this->generateCode();
        $expiresAt = Carbon::now()->addSeconds($ttl);

        $this->cache->put($this->cacheKey($purpose, $key), [
            'hash' => hash('sha256', $code),
            'payload' => $payload,
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return $code;
    }

    /**
     * Verify a submitted code. Consumes (clears) the code on success or once
     * the attempt cap is hit. Constant-time comparison; bounded attempts.
     */
    public function verify(string $purpose, string $key, string $code): OtpResult
    {
        $cacheKey = $this->cacheKey($purpose, $key);
        /** @var array{hash: string, payload: array<string, mixed>, attempts: int, expires_at: int}|null $record */
        $record = $this->cache->get($cacheKey);

        if ($record === null) {
            return OtpResult::failed('expired');
        }

        $remaining = $record['expires_at'] - Carbon::now()->getTimestamp();
        if ($remaining <= 0) {
            $this->cache->forget($cacheKey);

            return OtpResult::failed('expired');
        }

        if (($record['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            $this->cache->forget($cacheKey);

            return OtpResult::failed('too_many_attempts');
        }

        if (hash_equals((string) $record['hash'], hash('sha256', trim($code)))) {
            $this->cache->forget($cacheKey);

            return OtpResult::verified($record['payload'] ?? []);
        }

        $record['attempts']++;
        // Re-store with the REMAINING ttl so a wrong guess never extends expiry.
        $this->cache->put($cacheKey, $record, $remaining);

        return OtpResult::failed('invalid');
    }

    /**
     * The payload of a still-pending code, without consuming it (e.g. to resend
     * the same request). Null when nothing is pending.
     *
     * @return array<string, mixed>|null
     */
    public function peek(string $purpose, string $key): ?array
    {
        /** @var array{payload?: array<string, mixed>}|null $record */
        $record = $this->cache->get($this->cacheKey($purpose, $key));

        return $record === null ? null : ($record['payload'] ?? []);
    }

    public function pending(string $purpose, string $key): bool
    {
        return $this->cache->has($this->cacheKey($purpose, $key));
    }

    public function clear(string $purpose, string $key): void
    {
        $this->cache->forget($this->cacheKey($purpose, $key));
    }

    private function cacheKey(string $purpose, string $key): string
    {
        return 'otp:'.$purpose.':'.$key;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, (10 ** self::CODE_DIGITS) - 1), self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }
}
