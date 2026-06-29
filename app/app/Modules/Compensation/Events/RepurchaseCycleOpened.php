<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/** A new repurchase cycle was opened for a distributor. Fire-and-forget. */
final class RepurchaseCycleOpened
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $cycleId,
        public readonly string $cycleStartDate,
    ) {}
}
