<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Rules;

use App\Modules\Shared\Features\HibpPasswordCheck;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * HaveIBeenPwned k-anonymity check. We send only the first 5 hex chars of
 * SHA1(password) — never the full hash and never the password — and look
 * for the password's hash suffix in the returned ~500 candidates. Built
 * into Laravel HTTP client so tests can Http::fake the upstream cleanly.
 *
 * If the API is unreachable the rule fails OPEN (logs a warning, lets the
 * registration through). Failing closed would let a network outage block
 * all registrations — disproportionate to the marginal risk.
 *
 * Gated by the HibpPasswordCheck feature flag — admins can disable the
 * check from /admin/feature-flags (e.g. for offline staging environments
 * or demo seeding). When the flag is OFF, this rule short-circuits to
 * a no-op; zxcvbn (StrongPassword) still runs.
 */
final class NotPwned implements ValidationRule
{
    private const TIMEOUT_SECONDS = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // other rules will catch the missing/empty case
        }

        // Feature flag — when admin has disabled the HIBP check, skip
        // the upstream call entirely. zxcvbn still enforces entropy.
        if (! Feature::active(HibpPasswordCheck::class)) {
            return;
        }

        $sha1 = strtoupper(sha1($value));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Add-Padding' => 'true'])
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");

            if (! $response->ok()) {
                Log::warning('HIBP range API returned non-2xx; allowing registration through.', [
                    'status' => $response->status(),
                ]);

                return;
            }

            foreach (preg_split('/\R/', (string) $response->body()) ?: [] as $line) {
                [$lineSuffix] = explode(':', trim($line)) + [''];
                if ($lineSuffix === '' || $lineSuffix === '0000000000000000000000000000000000000') {
                    continue; // padding rows are zero-suffixes; skip
                }
                if (strcasecmp($lineSuffix, $suffix) === 0) {
                    $fail("The {$attribute} has appeared in known data breaches. Choose a different password.");

                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('HIBP check failed; allowing registration through.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
