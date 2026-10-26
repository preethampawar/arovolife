<?php

declare(strict_types=1);

namespace App\Modules\Content\Exceptions;

use App\Modules\Content\Services\PlanChangeApprovals;
use RuntimeException;

/**
 * A plan change was not proposed, approved, rejected or withdrawn, for a
 * reason the person who asked can act on (R-125).
 *
 * Thrown by {@see PlanChangeApprovals}. Its message is written for an
 * administrator and is shown to them as is, so it never carries anything but
 * the reason.
 */
final class PlanChangeRefused extends RuntimeException {}
