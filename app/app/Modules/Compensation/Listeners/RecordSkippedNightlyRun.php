<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Compensation\Support\NightlyRunAlert;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Carbon;

/**
 * Notices when the nightly chain was skipped because the previous one was still
 * running, and leaves the one trace such a night otherwise has.
 *
 * ScheduledTaskFinished rather than ScheduledTaskSkipped: Laravel reserves the
 * latter for a filter or a pause — the recompute's own `when()` among them,
 * which is a deliberate decision and not news. An OVERLAP goes down the normal
 * path and comes back through `finished` with the event flagged, having run
 * nothing at all.
 */
final class RecordSkippedNightlyRun
{
    private const SIGNATURE = 'compensation:nightly-run';

    public function handle(ScheduledTaskFinished $event): void
    {
        if (! $event->task->skippedBecauseOverlapping) {
            return;
        }

        if (! str_contains((string) $event->task->command, self::SIGNATURE)) {
            return;
        }

        NightlyRunAlert::skippedNight(
            Carbon::now(),
            'The previous nightly chain was still running at 00:05, so tonight\'s was skipped by withoutOverlapping(). '
            .'The next chain to start backfills the days this one would have cut off.',
        );
    }
}
