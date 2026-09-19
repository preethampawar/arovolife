<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The durable record of something a scheduled run could not do.
 *
 * Five events, one home, because they share a shape no other check in the
 * platform can see: nothing failed. No command exited non-zero, no
 * `engine_runs` row is wrong, and the run returns 0 — yet a day, a week or a
 * month of product-sale-driven commission has not been computed.
 *
 *   • SKIPPED NIGHT — `withoutOverlapping()` found the previous run of that
 *     orchestrator still going, so Laravel returned from the event without
 *     starting the command at all. No run, no exit code, nothing.
 *   • BACKFILL GAP — the missed nights reach further back than the nightly run
 *     may heal unattended ({@see NightlyRunCommand::MAX_BACKFILL_DAYS}), so it
 *     did last night and left the rest. The health digest cannot see this
 *     either: it judges each engine on its most recent fire, and last night
 *     succeeded.
 *   • MONTH CLOSE DEFERRED — a month whose days are not all cut off must not be
 *     closed, because every monthly engine freezes what it computes and a month
 *     priced short stays priced short. Refusing is correct; refusing silently
 *     is not — nobody receives Growth Booster, Rank Bonus, Fortune or ADC for
 *     that month until someone acts.
 *   • PAYOUT DEFERRED — the month's crediting is incomplete, so the monthly
 *     payout batch was not built. Distributors are not paid for that month
 *     until the engine the gate names has succeeded.
 *   • WEEKLY DEFERRED — tonight's nightly run had not succeeded, so the Tuesday
 *     batch waited (the client's ordering rule, 2026-09-18). It costs a day,
 *     never a figure: the batch is still dated that Tuesday when it is built.
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

    public const ACTION_PAYOUT_DEFERRED = 'compensation.nightly_run_payout_deferred';

    public const ACTION_WEEKLY_DEFERRED = 'compensation.weekly_run_deferred';

    /** Every action this class writes — what the health digest reads back. */
    public const ACTIONS = [
        self::ACTION_SKIPPED_NIGHT,
        self::ACTION_BACKFILL_GAP,
        self::ACTION_MONTH_DEFERRED,
        self::ACTION_PAYOUT_DEFERRED,
        self::ACTION_WEEKLY_DEFERRED,
    ];

    /**
     * A night the scheduler never started because the previous run of that
     * orchestrator was still going.
     *
     * Keyed on the orchestrator as well as the night: three runs fire on their
     * own clocks now, and a nightly overlap says nothing about whether the
     * weekly one started. One row each, or the second skip of a night is
     * silently swallowed by the first.
     */
    public static function skippedNight(Carbon $night, string $reason, string $orchestratorKey): void
    {
        self::record(
            self::ACTION_SKIPPED_NIGHT,
            $night,
            $reason,
            ['date' => $night->toDateString(), 'orchestrator' => $orchestratorKey],
            ['date', 'orchestrator'],
        );
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

    /**
     * A month the monthly run refused to close, and why it refused.
     *
     * One row per NIGHT per month per cause. The night is in the key because
     * the health digest reads a seven-day window: a month deferred once, on the
     * 1st, would otherwise be out of the digest by the 9th while nobody had yet
     * received a rupee of that month's Growth Booster, Rank Bonus, Fortune or
     * ADC. A standing refusal has to keep standing in front of a reader.
     *
     * The cause stays in the key as well, because the three causes call for
     * three different actions (cut the missing days off; wait for the cut-off
     * to finish; wait for tonight's runs to go green) and a night on which the
     * cause changes is a night that has something new to say.
     *
     * $missingDays is null for a cause that is not about coverage.
     *
     * @param  'coverage'|'cutoff_in_flight'|'prerequisite'  $cause
     */
    public static function monthCloseDeferred(Carbon $night, Carbon $month, ?int $missingDays, string $cause, string $reason): void
    {
        self::record(
            self::ACTION_MONTH_DEFERRED,
            $night,
            $reason,
            [
                'date' => $night->toDateString(),
                'month' => $month->format('Y-m'),
                'missing_days' => $missingDays,
                'cause' => $cause,
            ],
            ['date', 'month', 'cause'],
        );
    }

    /**
     * A monthly payout batch the monthly run did not build, because the month's
     * crediting is incomplete.
     *
     * One row per night per month — the standing alert. It repeats each night
     * on purpose: unlike a deferred close, nothing here heals itself, and the
     * engine that must be re-run is named on every row.
     */
    public static function payoutDeferred(Carbon $night, Carbon $creditingMonth, ?string $engineKey, string $reason): void
    {
        self::record(
            self::ACTION_PAYOUT_DEFERRED,
            $night,
            $reason,
            [
                'date' => $night->toDateString(),
                'month' => $creditingMonth->format('Y-m'),
                'engine_key' => $engineKey,
            ],
            ['date', 'month'],
        );
    }

    /**
     * The Tuesday batch(es) that waited on tonight's nightly run.
     *
     * One row per night: the wait is the client's ordering rule, so what a
     * reader needs is "it did not run tonight", not one row per Tuesday.
     *
     * @param  list<Carbon>  $tuesdays
     */
    public static function weeklyDeferred(Carbon $night, array $tuesdays, string $reason): void
    {
        self::record(
            self::ACTION_WEEKLY_DEFERRED,
            $night,
            $reason,
            [
                'date' => $night->toDateString(),
                'tuesdays' => array_map(static fn (Carbon $day): string => $day->toDateString(), $tuesdays),
            ],
        );
    }

    /**
     * One row per action per dedupe key, however many times a minute the
     * scheduler asks — and, for a key that carries the night, a fresh row each
     * night the condition still holds, which is what keeps an unhealed gap in
     * front of a reader instead of scrolling out of the digest after the first
     * morning.
     *
     * Keyed on what the row is ABOUT, not on the day it was written: a run
     * replayed by hand for a past night (`--date=`) would otherwise write
     * another row on every invocation, because the two dates never match.
     *
     * $uniqueBy names which of $details make the row unique — `['date']` for
     * the per-night alerts, and a longer key for one that says more than one
     * thing about a night (`['date', 'month', 'cause']`: a deferred close is
     * repeated every night it holds, because the digest only looks seven days
     * back).
     *
     * @param  array<string, mixed>  $details
     * @param  list<string>  $uniqueBy  Keys of $details.
     */
    private static function record(string $action, Carbon $night, string $reason, array $details, array $uniqueBy = ['date']): void
    {
        try {
            $query = AuditLog::query()->where('action', $action);

            foreach ($uniqueBy as $key) {
                $query->where("details->{$key}", $details[$key] ?? null);
            }

            if ($query->exists()) {
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
