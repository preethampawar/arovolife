<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * Schedule::command() only formats a class's signature into an artisan command
 * string — it never checks that the command is actually registered. Module
 * commands live outside app/Console/Commands, so Laravel's auto-discovery does
 * not find them and AppServiceProvider must list each one explicitly.
 *
 * When that list falls behind, nothing fails at boot, at deploy, or in
 * schedule:list — the miss only surfaces as "There are no commands defined in
 * the X namespace" on stderr of a cron run nobody is watching, and the bonus
 * for that month is silently never calculated. Four compensation commands
 * (gbb/rank/fortune/adc :monthly-run) shipped in exactly that state.
 */
it('registers every command referenced by the scheduler', function () {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(function ($event): ?string {
            // e.g. "'/usr/bin/php8.4' 'artisan' gbb:monthly-run --month='2026-07'"
            if (! preg_match("/'artisan'\s+([a-z0-9:_-]+)/i", $event->command ?? '', $m)) {
                return null; // exec() callbacks and closures are not artisan commands
            }

            return $m[1];
        })
        ->filter()
        ->unique()
        ->values();

    // Guards the guard: if the parse stops matching, the test must not silently
    // pass over an empty collection.
    expect($scheduled)->not->toBeEmpty();

    $registered = array_keys(Artisan::all());

    $missing = $scheduled->reject(fn (string $name) => in_array($name, $registered, true))->all();

    expect($missing)->toBe([], 'Scheduled but unregistered — these would fail at cron time: '.implode(', ', $missing));
});
