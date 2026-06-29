<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/** Grace lapsed without the repurchase met — the distributor's GSB/Fortune/GBB
 *  are suspended (Mentorship and Rank BV are never suspended). Fire-and-forget. */
final class IncomeSuspended
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $cycleId,
    ) {}
}
