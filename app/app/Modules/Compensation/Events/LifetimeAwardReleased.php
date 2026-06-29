<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/**
 * A rank's once-per-lifetime non-cash award was marked delivered to a
 * distributor. Fire-and-forget (e.g. for vendor-fulfilment or notification
 * listeners). Non-cash → no wallet credit, no admin charge or TDS.
 */
final class LifetimeAwardReleased
{
    public function __construct(
        public readonly int $milestoneId,
        public readonly int $distributorId,
        public readonly int $rankNumber,
        public readonly ?int $actorId = null,
    ) {}
}
