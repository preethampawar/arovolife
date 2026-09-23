<?php

use App\Modules\Commerce\Console\Commands\PurgeOfflinePaymentProofsCommand;
use App\Modules\Compensation\Console\Commands\AdcPurgeRejectedDocumentsCommand;
use App\Modules\Compensation\Console\Commands\AutoRetryFailedPayoutsCommand;
use App\Modules\Compensation\Console\Commands\CompensationRecomputeAllCommand;
use App\Modules\Compensation\Console\Commands\EngineHealthDigestCommand;
use App\Modules\Compensation\Console\Commands\MonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\NightlyRunCommand;
use App\Modules\Compensation\Console\Commands\WeeklyRunCommand;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Compensation\Support\MonthlyRunPlanner;
use App\Modules\Compensation\Support\WeeklyRunPlanner;
use App\Modules\Grievance\Console\Commands\GrievanceSlaSweepCommand;
use App\Modules\Inventory\Console\Commands\InventoryAlertsCommand;
use App\Modules\Inventory\Console\Commands\VerifyStockLedgerCommand;
use App\Modules\Kyc\Console\Commands\PurgeExpiredDocumentsCommand;
use App\Modules\Payments\Console\Commands\ExpireUnpaidOrdersCommand;
use App\Modules\Payments\Console\Commands\PaymentsReconcileCommand;
use App\Modules\Payments\Console\Commands\PaymentsRedactEventsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
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

// ── The three compensation runs ──────────────────────────────────────────────
// Three entries, three cadences, three run rows: the nightly run at 00:05, the
// weekly run at 03:00 and the monthly run at 04:00 (ADR-0016).
//
// Until 2026-09-18 one entry ran all of it, and one `engine_runs` row carried
// all three cadences: a monthly step that REFUSED — a month whose days were not
// all cut off, a payout the completion gate held back — marked the night FAILED
// although the daily work had finished perfectly. Splitting them gives each
// cadence its own row, its own alerts and its own self-heal.
//
// The ordering the client asked for (daily, then weekly, then monthly) is held
// by the RUN LOG, not by these clocks: each run asks RunPrerequisites whether
// tonight's earlier run actually succeeded, and defers itself as a `skipped`
// row plus an alert when it has not. A shared cache lock was rejected — the
// default store is a Redis shared with eight other apps under `allkeys-lfu`,
// which may evict a lock key silently (ADR-0011).
//
// 00:05 rather than 23:59: the cut-off prices the day that has just ended, and
// an order paid at 23:58 whose PropagateGroupBvJob lands a moment later must
// still count. The night starts five minutes in so queued propagation can land;
// results are still recorded against the day the BV belongs to.
Schedule::command(NightlyRunCommand::class)
    ->dailyAt('00:05')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// Weekly run — 03:00 every night, but it STARTS only when a Tuesday batch is
// owed: on Tuesdays, and on any other night only when a Tuesday was never
// built. The predicate is evaluated once, at 03:00, in the scheduler process; a
// false answer starts no process and writes no row. The prerequisite on
// tonight's nightly run is checked INSIDE the command, so a deferral is
// recorded rather than silently skipped.
//
// 03:00 is the position gsb.weekly-payout has always declared, and it owes
// nothing to tonight's cut-off: the batch dated Tuesday T sweeps only entries
// earned on or before T−7.
Schedule::command(WeeklyRunCommand::class)
    ->dailyAt('03:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when(static fn (): bool => $compensationEnginesMayRun()
        && app(WeeklyRunPlanner::class)->isDue(Carbon::today('Asia/Kolkata')))
    ->runInBackground();

// Monthly run — 04:00: the 1st, any night a closable month is still open, and
// from the 8th while the month's payout batch is missing. See
// MonthlyRunPlanner::isDue(). An hour after the weekly run so that on the 8th
// the two sweeps keep today's order; they share PayoutService's blocking sweep
// lock, which serialises them against the ₹50L income cap.
Schedule::command(MonthlyRunCommand::class)
    ->dailyAt('04:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->when(static fn (): bool => $compensationEnginesMayRun()
        && app(MonthlyRunPlanner::class)->isDue(Carbon::today('Asia/Kolkata')))
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

// Daily engine-health digest at 08:00 IST — after every overnight run (the
// monthly run is the last, 04:00). Emails the admin mailbox only when a run
// failed, a scheduled period never ran, or a run is stuck; a healthy day
// sends nothing. Recipient: notifications.engine_health_email.
Schedule::command(EngineHealthDigestCommand::class)
    ->dailyAt('08:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    // Paused with the engines it reports on: while they are held, every period
    // they did not compute reads as overdue and the digest would mail a page of
    // failures that are the pause working correctly.
    ->when($compensationEnginesMayRun)
    ->runInBackground();

// Failed payouts are re-sent daily at 11:00 IST — after both the weekly run
// (03:00) and the monthly run (04:00), so a transfer that failed on this
// morning's dispatch gets its first automatic second chance the next day. Only line items past the configured staleness window and under
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

// Offline-payment proof files (deposit slips, UPI screenshots) are kept for
// their admin-owned retention periods, then erased (DPDP §8(7), R-107).
Schedule::command(PurgeOfflinePaymentProofsCommand::class)
    ->dailyAt('03:35')
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

// Weekly Monday 03:00 IST: prove the stock projections still agree with the
// movement ledger, and that no reservation outlives the order holding it.
// resources/help/inventory-management.md has told operators this runs weekly
// since the module shipped; it never did — the command was registered but
// never scheduled. Read-only: it reports and exits non-zero, it never writes.
Schedule::command(VerifyStockLedgerCommand::class)
    ->weeklyOn(1, '03:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();
