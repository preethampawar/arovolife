<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Exceptions;

use App\Modules\Fulfilment\Data\ParcelGap;
use RuntimeException;

/**
 * An order cannot go through Shiprocket because one or more of its products has
 * no recorded weight or packed size.
 *
 * Refused rather than guessed (user decision 2026-09-24): a courier reweighs
 * and remeasures every parcel, and a declared weight that is wrong comes back
 * as a weight-discrepancy charge. Manual dispatch is unaffected.
 */
final class MissingParcelDetailsException extends RuntimeException
{
    /** @param  list<ParcelGap>  $gaps */
    public function __construct(public readonly array $gaps)
    {
        $lines = array_map(
            static fn (ParcelGap $gap): string => $gap->productName.' (missing '.implode(', ', $gap->missing).')',
            $gaps,
        );

        parent::__construct(
            'This order cannot be sent through Shiprocket until every product has a weight and a packed size: '
            .implode('; ', $lines).'. Fill them in on the product, or dispatch manually.'
        );
    }
}
