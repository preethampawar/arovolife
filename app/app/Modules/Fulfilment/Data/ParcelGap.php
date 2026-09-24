<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Data;

/**
 * One order line whose product lacks what a courier needs to price the parcel.
 * Returned by `ShiprocketGateway::parcelGaps()`, which the dispatch screen uses
 * to tell the operator exactly what to fill in, and where.
 */
final readonly class ParcelGap
{
    public const WEIGHT = 'weight';

    public const LENGTH = 'length';

    public const BREADTH = 'breadth';

    public const HEIGHT = 'height';

    /** The product the line refers to has since been deleted. */
    public const VARIANT = 'product record';

    /**
     * @param  int|null  $productVariantId  null when the variant no longer exists — there is nothing to link to
     * @param  list<string>  $missing  `ParcelGap::*` values
     * @param  int|null  $productId  for the edit link; null when the variant no longer exists
     */
    public function __construct(
        public ?int $productVariantId,
        public string $productName,
        public string $sku,
        public array $missing,
        public ?int $productId = null,
    ) {}
}
