<?php

declare(strict_types=1);

namespace App\Modules\Shared\Crypto;

use App\Modules\Shared\Casts\PiiEncrypted;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Encrypts / decrypts personal data (PAN, Aadhaar, bank account) with a
 * DEDICATED, stable key — `PII_ENCRYPTION_KEY` — that is independent of
 * `APP_KEY`. See ADR-0008.
 *
 * Why: `APP_KEY` is rotated as a routine operation (it protects sessions,
 * cookies and signed URLs). Every column encrypted under it becomes
 * undecryptable the moment it is regenerated — which is exactly the incident
 * this class prevents. PII is long-lived and must survive `APP_KEY` rotation.
 *
 * Migration safety:
 *   - Decryption falls back to `APP_KEY` and `APP_PREVIOUS_KEYS`, so data
 *     written before the dedicated key existed still reads. Run
 *     `php artisan pii:reencrypt` to move it onto the PII key; afterwards the
 *     fallback is no longer exercised.
 *   - If `PII_ENCRYPTION_KEY` is unset, this transparently uses `APP_KEY`, so
 *     nothing changes until the key is provisioned.
 *
 * Both the {@see PiiEncrypted} cast (reads) and the
 * admin identity/bank write paths use this single encrypter, so ciphertext is
 * always consistent.
 */
final class PiiCrypter
{
    private static ?Encrypter $encrypter = null;

    public static function encryptString(string $value): string
    {
        return self::encrypter()->encryptString($value);
    }

    public static function decryptString(string $payload): string
    {
        return self::encrypter()->decryptString($payload);
    }

    public static function encrypter(): Encrypter
    {
        if (self::$encrypter !== null) {
            return self::$encrypter;
        }

        $cipher = (string) config('app.cipher', 'AES-256-CBC');
        $appKey = self::parseKey(config('app.key'));
        $piiKey = self::parseKey(config('app.pii_key'));

        // The PII key writes new ciphertext; APP_KEY is the fallback so nothing
        // is provisioned yet still works.
        $primary = $piiKey ?? $appKey;
        if ($primary === null) {
            throw new RuntimeException('PiiCrypter requires PII_ENCRYPTION_KEY or APP_KEY to be set.');
        }

        $encrypter = new Encrypter($primary, $cipher);

        // Fallback decryption keys, oldest paths last: APP_KEY (when a distinct
        // PII key is in use) and any APP_PREVIOUS_KEYS, so pre-migration data
        // still reads.
        $fallback = [];
        if ($piiKey !== null && $appKey !== null) {
            $fallback[] = $appKey;
        }
        foreach ((array) config('app.previous_keys', []) as $previous) {
            $parsed = self::parseKey($previous);
            if ($parsed !== null) {
                $fallback[] = $parsed;
            }
        }
        if ($fallback !== []) {
            $encrypter->previousKeys($fallback);
        }

        return self::$encrypter = $encrypter;
    }

    /**
     * Drop the cached encrypter — for tests that change the key config between
     * cases. Not needed in normal request/worker lifecycles.
     */
    public static function flush(): void
    {
        self::$encrypter = null;
    }

    private static function parseKey(mixed $key): ?string
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        return $key;
    }
}
