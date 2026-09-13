<?php

declare(strict_types=1);

namespace App\Console\Actions;

use App\Modules\Compensation\Support\EngineScheduleWindow;
use RuntimeException;

/**
 * Thrown when platform:reset-purchases is attempted inside the nightly engine
 * window. See {@see EngineScheduleWindow}.
 */
final class PurchaseResetBlocked extends RuntimeException
{
    public static function duringEngineWindow(): self
    {
        return new self(
            'Refusing to reset purchase data: it is between 00:00 and 05:00 IST, when the '
            .'repurchase, GSB/MSB cut-off, monthly close and payout engines run. This truncates '
            ."engine_runs and every engine's result tables — running it now can erase the evidence "
            .'of a run that is mid-flight or just finished, and read as "no run recorded" on the '
            .'next health digest. Wait until after 05:00 IST.'
        );
    }
}
