<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a line-change is attempted for a distributor who already has
 * commerce activity (orders / BV) in their name. Repositioning them would
 * retroactively corrupt BV / commission attribution, so it is not allowed.
 */
final class LineChangeHasCommerceError extends RuntimeException {}
