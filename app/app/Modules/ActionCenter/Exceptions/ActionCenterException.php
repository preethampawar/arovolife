<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Exceptions;

use RuntimeException;

/**
 * Base for the domain refusals the HTTP layer turns into a 422 (plan §5).
 */
abstract class ActionCenterException extends RuntimeException
{
    public function status(): int
    {
        return 422;
    }
}
