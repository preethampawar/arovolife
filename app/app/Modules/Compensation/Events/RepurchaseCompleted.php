<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/** A distributor met their repurchase obligation for a cycle. Fire-and-forget. */
final class RepurchaseCompleted
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $cycleId,
        public readonly bool $withinGrace = false,
    ) {}
}
