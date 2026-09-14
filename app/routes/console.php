<?php

use App\Modules\Compensation\Console\Commands\AdcPurgeRejectedDocumentsCommand;
use App\Modules\Compensation\Console\Commands\AutoRetryFailedPayoutsCommand;
use App\Modules\Compensation\Console\Commands\CompensationRecomputeAllCommand;
use App\Modules\Compensation\Console\Commands\EngineHealthDigestCommand;
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use App\Modules\Compensation\Console\Commands\MonthlyCloseCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCloseCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Grievance\Console\Commands\GrievanceSlaSweepCommand;
use App\Modules\Inventory\Console\Commands\InventoryAlertsCommand;
use App\Modules\Kyc\Console\Commands\PurgeExpiredDocumentsCommand;
use App\Modules\Payments\Console\Commands\ExpireUnpaidOrdersCommand;
use App\Modules\Payments\Console\Commands\PaymentsReconcileCommand;
use App\Modules\Payments\Console\Commands\PaymentsRedactEventsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * May the compensation engines run tonight?
 *
 * ALWAYS yes in production — RecomputeState returns true before it touches the
 * cache or the database whenever the recompute gate is shut, which it always is
 * there. On a dev or staging environment it is no while a projection is
 * standing (the derived state is already ahead of the scheduler, so tonight's
 * cut-off would abort on the carry-forward out-of-order guard) or while a
 * replay is in flight (it is rewriting the very rows the engine would read).
 *
 * The nightly reset at 23:30 puts such an environment back to
 * production-faithful, which lifts this by itself.
 *
 * It covers the retry sweep too, which is the only one of these that reaches a
 * bank: a projection builds real payout batches for a date that has not
 * arrived, and re-dispatching their failed lines would send simulated amounts
 * to real accounts the moment the gateway is anything but Manual NEFT.
 */
$compensationEnginesMayRun = static fn (): bool => app(RecomputeState::class)->schedulerEnginesAllowed();

// Daily repurchase evaluation at 00:05 IST — refreshes each distributor's
// cycle status for the new day. Must run before the GSB cut-off at 00:10 so
// the cut-off reads the status this command computed for the new day.
// Flag-gated inside the command.
Schedule::command(RepurchaseEvaluateCommand::class)
    ->dailyAt('00:05')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// Daily GSB cut-off: runs at 00:10 IST and processes the PREVIOUS day.
// Running at 23:59 sharp lost any BV still in flight at the boundary — an
// order paid at 23:58 whose PropagateGroupBvJob landed after the cut-off had
// already produced the day's result was never counted (a CREDITED result is
// idempotent and never recomputed). The 10-minute buffer lets queued
// propagation jobs land; results are still recorded against the day the BV
// belongs to. withoutOverlapping prevents concurrent runs.
//
// The five minutes between 00:05 and 00:10 are an ordering HINT, not a
// guarantee: withoutOverlapping() is per-command, so nothing here serialises
// the cut-off behind the evaluation — an evaluation that overran five minutes,
// failed, or never started would let the cut-off proceed on yesterday's
// repurchase verdicts. The guarantee is inside the command: while the
// repurchase engine is on, gsb:daily-cutoff REFUSES (exit 1, a FAILED engine
// run) unless repurchase:evaluate has a succeeded run that has SEEN the whole
// cut-off day — dated later than it, or dated for it but started after it
// ended. The 00:05 run on the cut-off day itself does not count: a cycle
// fulfilled later that day would still read as failed. Here that is satisfied
// by the 00:05 run of the following morning, which is dated for the day the
// cut-off is not processing. A forfeited day credited (or a fulfilled day
// forfeited) by mistake is never corrected, so the cut-off would rather not run
// than run on a stale verdict.
Schedule::command(GsbDailyCutoffCommand::class, [
    '--date' => now('Asia/Kolkata')->subDay()->toDateString(),
])
    ->dailyAt('00:10')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// Tuesday weekly payout at 03:00 IST (weeklyOn: 2 = Tuesday).
