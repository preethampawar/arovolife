<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * A distributor's own standing against the AO-GO ("Achieve Once – Get Once")
 * offer for a month: lifetime uses, this month's conditions, and the grant
 * already recorded for the month if the monthly run has created one.
 */
final readonly class AogoStatus
{
    /**
     * @param  list<AogoCondition>  $conditions
     */
    public function __construct(
        public bool $everAchievedRank,
        public int $usesUsed,
        public int $usesMax,
        public int $pointsPerGrant,
        public array $conditions,
        public bool $conditionsMet,
        public ?int $grantedPoints = null,
        public ?string $grantedStatus = null,
    ) {}

    public function usesLeft(): int
    {
        return max(0, $this->usesMax - $this->usesUsed);
    }

    public function granted(): bool
    {
        return $this->grantedPoints !== null;
    }

    /** @return list<AogoCondition> */
    public function unmetConditions(): array
    {
        return array_values(array_filter($this->conditions, static fn (AogoCondition $c): bool => ! $c->met));
    }
}
