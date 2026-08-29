<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Paise arithmetic shared by the compensation engines.
 *
 * Every engine that turns a pool into a per-point / per-score value floors
 * that value to the whole rupee and leaves the remainder unspent (the client
 * confirmed this for GSB, MSB, GBB, Rank Bonus and Fortune Bonus alike:
 * 220.79 → ₹220). Keep the arithmetic here so the engines cannot drift.
 */
final class Money
{
    /**
     * Whole-rupee floor of amount ÷ units, in paise.
     *
     * Truncates the per-unit value to a multiple of 100 paise and clamps the
     * result at 0: a refund-heavy (negative-BV) period pays nothing rather
     * than a negative value, and zero or negative units — nobody to pay —
     * yield 0 instead of a division error.
     */
    public static function floorRupee(int $amountPaise, int $units = 1): int
    {
        if ($units <= 0) {
            return 0;
        }

        return max(0, intdiv(intdiv($amountPaise, $units), 100) * 100);
    }
}