Schedule::command(GsbWeeklyPayoutCommand::class)
    ->weeklyOn(2, '03:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// ── The monthly close ────────────────────────────────────────────────────────
// Two orchestrators replace eight independent monthly entries.
//
// The eight were sequenced only by 15-minute clock offsets, and
// withoutOverlapping() is per-command: it does NOT serialise across commands.
// If rank:check-qualifications overran its 15 minutes, Rank Bonus fired at
// 00:30 against an empty rank_qualifications table, priced the month with no
// qualifiers, froze it, and never retried. The 1st is also the heaviest night
// of the month — the GSB cut-off at 00:10 settles the closed month's last day —
// so the slack was thinnest exactly when it mattered most.
//
// The individual engines are unchanged and each still records its own
// engine_runs row; only who invokes them has moved.

// Crediting, 1st at 00:20 IST — after the 00:10 cut-off, which it waits for
// rather than assumes. One process, one lock, eight steps in dependency order,
// aborting at the first non-zero exit. A re-run resumes at the first step that
// has not succeeded.
Schedule::command(MonthlyCloseCommand::class, [
    '--month' => now('Asia/Kolkata')->subMonthNoOverflow()->format('Y-m'),
])
    ->monthlyOn(1, '00:20')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// Payment, 8th at 04:00 IST. A week after crediting, because the monthly payout
// batch is idempotent per month: once it has swept the wallet there is nowhere
// for a late credit to go. The week is the window in which a bad month can
// still be caught, and MonthlyEngineCompletionGate is what makes it mean
// something — the batch refuses unless every crediting engine for the month
// succeeded. 04:00 rather than 03:30 keeps it clear of the weekly GSB batch at
// Tuesday 03:00, which consults the same monthly income cap.
Schedule::command(MonthlyPayoutCloseCommand::class, [
    '--month' => now('Asia/Kolkata')->subMonthNoOverflow()->format('Y-m'),
])
    ->monthlyOn(8, '04:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// ── The nightly reset (dev and staging only) ─────────────────────────────────
// A projection leaves simulated rows standing: next Tuesday's payout sweep, the
// 1st's monthly close, the 8th's batch, all computed on a clock that has not
// arrived. Useful for an afternoon of testing, wrong to wake up to — and it
// holds the scheduled engines paused above for as long as it stands.
//
// 23:30 puts the environment back to production-faithful before the 00:05
// engines are due. `--if-projected` makes it a no-op on an environment that is
// already faithful, so this never wipes and rebuilds for no reason; the `when`
// keeps the whole entry out of production, where RecomputeGuard refuses anyway.
Schedule::command(CompensationRecomputeAllCommand::class, [
    '--horizon' => 'now',
    // Bare values, not `=> true`: Laravel renders a keyed entry as
    // `--if-projected='1'`, and a value-less Symfony option refuses a value.
    '--if-projected',
    '--force',
])
    ->dailyAt('23:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    // A POSITIVE allow-list, not merely "is this not production". This is the
    // only unattended destructive command on the platform — `--force` skips the
    // typed-database confirmation by design — so it must not be one mis-set
    // APP_ENV away from wiping a real database on a timer. RecomputeGuard's own
    // three locks still apply on top; this narrows which environments may even
    // ask.
    ->when(static fn (): bool => app()->environment(['local', 'testing', 'staging'])
        && app(RecomputeGuard::class)->isPermitted())
    ->runInBackground();

// Daily engine-health digest at 08:00 IST — after every overnight engine
// (monthly payout close is the last, 04:00 on the 8th). Emails the admin
// mailbox only when a run failed, a scheduled period never ran, or a run is
// stuck; a healthy day sends nothing. Recipient: notifications.engine_health_email.
Schedule::command(EngineHealthDigestCommand::class)
    ->dailyAt('08:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    // Paused with the engines it reports on: while they are held, every period
    // they did not compute reads as overdue and the digest would mail a page of
    // failures that are the pause working correctly.
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// Failed payouts are re-sent daily at 11:00 IST — after both the Tuesday
// weekly batch (03:00) and the monthly payout batch (8th 04:00), so a transfer
// that failed on this morning's dispatch gets its first automatic second chance
// the next day. Only line items past the configured staleness window and under
// the retry limit are picked up; the command is a no-op in Manual NEFT mode.
Schedule::command(AutoRetryFailedPayoutsCommand::class)
    ->dailyAt('11:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// Grievance SLA sweep — hourly. Stamps acknowledgement / first-response /
// resolution breaches the moment a published clock lapses, auto-escalates
// tickets that have sat too long at one step of the policy §4 ladder, and
// nudges the owning officer when a third-party-dependent grievance is due its
// 15-day progress update.
//
// Hourly rather than daily because the acknowledgement promise is measured in
// hours (48), not days: a once-a-day sweep could record a breach up to 24
// hours after it happened, which is the one number in the monthly compliance
// report that has to be exact.
Schedule::command(GrievanceSlaSweepCommand::class)
    ->hourly()
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// ADC application documents of rejected applications are erased 90 days after
// the decision (DPDP §8(7) storage limitation, R-62). Daily, quiet hour.
Schedule::command(AdcPurgeRejectedDocumentsCommand::class)
    ->dailyAt('03:15')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

// KYC scans are kept for the admin-owned retention period
// (`kyc.document_retention_days`) after the review they served, then erased
// (DPDP §8(7), R-31; client decision 2026-09-11). Daily, quiet hour.
Schedule::command(PurgeExpiredDocumentsCommand::class)
    ->dailyAt('03:25')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();

// ── Payments ─────────────────────────────────────────────────────────────────
// Every five minutes: ask Razorpay about open intents older than three
// minutes and confirm from its answer — the backstop for a lost callback or
// a delayed webhook. Never cancels.
Schedule::command(PaymentsReconcileCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Every five minutes, offset from the reconciler: release online orders left
// unpaid past the expiry window, after a final check with the gateway.
Schedule::command(ExpireUnpaidOrdersCommand::class)
    ->cron('2-59/5 * * * *')
    ->withoutOverlapping()
    ->runInBackground();

// Daily 03:15 IST: drop gateway payloads older than the dispute window.
Schedule::command(PaymentsRedactEventsCommand::class)
    ->dailyAt('03:15')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// ── Inventory ────────────────────────────────────────────────────────────────
// Daily low-stock / expiry alert email (plan §7.3). Guarded inside the command
// by InventoryFeature, the same killswitch as checkout enforcement — off means
// nothing is sent, whatever the schedule says.
Schedule::command(InventoryAlertsCommand::class)
    ->dailyAt('08:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();
