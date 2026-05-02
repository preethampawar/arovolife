<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use ZxcvbnPhp\Zxcvbn;

/**
 * Rejects low-entropy passwords using Dropbox's zxcvbn — the standard for
 * "is this password actually hard to guess" rather than "does it satisfy
 * a checklist". A score of ≥ 3 means roughly 10^8 guesses to crack.
 *
 * The CLAUDE.md / master plan target is score ≥ 3.
 */
final class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail("The {$attribute} is required.");

            return;
        }

        $score = (int) ((new Zxcvbn)->passwordStrength($value)['score'] ?? 0);

        if ($score < 3) {
            $fail("The {$attribute} is too easy to guess. Use a longer phrase or mix unrelated words.");
        }
    }
}
