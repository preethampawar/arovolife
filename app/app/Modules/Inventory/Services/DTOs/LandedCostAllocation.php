<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services\DTOs;

/**
 * The result of spreading one GRN's charges across its lines.
 *
 * The invariant the allocator guarantees, and which
 * LandedCostAllocatorTest asserts as a property: the allocated shares sum
 * EXACTLY to the charge total. Not approximately — exactly. A paise lost here
 * is a paise of cost that never reaches any batch, and it compounds silently
 * through every sale made from that batch.
 */
final readonly class LandedCostAllocation
{
    /** @param list<LandedCostLine> $lines */
    public function __construct(
        public array $lines,
        public string $basis,
        public int $chargesPaise,
    ) {}

    public function allocatedTotalPaise(): int
    {
        return array_sum(array_map(
            static fn (LandedCostLine $l): int => $l->allocatedChargesPaise,
            $this->lines,
        ));
    }

    public function landedTotalPaise(): int
    {
        return array_sum(array_map(
            static fn (LandedCostLine $l): int => $l->landedValuePaise(),
            $this->lines,
        ));
    }

    public function unitisationRoundingPaise(): int
    {
        return array_sum(array_map(
            static fn (LandedCostLine $l): int => $l->unitisationRoundingPaise(),
            $this->lines,
        ));
    }
}
