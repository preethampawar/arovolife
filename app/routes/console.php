<?php

use App\Modules\Compensation\Console\Commands\AdcPurgeRejectedDocumentsCommand;
use App\Modules\Compensation\Console\Commands\AutoRetryFailedPayoutsCommand;
use App\Modules\Compensation\Console\Commands\CompensationRecomputeAllCommand;
use App\Modules\Compensation\Console\Commands\EngineHealthDigestCommand;
use App\Modules\Compensation\Console\Commands\NightlyRunCommand;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Grievance\Console\Commands\GrievanceSlaSweepCommand;
use App\Modules\Inventory\Console\Commands\InventoryAlertsCommand;
use App\Modules\Inventory\Console\Commands\VerifyStockLedgerCommand;
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

// ── The nightly chain ────────────────────────────────────────────────────────
// One entry replaces five: repurchase evaluation, the GSB daily cut-off, the
// monthly crediting close, the Tuesday weekly payout batch and the 8th's
// monthly payout close.
//
// Those five were sequenced only by clock offsets — 00:05, 00:10, 00:20,
// Tuesday 03:00, the 8th at 04:00 — and withoutOverlapping() is per-command: it
// does NOT serialise across commands. Nothing held the order. An evaluation
// that overran five minutes, failed, or never started still let the cut-off
// proceed on yesterday's repurchase verdicts, and a day forfeited by mistake is
// never corrected afterwards. The offsets also bought their safety with dead
// time: the monthly close polled for up to ten minutes for a cut-off it had no
// way to observe, because it was a separate process.
//
// Inside one process the ordering is real — a step runs only after the one
// before it exited 0 — and each engine fires the moment its inputs are ready.
// The engines themselves are unchanged and each still records its own
// engine_runs row; only who invokes them has moved. Each remains individually
// runnable from the CLI.
//
// 00:05 rather than 23:59: the cut-off prices the day that has just ended, and
// an order paid at 23:58 whose PropagateGroupBvJob lands a moment later must
// still count. The chain starts five minutes into the new day so queued
// propagation can land; results are still recorded against the day the BV
// belongs to.
Schedule::command(NightlyRunCommand::class)
    ->dailyAt('00:05')
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
