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
    ) {}
}
