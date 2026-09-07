<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/**
 * A distributor met their repurchase obligation for a cycle. Fire-and-forget.
 *
 * `$fromStatus` is the status the cycle held immediately before completing, so
 * listeners can tell apart an on-time completion (from active) from a late
 * fulfilment (from suspended — the days in between were forfeited).
 */
final class RepurchaseCompleted
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $cycleId,
        public readonly string $fromStatus,
    ) {}
}
