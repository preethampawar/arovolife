<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services\DTOs;

/**
 * One GRN line's share of the invoice-level charges, and the per-unit cost
 * that share produces.
 *
 * `unitisationRoundingPaise` is the difference between what the line actually
 * costs (taxable + allocated) and what the warehouse will be holding once the
 * cost is expressed per unit (qty x landedUnitCost). Integer paise per unit
 * cannot always divide a line total exactly, so this is not an error — it is
 * the arithmetic residue, and the Trading Account carries it as an explicit
 * reconciling line rather than letting it quietly break the identity
 * opening + purchases - closing = COGS.
 */
final readonly class LandedCostLine
{
    public function __construct(
        public int $qty,
        public int $taxableValuePaise,
        public int $allocatedChargesPaise,
        public int $landedUnitCostPaise,
    ) {}

    public function landedValuePaise(): int
    {
        return $this->taxableValuePaise + $this->allocatedChargesPaise;
    }

    public function unitisationRoundingPaise(): int
    {
        return ($this->qty * $this->landedUnitCostPaise) - $this->landedValuePaise();
    }
}
