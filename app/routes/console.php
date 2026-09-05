<?php

use App\Modules\Commerce\Console\Commands\PurchaseOffersMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\AdcBonusRunCommand;
use App\Modules\Compensation\Console\Commands\AdcPurgeRejectedDocumentsCommand;
use App\Modules\Compensation\Console\Commands\AutoRetryFailedPayoutsCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusEnrollCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusRunCommand;
use App\Modules\Compensation\Console\Commands\GbbMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCommand;
use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;
use App\Modules\Compensation\Console\Commands\RankCheckCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseMonthlySnapshotCommand;
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

// Repurchase wallet month-end snapshot — 1st of each month at 00:06 IST, for
// the month that has just closed. It runs first on the 1st, before every
// engine that reads it: the GSB cut-off at 00:10, Rank at 00:30, GBB at 00:45,
// Fortune enrolment at 01:00, ADC at 01:15, Fortune payout at 03:15, monthly
// payout at 03:30. Without the row the gate fails open, so the ordering is
// what makes the gate mean anything at all.
Schedule::command(RepurchaseMonthlySnapshotCommand::class)
    ->monthlyOn(1, '00:06')
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

// On the 1st the monthly bonus engines fire in dependency order:
// snapshot 00:06 (gates read it) → rank qualifications 00:15 → Rank 00:30 →
// GBB 00:45 (needs the previous month's rank gate) → Fortune enrolment 01:00 →
// ADC 01:15 → Fortune payout 03:15 → monthly payout batch 03:30 (sweeps all
// credits just landed) → Offers 04:00 (reads previous month BV, grants nothing
// other engines depend on).

// Rank qualifications for the closed month. Rank Bonus only READS
// rank_qualifications — nothing else writes them — so this must succeed before
// 00:30 or the month is priced with no qualifiers: every RAP achiever is paid
// nothing while AO-GO grants still issue against the whole Rank-1 pool and
// consume a lifetime use. It ran unscheduled while Rank Bonus was on the 8th
// (a week of slack); on the 1st that slack is 15 minutes, so it is scheduled.
// The --month is explicit: the command defaults to the CURRENT month, which on
// the 1st is the month that has barely started.
Schedule::command(RankCheckCommand::class, [
    '--month' => now('Asia/Kolkata')->subMonthNoOverflow()->format('Y-m'),
])
    ->monthlyOn(1, '00:15')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(RankBonusRunCommand::class)
    ->monthlyOn(1, '00:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(GbbMonthlyRunCommand::class)
    ->monthlyOn(1, '00:45')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Fortune Bonus enrolment: a single batched pass keeps the FCFS matrix
// deterministic — every eligible distributor is placed in one go, ordered by
// their first GSB credit date.
Schedule::command(FortuneBonusEnrollCommand::class)
    ->monthlyOn(1, '01:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(AdcBonusRunCommand::class)
    ->monthlyOn(1, '01:15')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(FortuneBonusRunCommand::class)
    ->monthlyOn(1, '03:15')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Monthly payout batch runs after all crediting engines (Rank 00:30, GBB 00:45,
// Fortune 03:15, ADC 01:15) have completed.
Schedule::command(MonthlyPayoutCommand::class)
    ->monthlyOn(1, '03:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Failed payouts are re-sent daily at 11:00 IST — after both the Tuesday
// weekly batch (03:00) and the monthly payout batch (1st 03:30), so a transfer
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

// Purchase offers on the 1st at 04:00 IST. After the payout batch (03:30),
// because the offers read the previous month's BV and grant nothing that any
// other engine depends on.
Schedule::command(PurchaseOffersMonthlyRunCommand::class)
    ->monthlyOn(1, '04:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

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
