<?php

use App\Modules\Compensation\Console\Commands\AdcPurgeRejectedDocumentsCommand;
use App\Modules\Compensation\Console\Commands\AutoRetryFailedPayoutsCommand;
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use App\Modules\Compensation\Console\Commands\MonthlyCloseCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCloseCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Grievance\Console\Commands\GrievanceSlaSweepCommand;
use App\Modules\Payments\Console\Commands\ExpireUnpaidOrdersCommand;
use App\Modules\Payments\Console\Commands\PaymentsReconcileCommand;
use App\Modules\Payments\Console\Commands\PaymentsRedactEventsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily repurchase evaluation at 00:05 IST — refreshes each distributor's
// cycle status for the new day. Must run before the GSB cut-off at 00:10 so
// the cut-off reads the status this command computed for the new day.
// Flag-gated inside the command.
Schedule::command(RepurchaseEvaluateCommand::class)
    ->dailyAt('00:05')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Daily GSB cut-off: runs at 00:10 IST and processes the PREVIOUS day.
// Running at 23:59 sharp lost any BV still in flight at the boundary — an
// order paid at 23:58 whose PropagateGroupBvJob landed after the cut-off had
// already produced the day's result was never counted (a CREDITED result is
// idempotent and never recomputed). The 10-minute buffer lets queued
// propagation jobs land; results are still recorded against the day the BV
// belongs to. withoutOverlapping prevents concurrent runs.
Schedule::command(GsbDailyCutoffCommand::class, [
    '--date' => now('Asia/Kolkata')->subDay()->toDateString(),
])
    ->dailyAt('00:10')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Tuesday weekly payout at 03:00 IST (weeklyOn: 2 = Tuesday).
Schedule::command(GsbWeeklyPayoutCommand::class)
    ->weeklyOn(2, '03:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
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
