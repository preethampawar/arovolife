<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Exceptions;

/** A snooze shorter than a day or longer than `action_center.max_snooze_days`. */
final class InvalidSnoozeWindow extends ActionCenterException
{
    public static function for(int $days, int $max): self
    {
        return new self("A snooze must be between 1 and {$max} days; {$days} given.");
    }
}
