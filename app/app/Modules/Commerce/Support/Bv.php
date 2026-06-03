<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Support;

use Illuminate\Support\Number;

/**
 * The single source of truth for turning a BV amount in paise into the values
 * the app shows. BV is stored in paise (points × 100) everywhere; every surface
 * that displays "N BV" or writes BV to a CSV must go through here so the unit
 * conversion and formatting are defined in exactly one place.
 */
final class Bv
{
    /** Human display, e.g. 363000 paise → "3,630 BV" (locale-aware). */
    public static function format(int $paise): string
    {
        return Number::format(self::points($paise)).' BV';
    }

    /** Raw point value for spreadsheets/CSV, e.g. 363000 paise → 3630. */
    public static function points(int $paise): int
    {
        return intdiv($paise, 100);
    }
}
