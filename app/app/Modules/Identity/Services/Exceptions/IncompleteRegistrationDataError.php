<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services\Exceptions;

use LogicException;

/**
 * Raised when finalise() is invoked but required account data is missing
 * from the wizard state — typically email, phone, or password_hash.
 *
 * Extends LogicException rather than RuntimeException so it does not
 * accidentally get caught by handlers reaching for PlacementSlotsExhaustedError
 * / PlacementSlotFullError (both of which extend RuntimeException).
 */
final class IncompleteRegistrationDataError extends LogicException
{
}
