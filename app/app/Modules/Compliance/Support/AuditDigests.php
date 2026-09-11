<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\AuditLog;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Before/after state digests for `audit_log` (QA finding F108).
 *
 * CLAUDE.md: "any admin action, any KYC change, any settings change →
 * audit_log entry with before/after hashes". Call sites used to hash a single
 * hand-picked string when they hashed anything at all, so 19 of 3,235 staging
 * rows carried a `before_hash`. This is the one helper they all now use:
 * snapshot the subject before the mutation, snapshot it after, digest both.
 *
 * Three rules the implementation exists to keep:
 *
 *  1. **Raw bytes, never hex.** The columns are `BINARY(32)`; everything here
 *     returns what `AuditLog::digest()` returns.
 *  2. **Stable across representations.** A model just written holds PHP ints
 *     and Carbon instances where the same model read back holds strings, so
 *     every scalar is normalised to its string form before hashing. Without
 *     that, an untouched column would move the digest.
 *  3. **Never raw PII.** Identity numbers are digested in their masked
 *     (last-4) form, exactly as the self-service bank-details audit does;
 *     credentials and ciphertext columns collapse to the fact that they are
 *     set. A digest is not a store, but it must not become a lookup oracle
 *     over a value space as small as a PAN.
 */
final class AuditDigests
{
    /**
     * Attributes whose value is an identity number: digested masked, so a
     * change still moves the hash but the number itself never reaches it.
     *
     * @var list<string>
     */
    private const MASKED = ['pan', 'aadhaar', 'aadhar', 'account_number', 'bank_account'];

    /**
     * Attributes that hold a credential or ciphertext: digested as the bare
     * fact that a value is present. Their own actions audit the change.
     *
     * @var list<string>
     */
    private const OPAQUE = ['password', 'token', 'secret', 'otp', 'mfa'];

    /**
     * Columns that move on every save and would otherwise make every digest
     * pair look like a change.
     *
     * @var list<string>
     */
    private const VOLATILE = ['updated_at'];

    /**
     * The digest of one state, in the raw 32-byte form `audit_log` holds.
     *
     * `null` in, `null` out — a create has no before-state and stores
     * `before_hash` NULL rather than the digest of an empty array.
     *
     * @param  Model|array<array-key, mixed>|string|null  $state
     */
    public static function of(Model|array|string|null $state): ?string
    {
        if ($state === null) {
            return null;
        }

        if (is_string($state)) {
            return AuditLog::digest($state);
        }

        $snapshot = $state instanceof Model ? self::snapshot($state) : self::canonicalise($state);

        return AuditLog::digest(
            json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * A model's audited attributes, canonicalised and redacted.
     *
     * Pass `$only` to pin the digest to the fields an action actually governs
     * (a settings row's value, a batch's status) instead of the whole row.
     *
     * @param  list<string>  $only
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model, array $only = []): array
    {
        $attributes = $model->getAttributes();

        if ($only !== []) {
            $attributes = array_intersect_key($attributes, array_flip($only));
        }

        foreach (self::VOLATILE as $column) {
            unset($attributes[$column]);
        }

        return self::canonicalise($attributes);
    }

    /**
     * Sort keys and normalise every leaf, so the same state always encodes to
     * the same bytes whatever order or PHP type it arrived in.
     *
     * @param  array<array-key, mixed>  $state
     * @return array<array-key, mixed>
     */
    private static function canonicalise(array $state): array
    {
        ksort($state);

        $canonical = [];
        foreach ($state as $key => $value) {
            $canonical[$key] = is_string($key)
                ? self::normalise($key, $value)
                : self::normalise('', $value);
        }

        return $canonical;
    }

    private static function normalise(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return self::canonicalise($value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_object($value)) {
            $value = method_exists($value, '__toString') ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
        }

        $value = (string) $value;

        // Binary columns (a BINARY(32) hash, a checksum) are not valid UTF-8
        // and would make json_encode throw inside an audit write — which is
        // an admin action failing because of its own audit row. Hex them.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = bin2hex($value);
        }

        return self::redact($key, $value);
    }

    private static function redact(string $key, string $value): string
    {
        $key = strtolower($key);

        foreach (self::OPAQUE as $needle) {
            if (str_contains($key, $needle)) {
                return 'set';
            }
        }

        if (str_ends_with($key, '_enc')) {
            return 'set';
        }

        foreach (self::MASKED as $needle) {
            if (str_contains($key, $needle)) {
                // A value already down to four characters IS the masked
                // form (`pan_last4`, `account_last4`) — the columns that
                // hold it are plaintext by design. Keep it, or a before and
                // an after would collapse onto the same digest.
                return strlen($value) <= 4 ? $value : '****'.substr($value, -4);
            }
        }

        return $value;
    }
}
