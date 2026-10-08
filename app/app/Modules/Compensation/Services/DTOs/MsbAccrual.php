<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * One sponsor's Mentorship Bonus entitlement for a sponsee's credited cut-off,
 * measured in MSB score points and not yet priced.
 *
 * Produced by MentorshipBonusService::accrueForSponsee() during the cut-off's
 * settle pass (zero writes), summed into the day's denominator, and only then
 * priced by creditAccrual() at the frozen point value. Nothing here is
 * persisted: on a crash the whole day is simply re-run and the surviving
 * accruals are reconstructed from the credited gsb_cutoff_results rows.
 *
 * A repurchase-gated accrual (client 2026-10-09: a sponsor below the royalty
 * rank who is failed on the cut-off day) carries the points that WOULD have
 * accrued, but stays out of the day's denominator; creditAccrual() records it
 * as a `repurchase_gated` row at ₹0 so the verdict is frozen, never re-derived.
 */
final class MsbAccrual
{
    public function __construct(
        public readonly int $sponsorId,
        public readonly int $sponseeId,
        public readonly int $slab,
        public readonly int $points,
        public readonly int $sponseeGsbPaise,
        public readonly string $cutoffDate,
        public readonly bool $repurchaseGated = false,
        public readonly ?string $gateReason = null,
        public readonly ?int $sponsorRankAsOf = null,
    ) {}

    /** Whether these points join the day's MSB denominator. */
    public function countsInDenominator(): bool
    {
        return ! $this->repurchaseGated;
    }
}
