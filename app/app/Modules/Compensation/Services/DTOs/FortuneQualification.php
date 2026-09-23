<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/** This month's Fortune Bonus gate verdict for one distributor — facts, never money. */
final readonly class FortuneQualification
{
    public function __construct(
        public string $tier,              // FortuneBonusService::TIER_* or rank_1..rank_5
        public bool $rankIneligible,      // holds a rank in plan->fortuneIneligibleRanks()
        public bool $hasGsbIncome,        // at least one credited GSB cutoff this month
        public int $personalBvPaise,
        public int $bvRequiredPaise,
        public int $slabCount,
        public int $slabsRequired,
        public ?bool $holdsTitle,         // null when the tier does not require one
        public bool $qualified,           // every gate above passes
    ) {}

    public function tierLabel(): string
    {
        return match ($this->tier) {
            'new_joiner' => 'New joiner (first month)',
            'non_ranked' => 'No rank this month',
            default => 'Rank '.substr($this->tier, 5),
        };
    }
}
