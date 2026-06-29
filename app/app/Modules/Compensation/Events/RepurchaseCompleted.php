<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

use App\Modules\Compensation\Models\RepurchaseCycle;

/**
 * A distributor met their repurchase obligation for a cycle. Fire-and-forget.
 *
 * `$fromStatus` is the status the cycle held immediately before completing, so
 * listeners can tell apart on-time completion (from active), completion within
 * the grace window, and completion after the period lapsed (from suspended —
 * income for the lapsed days was forfeited).
 */
final class RepurchaseCompleted
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $cycleId,
        public readonly string $fromStatus,
    ) {}

    /** Completed inside the grace window (held income, eligible for release). */
    public function withinGrace(): bool
    {
        return $this->fromStatus === RepurchaseCycle::STATUS_GRACE;
    }

    /** Completed after grace lapsed — income for the lapsed period was forfeited. */
    public function wasForfeited(): bool
    {
        return $this->fromStatus === RepurchaseCycle::STATUS_SUSPENDED;
    }
}
