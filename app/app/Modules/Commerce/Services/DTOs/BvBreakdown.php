<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services\DTOs;

/**
 * A distributor's personal-BV totals over a window, all in paise. `reversed`
 * is signed (≤ 0), so `net === accrued + reversed`.
 */
final readonly class BvBreakdown
{
    public function __construct(
        public int $accruedPaise,
        public int $reversedPaise,
        public int $netPaise,
    ) {}
}
