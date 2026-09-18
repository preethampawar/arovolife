<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\NightlyRunAlert;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Carbon;

/**
 * Notices when one of the three scheduled runs was skipped because the previous
 * one was still running, and leaves the one trace such a night otherwise has.
 *
 * ScheduledTaskFinished rather than ScheduledTaskSkipped: Laravel reserves the
 * latter for a filter or a pause — the recompute's own `when()` among them, and
 * the weekly and monthly runs' "is anything owed tonight" predicates, which are
 * deliberate decisions and not news. An OVERLAP goes down the normal path and
 * comes back through `finished` with the event flagged, having run nothing at
 * all.
 *
 * Matched against {@see EngineRegistry::rootOrchestratorKeys()} rather than one
 * hard-coded signature: there are three runs now, and a fourth would otherwise
 * be the one whose skipped nights nobody ever hears about.
 */
final class RecordSkippedOrchestratorRun
{
    public function handle(ScheduledTaskFinished $event): void
    {
        if (! $event->task->skippedBecauseOverlapping) {
            return;
        }

        $command = (string) $event->task->command;

        foreach (EngineRegistry::rootOrchestratorKeys() as $key) {
            $signature = EngineRegistry::get($key)->commandSignature;

            // Anchored on a word boundary and a trailing space or end of string:
            // the scheduler's command string is a whole shell line, and a bare
            // str_contains would let one run's entry answer for another's the
            // day two signatures share a prefix.
            if (preg_match('/\b'.preg_quote($signature, '/').'(\s|$)/', $command) !== 1) {
                continue;
            }

            NightlyRunAlert::skippedNight(Carbon::now(), self::reasonFor($key), $key);

            return;
        }
    }

    /**
     * What was actually lost, per run — and what heals it.
     *
     * Each sentence names the run's own clock and its own self-heal, because
     * the three heal differently: the nightly run backfills days, the weekly
     * run rebuilds a Tuesday still dated that Tuesday, and the monthly run
     * simply re-asks what the month owes.
     */
    private static function reasonFor(string $key): string
    {
        return match ($key) {
            'compensation.weekly-run' => 'The previous weekly run was still going, so tonight\'s was skipped at '
                .'03:00 by withoutOverlapping(). The next night builds any Tuesday this one would have, still dated '
                .'that Tuesday.',
            'compensation.monthly-run' => 'The previous monthly run was still going, so tonight\'s was skipped at '
                .'04:00 by withoutOverlapping(). The next night re-evaluates what the month owes — a close, a payout '
                .'or neither.',
            default => 'The previous nightly run was still running at 00:05, so tonight\'s was skipped by '
                .'withoutOverlapping(). The next nightly run backfills the days this one would have cut off.',
        };
    }
}
