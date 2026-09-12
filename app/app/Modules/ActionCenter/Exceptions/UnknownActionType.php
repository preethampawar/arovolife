<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Exceptions;

/** A key that no registered provider answers to. */
final class UnknownActionType extends ActionCenterException
{
    public static function for(string $key): self
    {
        return new self("No action provider is registered for [{$key}].");
    }

    public function status(): int
    {
        return 404;
    }
}
