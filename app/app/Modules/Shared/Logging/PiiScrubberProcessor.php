<?php

declare(strict_types=1);

namespace App\Modules\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Scrubs PAN / Aadhaar / credentials out of the log record before it leaves
 * the process. CLAUDE.md hard rule 8 (DPDP Act 2023) and the ".env"
 * `LOG_PII_SCRUB=true` flag both expect this layer.
 *
 * The strategy errs on the side of over-redacting:
 *   - any 12-digit sequence (with or without space/dash separators) is
 *     treated as Aadhaar — we accept the false positive of redacting an
 *     unrelated 12-digit ID, because under-redacting is a compliance
 *     breach and over-redacting is an information-loss inconvenience.
 *   - any token matching the strict PAN format is redacted.
 *   - context keys whose names look credential-like are replaced wholesale,
 *     not pattern-matched, because a missed prefix would leak the secret.
 */
final class PiiScrubberProcessor implements ProcessorInterface
{
    /**
     * Keys whose values are nuked regardless of content. Includes Arovolife
     * column / form-field names so a `['pan_number' => 'ABCDE1234F']` array
     * is wiped at the key level even if the value's regex shape later drifts.
     */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'mfa_secret', 'mfa_secret_enc', 'totp', 'totp_code',
        'otp', 'otp_code',
        'token', '_token', 'csrf_token',
        'api_key', 'api_secret', 'secret', 'access_token', 'refresh_token',
        'authorization',
        // Arovolife PII column / form names
        'pan', 'pan_number', 'pan_hash',
        'aadhaar', 'aadhaar_number', 'aadhaar_ref',
        'account_number', 'bank_account', 'bank_account_enc',
    ];

    /**
     * Indian PAN pattern: 5 letters, 4 digits, 1 letter — case-insensitive
     * because validation may reject a lowercase PAN before storage but the
     * rejected payload can still appear in a Laravel ValidationException
     * log line. Better to over-redact than leak.
     */
    private const PAN_RE = '/\b[A-Za-z]{5}[0-9]{4}[A-Za-z]\b/';

    /** Aadhaar: 4-4-4 digits, optional space or dash separator. */
    private const AADHAAR_RE = '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->scrubArray($record->context),
        );
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function scrubArray(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $array[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $array[$key] = $this->scrubArray($value);
            } elseif (is_string($value)) {
                $array[$key] = $this->scrubString($value);
            }
        }

        return $array;
    }

    private function scrubString(string $value): string
    {
        // Aadhaar first, then PAN — order matters because the PAN pattern
        // does not overlap but a 12-digit number could otherwise be missed
        // if other regexes mutate the string under us first.
        $value = (string) preg_replace(self::AADHAAR_RE, '[REDACTED:AADHAAR]', $value);
        $value = (string) preg_replace(self::PAN_RE, '[REDACTED:PAN]', $value);

        return $value;
    }
}
