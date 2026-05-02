<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\Exceptions;

use RuntimeException;

/**
 * Raised when the referral link does not specify a side AND both `L` and
 * `R` slots under the placement target are already taken. The sponsor must
 * pick a different placement target (one of the existing children, or any
 * deeper descendant with at least one open slot).
 */
final class PlacementSlotsExhaustedError extends RuntimeException
{
    public function __construct(
        public readonly int $placementId,
    ) {
        parent::__construct("placement_id={$placementId} has no open slot on either leg; pick a different placement target.");
    }
}
