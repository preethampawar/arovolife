<?php

use App\Modules\Commerce\Console\Commands\PurchaseOffersMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\AdcBonusRunCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusEnrollCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusRunCommand;
use App\Modules\Compensation\Console\Commands\FranchiseMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\GbbMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCommand;
use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Grievance\Console\Commands\GrievanceSlaSweepCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily repurchase evaluation at 00:30 IST — refreshes each distributor's
// cycle status for the new day. The GSB cut-off for a given date D runs at
// 00:10 on D+1 and therefore reads the status this command computed on D's
// own morning, which is the correct as-of-date view. Flag-gated inside the
// command.
Schedule::command(RepurchaseEvaluateCommand::class)
    ->dailyAt('00:30')
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

// Tuesday weekly payout at 09:00 IST (weeklyOn: 2 = Tuesday).
Schedule::command(GsbWeeklyPayoutCommand::class)
    ->weeklyOn(2, '09:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// GBB runs on the 2nd of each month at 08:00 IST (after the previous month's orders are settled).
Schedule::command(GbbMonthlyRunCommand::class)
    ->monthlyOn(2, '08:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Rank Bonus runs on the 8th of each month at 08:00 IST.
Schedule::command(RankBonusRunCommand::class)
    ->monthlyOn(8, '08:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Fortune Bonus enrolment runs on the 9th at 08:45 IST, immediately before the
// 09:00 payout run and for the same (previous) month. A single batched pass is
// what keeps the FCFS matrix deterministic: every eligible distributor is
// placed in one go, ordered by their first GSB credit date.
Schedule::command(FortuneBonusEnrollCommand::class)
    ->monthlyOn(9, '08:45')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Fortune Bonus runs on the 9th of each month at 09:00 IST (after rank bonus is processed).
Schedule::command(FortuneBonusRunCommand::class)
    ->monthlyOn(9, '09:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// ADC Bonus runs on the 8th of each month at 09:30 IST (after rank bonus at 08:00).
Schedule::command(AdcBonusRunCommand::class)
    ->monthlyOn(8, '09:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Monthly payout (Groups B/C/D) runs on the 9th at 10:30 IST, after all monthly
// engines (GBB 2nd, Rank 8th, Fortune 9th 09:00, ADC 8th) have completed.
Schedule::command(MonthlyPayoutCommand::class)
    ->monthlyOn(9, '10:30')
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

// Franchise commission runs on the 8th at 09:45 IST, after ADC at 09:30 and
// before the monthly payout at 10:30. It depends on nothing — the base is
// fulfilled order value, not BV or rank — so its position in the sequence is
// about payout ordering, not data dependency.
Schedule::command(FranchiseMonthlyRunCommand::class)
    ->monthlyOn(8, '09:45')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Purchase offers on the 2nd at 06:00 IST. Early in the month and ahead of
// every bonus engine, because the offers read the previous month's BV and
// grant nothing that any other engine depends on — running them first means a
// distributor sees what they earned before the payout cycle starts.
Schedule::command(PurchaseOffersMonthlyRunCommand::class)
    ->monthlyOn(2, '06:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();
