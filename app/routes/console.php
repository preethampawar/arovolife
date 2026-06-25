<?php

use App\Modules\Compensation\Console\Commands\GbbMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily GSB cut-off at 23:59 IST. withoutOverlapping prevents concurrent runs.
Schedule::command(GsbDailyCutoffCommand::class)
    ->dailyAt('23:59')
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
