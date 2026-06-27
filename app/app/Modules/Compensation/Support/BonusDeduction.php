<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

/**
 * Immutable result of applying the admin charge + TDS to a bonus gross amount.
 *
 * All fields are in paise (×100). `netPaise` is what the distributor actually
 * receives: gross − adminCharge − tds, clamped to never go below zero.
 */
final readonly class BonusDeduction
{
    public function __construct(
        public int $grossPaise,
        public int $adminChargePaise,
        public int $tdsPaise,
        public int $netPaise,
    ) {}
}
