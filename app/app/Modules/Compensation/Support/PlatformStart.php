<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;

/**
 * The first day the platform had anybody in it.
 *
 * Days before it are not owed a daily cut-off: with no distributor there is
 * nobody to hold BV, no group to match, no carry-forward to advance and no
 * credit to compute. That is what lets a platform close the month it launched
 * in — otherwise the launch month is short by however many days preceded the
 * launch, every monthly engine is refused for it, and from the 8th of the next
 * month the monthly run fails on a month that can never be completed.
 *
 * Deliberately NOT "the first day with a sale". A day on which distributors
 * existed and bought nothing is still a day the engines owe a cut-off for, and
 * a stretch of such days is also what a broken order feed looks like.
 *
 * Not cached: `distributors.effective_date` carries no index today, so caching
 * would trade a cheap MIN for a stale answer on the one question that decides
 * whether a month may be frozen.
 */
final class PlatformStart
{
    public static function firstDay(): ?Carbon
    {
        $earliest = Distributor::query()->min('effective_date');

        return is_string($earliest) && $earliest !== ''
            ? Carbon::parse($earliest)->startOfDay()
            : null;
    }

    /**
     * The first day of $month that is owed a cut-off — the month's own start,
     * unless the platform began part-way through it.
     *
     * Null when the month owes nothing at all: no distributor has ever existed,
     * or the first one joined after this month had already ended. Null is not
     * "everything is owed"; it is "nothing is", and every caller must say so
     * explicitly rather than fall back to the month's length.
     */
    public static function owedFrom(Carbon $monthStart): ?Carbon
    {
        $first = self::firstDay();

        if ($first === null) {
            return null;
        }

        $lastDay = $monthStart->copy()->endOfMonth()->startOfDay();

        if ($first->greaterThan($lastDay)) {
            return null;
        }

        return $first->greaterThan($monthStart) ? $first : $monthStart->copy();
    }
}
