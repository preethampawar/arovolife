<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * The AO-GO rules evaluated for one distributor in one month.
 *
 * This is the single decision point for the offer: the monthly run creates a
 * grant when {@see structurallyEligible()} is true (and the month's own
 * purchase BV passes), and the distributor / admin checklists label these same
 * booleans. Neither side re-states a rule, so what is paid and what is
 * displayed cannot drift apart.
 *
 * "Structural" = the three standing rules (user decision 2026-10-17). The
 * month's own purchase BV is evaluated separately by
 * RankRequalificationGateService::passMapByRank().
 */
final readonly class AogoRuleCheck
{
    public function __construct(
        public bool $achievedLastMonth,
        public bool $notRankedThisMonth,
        public bool $usesRemaining,
        public bool $alreadyGrantedThisMonth,
    ) {}

    public function structurallyEligible(): bool
    {
        return $this->achievedLastMonth
            && $this->notRankedThisMonth
            && $this->usesRemaining;
    }
}
