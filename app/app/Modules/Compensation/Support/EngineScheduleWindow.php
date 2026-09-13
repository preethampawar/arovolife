<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * The nightly window every scheduled engine occupies — repurchase evaluation,
 * the GSB/MSB daily cut-off, the monthly close and the weekly/monthly payouts
 * (see routes/console.php: 00:05 through 04:00 IST). A destructive reset that
 * lands here can truncate engine_runs and result tables out from under a run
 * that is mid-flight, or erase the evidence of one that just finished.
 *
 * This is exactly what happened on staging 2026-08-31/09-01:
 * platform:reset-purchases ran once at 15:50 the day before the monthly close
 * and again at 08:54 the following morning, after that night's monthly close
 * had already run — leaving the engine-health digest reading "no run
 * recorded" for every monthly engine even though the close may well have
 * succeeded before its evidence was wiped.
 */
final class EngineScheduleWindow
{
    private const START_HOUR = 0;

    private const END_HOUR = 5;

    public static function isActiveNow(): bool
    {
        $hour = Carbon::now('Asia/Kolkata')->hour;

        return $hour >= self::START_HOUR && $hour < self::END_HOUR;
    }
}
