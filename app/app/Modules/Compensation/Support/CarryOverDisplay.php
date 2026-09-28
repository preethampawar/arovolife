<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

/**
 * My Business "Carried-over" card (client, 2026-09-28): business added on a
 * side since the last slab match. The carry forward already sits on its own
 * card, so it is not repeated here — right after a match this reads 0.
 * Display only; the engine's carried totals are unchanged.
 */
final class CarryOverDisplay
{
    public static function sinceLastMatch(int $carriedBv, int $carryForwardBv): int
    {
        return max(0, $carriedBv - $carryForwardBv);
    }
}
