<?php

declare(strict_types=1);

namespace App\Modules\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

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
        'pan', 'pan_number', 'pan_hash', 'pan_encrypted',
        'aadhaar', 'aadhaar_number', 'aadhaar_ref', 'aadhaar_encrypted',
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
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, mixed>
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
            } elseif ($value instanceof Throwable) {
                // Laravel puts the Throwable itself under `context.exception`,
                // and the formatter renders its message and stack trace. A PAN
                // passed as a function argument appears in that trace, so an
                // object left untouched here walks straight past every string
                // rule above (T-6.1 finding M-4).
                //
                // Replaced with a scrubbed array rather than mutated: a
                // Throwable's message is read-only, and re-throwing a rewritten
                // copy would lose the original type and its chain.
                //
                // The chain matters as much as the head (EH-M7): a
                // QueryException wraps a PDOException whose message carries the
                // bound parameters — exactly where a PAN or account number
                // leaks. Walked with a loop, not recursion, capped at 10
                // levels, so an artificially long chain cannot blow the stack.
                $flat = $this->throwableToArray($value);

                $previous = [];
                $prev = $value->getPrevious();
                for ($depth = 0; $prev !== null && $depth < 10; $depth++) {
                    $previous[] = $this->throwableToArray($prev);
                    $prev = $prev->getPrevious();
                }
                if ($previous !== []) {
                    $flat['previous'] = $previous;
                }

                $array[$key] = $this->scrubArray($flat);
            }
        }

        return $array;
    }

    /**
     * @return array{class: class-string, message: string, file: string, line: int, trace: string}
     */
    private function throwableToArray(Throwable $e): array
    {
        return [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
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
