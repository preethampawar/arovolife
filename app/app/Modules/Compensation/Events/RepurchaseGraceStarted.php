<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/** A cycle passed its due date unmet and entered the grace window. Income is
 *  calculated but held until the cycle is met or grace lapses. Fire-and-forget. */
final class RepurchaseGraceStarted
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $cycleId,
        public readonly string $graceEndDate,
    ) {}
}
