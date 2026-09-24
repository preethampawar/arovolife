<?php

declare(strict_types=1);

use App\Console\Commands\AppStatusCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reports database, cache and migrations as healthy', function () {
    $this->artisan('app:status')
        ->expectsOutputToContain('Database')
        ->expectsOutputToContain('read/write OK')
        ->expectsOutputToContain('none pending');
});

it('reports a recent scheduler heartbeat as OK', function () {
    Cache::put(AppStatusCommand::SCHEDULER_HEARTBEAT_KEY, time() - 30, 600);

    $this->artisan('app:status')->expectsOutputToContain('last tick 30s ago');
});

it('fails when the scheduler heartbeat is stale', function () {
    Cache::put(AppStatusCommand::SCHEDULER_HEARTBEAT_KEY, time() - 600, 900);

    $this->artisan('app:status')
        ->expectsOutputToContain('is the schedule:run cron job installed?')
        ->assertFailed();
});

it('only warns when no heartbeat has been recorded yet', function () {
    $this->artisan('app:status')->expectsOutputToContain('no heartbeat recorded yet');
});

it('schedules the heartbeat every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => $e->description === 'ops:scheduler-heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

it('flags a queue job that has waited more than fifteen minutes', function () {
    DB::table('jobs')->insert([
        'queue' => 'compensation',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => time() - 1200,
        'created_at' => time() - 1200,
    ]);

    $this->artisan('app:status')->expectsOutputToContain('compensation=1 (oldest 20 min)');
});

it('treats an idle queue with no worker as healthy', function () {
    $this->artisan('app:status')->expectsOutputToContain('idle — no jobs waiting');
});

it('fails a queue whose job has waited past two minutes with no worker', function () {
    DB::table('jobs')->insert([
        'queue' => 'otp',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => time() - 300,
        'created_at' => time() - 300,
    ]);

    $this->artisan('app:status')
        ->expectsOutputToContain('job waiting 300s with no worker')
        ->assertFailed();
});
