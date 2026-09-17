<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The durable record of something the nightly chain could not do.
 *
 * Three events, one home, because they share a shape no other check in the
 * platform can see: nothing failed. No command exited non-zero, no
 * `engine_runs` row is wrong, and the chain returns 0 — yet a day, a week or a
 * month of product-sale-driven commission has not been computed.
 *
 *   • SKIPPED NIGHT — `withoutOverlapping()` found the previous chain still
 *     running, so Laravel returned from the event without starting the command
 *     at all. No run, no exit code, nothing.
 *   • BACKFILL GAP — the missed nights reach further back than the chain may
 *     heal unattended ({@see NightlyRunCommand::MAX_BACKFILL_DAYS}), so it did
 *     last night and left the rest. The health digest cannot see this either:
 *     it judges each engine on its most recent fire, and last night succeeded.
 *   • MONTH CLOSE DEFERRED — a month whose days are not all cut off must not be
 *     closed, because every monthly engine freezes what it computes and a month
 *     priced short stays priced short. Refusing is correct; refusing silently
 *     is not — nobody receives Growth Booster, Rank Bonus, Fortune or ADC for
 *     that month until someone acts.
 *
 * An audit row rather than an `engine_runs` row in every case: nothing ran, and
 * a run row for a process that never started would be reported for thirty days
 * as a failure to re-run when what is owed is a look at why.
 */
final class NightlyRunAlert
{
    public const ACTION_SKIPPED_NIGHT = 'compensation.nightly_run_skipped';

    public const ACTION_BACKFILL_GAP = 'compensation.nightly_run_backfill_gap';

    public const ACTION_MONTH_DEFERRED = 'compensation.nightly_run_month_deferred';

    /** Every action this class writes — what the health digest reads back. */
    public const ACTIONS = [
        self::ACTION_SKIPPED_NIGHT,
        self::ACTION_BACKFILL_GAP,
        self::ACTION_MONTH_DEFERRED,
    ];

    /** A night the scheduler never started because the previous one was still running. */
    public static function skippedNight(Carbon $night, string $reason): void
    {
        self::record(self::ACTION_SKIPPED_NIGHT, $night, $reason, ['date' => $night->toDateString()]);
    }

    /**
     * Days the chain will not heal by itself, with the range it left behind.
     */
    public static function backfillGap(Carbon $night, string $reason, ?string $lastProvenDate): void
    {
        self::record(self::ACTION_BACKFILL_GAP, $night, $reason, [
            'date' => $night->toDateString(),
            'last_proven_cutoff' => $lastProvenDate,
        ]);
    }

    /** A month the chain refused to close because its cut-offs are incomplete. */
    public static function monthCloseDeferred(Carbon $night, Carbon $month, int $missingDays, string $reason): void
    {
        self::record(self::ACTION_MONTH_DEFERRED, $night, $reason, [
            'date' => $night->toDateString(),
            'month' => $month->format('Y-m'),
            'missing_days' => $missingDays,
        ]);
    }

    /**
     * One row per action per night, however many times a minute the scheduler
     * asks — and a fresh row each night the condition still holds, which is
     * what keeps an unhealed gap in front of a reader instead of scrolling out
     * of the digest after the first morning.
     *
     * Keyed on the night the row is ABOUT, not on the day it was written: a
     * chain replayed by hand for a past night (`--date=`) would otherwise write
     * another row on every invocation, because the two dates never match.
     *
     * @param  array<string, mixed>  $details
     */
    private static function record(string $action, Carbon $night, string $reason, array $details): void
    {
        try {
            $alreadyRecorded = AuditLog::query()
                ->where('action', $action)
                ->where('details->date', $night->toDateString())
                ->exists();

            if ($alreadyRecorded) {
                return;
            }

            AuditLog::create([
                'actor_id' => null,
                'action' => $action,
                'subject_type' => 'platform',
                'subject_id' => 0,
                'details' => [...$details, 'reason' => $reason],
            ]);
        } catch (Throwable $e) {
            // The alert must never be the thing that breaks the run it reports on.
            Log::error('compensation.nightly_run.alert_failed', [
                'action' => $action,
                'date' => $night->toDateString(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
