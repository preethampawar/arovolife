<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/**
 * A repurchase window closed unmet. Under the forfeit model (client
 * 2026-09-07) the cycle itself reaches exactly two things: the GSB cut-off,
 * which forfeits every failed day outright, and the Genos BV counted at rank
 * qualification, which skips those same days. Mentorship, Growth Booster,
 * Fortune and Rank Bonus are never suspended by the cycle — their own
 * month-end repurchase-wallet gate is a separate rule. Fire-and-forget.
 */
final class IncomeSuspended
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $cycleId,
    ) {}
}
