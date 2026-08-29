<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use NumberFormatter;

/**
 * Indian-digit-grouping number formatter (24,30,000 — lakh/crore style).
 *
 * Why this exists: display formatting used to rely on
 * Number::useLocale('en_IN'), but CLDR removed lakh grouping from the Indian
 * locales' default data (en_IN in CLDR 42, the rest by CLDR 48 / ICU 78), so
 * on current ICU every locale silently renders western groups (2,430,000).
 * The pattern below pins the grouping explicitly and is therefore immune to
 * ICU/CLDR upgrades.
 *
 * Every user-facing surface must format numbers through this class or the Bv
 * helper (the "bv" Blade directive) — never Illuminate\Support\Number::format
 * (western groups on modern ICU) and never raw number_format(). CSV exports
 * are the exception: they stay ungrouped for spreadsheets. IndianNumberTest
 * pins the contract.
 */
final class IndianNumber
{
    private const string PATTERN = '#,##,##0.###';

    /**
     * Drop-in replacement for Illuminate\Support\Number::format — same
     * signature (minus locale) so existing call sites swap mechanically.
     */
    public static function format(int|float $number, ?int $precision = null, ?int $maxPrecision = null): string
    {
        $formatter = new NumberFormatter('en_IN', NumberFormatter::PATTERN_DECIMAL, self::PATTERN);

        if ($maxPrecision !== null) {
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxPrecision);
        } elseif ($precision !== null) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $precision);
        }

        $formatted = $formatter->format($number);

        return $formatted === false ? (string) $number : $formatted;
    }

    /** Paise → "₹1,37,71,439.80" (lakh grouping, fixed decimals). */
    public static function rupees(int $paise, int $precision = 2): string
    {
        return '₹'.self::format($paise / 100, $precision);
    }

    /** 20.0 → "20%", 2.5 → "2.5%", 3.75 → "3.75%" (trailing zeros dropped). */
    public static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'%';
    }

    /** Basis points → percent label: 4500 → "45%", 250 → "2.5%". */
    public static function percentFromBp(int $bp): string
    {
        return self::percent($bp / 100);
    }
}
