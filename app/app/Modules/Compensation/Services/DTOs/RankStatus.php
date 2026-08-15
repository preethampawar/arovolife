<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * A distributor's own rank standing: the rank they currently hold, the highest
 * they have ever achieved, how many times each rank was achieved, and — for
 * the next rank only — how far this month's own figures are from that rank's
 * published conditions.
 *
 * Every figure is historical or current fact about the signed-in distributor.
 * Nothing here estimates or implies a future rank, income or timeline
 * (DSR 2021 r.5(1)(d); hard rule #3).
 */
final readonly class RankStatus
{
    /**
     * @param  array<int, int>  $achievementCounts  rank_number => lifetime qualified occurrences
     * @param  array<int, string>  $rankNames  rank_number => display name
     * @param  list<RankRequirement>  $nextRequirements  the next rank's conditions, met or not
     */
    public function __construct(
        public ?int $currentRank,
        public ?int $highestRank,
        public array $achievementCounts,
        public array $rankNames,
        public ?int $nextRank,
        public array $nextRequirements,
        /** True when this month already carries a qualification for any rank. */
        public bool $qualifiedThisMonth,
        /** Rank achieved this calendar month, if any. */
        public ?int $thisMonthRank,
        /**
         * Set when the current rank has been achieved before: re-qualifying
         * pays only if the month's repurchase conditions are met.
         */
        public ?bool $requalificationConditionsMet = null,
    ) {}

    public function currentRankName(): ?string
    {
        return $this->currentRank !== null
            ? ($this->rankNames[$this->currentRank] ?? 'Rank '.$this->currentRank)
            : null;
    }

    public function highestRankName(): ?string
    {
        return $this->highestRank !== null
            ? ($this->rankNames[$this->highestRank] ?? 'Rank '.$this->highestRank)
            : null;
    }

    public function nextRankName(): ?string
    {
        return $this->nextRank !== null
            ? ($this->rankNames[$this->nextRank] ?? 'Rank '.$this->nextRank)
            : null;
    }

    /** Total rank achievements across every rank. */
    public function totalAchievements(): int
    {
        return array_sum($this->achievementCounts);
    }

    public function allNextRequirementsMet(): bool
    {
        foreach ($this->nextRequirements as $requirement) {
            if (! $requirement->met()) {
                return false;
            }
        }

        return $this->nextRequirements !== [];
    }
}
