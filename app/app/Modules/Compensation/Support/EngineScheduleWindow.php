<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Carbon;

/**
 * The [00:00, 05:00) IST window that comfortably contains every scheduled
 * engine — repurchase evaluation (00:05), the GSB/MSB daily cut-off (00:10),
 * the monthly close (1st, 00:20) and its payout (8th, 04:00), the weekly
 * payout (Tuesday, 03:00), and the KYC/ADC/payments quiet-hour jobs (03:15,
 * 03:25) — see routes/console.php for the authoritative schedule; revisit
 * these constants if it changes. A destructive reset that lands inside it can
 * truncate engine_runs and result tables out from under a run that is
 * mid-flight, or erase the evidence of one that just finished.
 *
 * This narrows but does not close the exact hole staging hit on 2026-08-31/
 * 09-01: platform:reset-purchases ran once at 15:50 the day before the
 * monthly close and again at 08:54 the following morning — both OUTSIDE this
 * window — leaving the engine-health digest reading "no run recorded" for
 * every monthly engine even though the close may well have succeeded before
 * its evidence was wiped by the second run. This guard stops a reset from
 * landing DURING the run itself; it does not stop one shortly before or
 * after. A fuller fix would refuse whenever an EngineRun exists for the
 * current day, not just by clock hour — tracked as a follow-up.
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
