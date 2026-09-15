<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services\DTOs;

/**
 * What one sold line cost us, and how confident we are in that number.
 *
 * The basis travels with the figure on purpose. A profit report that mixes a
 * cost read from real stock movements with a cost guessed from a hand-typed
 * field, and shows them in the same column with no distinction, is worse than
 * no report: it is a wrong number wearing the clothes of a right one.
 */
final readonly class LineCost
{
    /** Actual FIFO batch cost, from a receipt that carried freight and other charges. */
    public const BASIS_LANDED = 'landed';

    /** Actual FIFO batch cost, but from a receipt with no charges entered — supplier price only. */
    public const BASIS_SUPPLIER = 'supplier';

    /** No stock movement: fell back to the variant's derived landing price. */
    public const BASIS_DERIVED = 'derived';

    /** No landing price either: fell back to the hand-typed cost price. */
    public const BASIS_ESTIMATED = 'estimated';

    /** Nothing to go on. Cost reported as zero, and counted as unknown. */
    public const BASIS_UNKNOWN = 'unknown';

    public const LABELS = [
        self::BASIS_LANDED => 'Actual (landed)',
        self::BASIS_SUPPLIER => 'Actual (supplier)',
        self::BASIS_DERIVED => 'Derived',
        self::BASIS_ESTIMATED => 'Estimated',
        self::BASIS_UNKNOWN => 'Unknown',
    ];

    /** Bases that are read from what actually moved, rather than inferred. */
    public const ACTUAL = [self::BASIS_LANDED, self::BASIS_SUPPLIER];

    public function __construct(
        public int $orderItemId,
        public int $orderId,
        public int $productVariantId,
        public int $units,
        public int $cogsPaise,
        public string $basis,
    ) {}

    public function label(): string
    {
        return self::LABELS[$this->basis] ?? $this->basis;
    }

    public function isActual(): bool
    {
        return in_array($this->basis, self::ACTUAL, true);
    }

    public function unitCostPaise(): int
    {
        return $this->units > 0 ? intdiv($this->cogsPaise, $this->units) : 0;
    }
}
