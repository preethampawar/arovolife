<?php

use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
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
