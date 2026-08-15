<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * The AO-GO rules evaluated for one distributor in one month.
 *
 * This is the single decision point for the offer: the monthly run creates a
 * grant when {@see structurallyEligible()} is true (and the requalification
 * gate passes), and the distributor / admin checklists label these same
 * booleans. Neither side re-states a rule, so what is paid and what is
 * displayed cannot drift apart.
 *
 * "Structural" = the four standing rules. The month's requalification
 * conditions are evaluated separately by RankRequalificationGateService,
 * because failing them consumes no lifetime use.
 */
final readonly class AogoRuleCheck
{
    public function __construct(
        public bool $achievedBefore,
        public bool $notRankedThisMonth,
        public bool $usesRemaining,
        public bool $notUsedLastMonth,
        public bool $reAchievedSinceLastUse,
        public bool $alreadyGrantedThisMonth,
    ) {}

    public function structurallyEligible(): bool
    {
        return $this->achievedBefore
            && $this->notRankedThisMonth
            && $this->usesRemaining
            && $this->notUsedLastMonth
            && $this->reAchievedSinceLastUse;
    }
}
