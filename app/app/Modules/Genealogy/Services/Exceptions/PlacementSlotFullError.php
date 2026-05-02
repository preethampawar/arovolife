<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\Exceptions;

use RuntimeException;

/**
 * Raised when the referral link asks for a specific side (`L` or `R`) under
 * a placement target whose corresponding slot is already taken. The wizard
 * surfaces this as the generic `invalid_referral_link` Contact Us redirect.
 */
final class PlacementSlotFullError extends RuntimeException
{
    public function __construct(
        public readonly string $side,
        public readonly int $placementId,
    ) {
        parent::__construct("placement_id={$placementId}.{$side} is already taken; cannot place here.");
    }
}
