<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects free text that contains a raw Aadhaar or PAN number.
 *
 * Hard rule 8 says raw Aadhaar is never stored. A grievance body is free text
 * retained for seven years, and one of the categories we explicitly invite is
 * "PAN / Aadhaar verification errors" — so a complainant will paste the number
 * unless something stops them. A warning above the textarea is not a control.
 *
 * Aadhaar candidates are Verhoeff-checked before rejection. Without that, any
 * twelve-digit string — an order value, a phone number pair, a date run —
 * would block a legitimate complaint, and a complainant who cannot describe
 * their problem is worse off than one who overshares.
 */
final class NoRawGovernmentId implements ValidationRule
{
    /** Twelve digits, optionally grouped in fours by a space or hyphen. */
    private const AADHAAR_PATTERN = '/\b(\d{4})[\s-]?(\d{4})[\s-]?(\d{4})\b/';

    /** Five letters, four digits, one letter — the PAN format. */
    private const PAN_PATTERN = '/\b[A-Z]{5}\d{4}[A-Z]\b/i';

    /**
     * Verhoeff multiplication table (d).
     *
     * @var array<int, array<int, int>>
     */
    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    /**
     * Verhoeff permutation table (p).
     *
     * @var array<int, array<int, int>>
     */
    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match(self::PAN_PATTERN, $value) === 1) {
            $fail('Please remove the full PAN number. Quote only the last 4 digits — we can find the record from that.');

            return;
        }

        if (preg_match_all(self::AADHAAR_PATTERN, $value, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $digits = $match[1].$match[2].$match[3];

                // Aadhaar never starts 0 or 1, and carries a Verhoeff check digit.
                if ($digits[0] !== '0' && $digits[0] !== '1' && $this->passesVerhoeff($digits)) {
                    $fail('Please remove the full Aadhaar number. Quote only the last 4 digits — we never store the full number, and we do not want it in a complaint either.');

                    return;
                }
            }
        }
    }

    private function passesVerhoeff(string $digits): bool
    {
        $checksum = 0;
        $reversed = strrev($digits);

        foreach (str_split($reversed) as $index => $digit) {
            $checksum = self::D[$checksum][self::P[$index % 8][(int) $digit]];
        }

        return $checksum === 0;
    }
}
