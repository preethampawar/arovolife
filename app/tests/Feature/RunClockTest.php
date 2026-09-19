<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Support\EngineCadence;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\RunClock;
use Illuminate\Support\Carbon;

/**
 * The admin surfaces print these instants as fact, next to the word IST, and an
 * operator decides whether to retry tonight or give up on the strength of them.
 * A next-run time that is off by a day is worse than the bare "00:05 IST" it
 * replaced, so the boundaries are pinned here.
 */
afterEach(function (): void {
    Carbon::setTestNow(null);
});

it('gives the next fire strictly after the instant asked about', function (): void {
    $daily = EngineCadence::daily('00:05');

    // Mid-morning: tonight's fire has been, the next is tomorrow's.
    expect($daily->nextRunAfter(Carbon::parse('2026-09-19 09:00', 'Asia/Kolkata'))->toDateTimeString())
        ->toBe('2026-09-20 00:05:00');

    // Three minutes before it fires, the answer is still today.
    expect($daily->nextRunAfter(Carbon::parse('2026-09-19 00:02', 'Asia/Kolkata'))->toDateTimeString())
        ->toBe('2026-09-19 00:05:00');

    // At the fire itself: STRICTLY after, or a page refreshed at 00:05 would
    // promise a run that is already under way.
    expect($daily->nextRunAfter(Carbon::parse('2026-09-19 00:05', 'Asia/Kolkata'))->toDateTimeString())
        ->toBe('2026-09-20 00:05:00');
});

it('walks the calendar for the weekly and monthly cadences', function (): void {
    // Tuesday 03:00, asked on the Wednesday it just missed.
    expect(EngineCadence::weeklyOn(Carbon::TUESDAY, '03:00')
        ->nextRunAfter(Carbon::parse('2026-09-16 09:00', 'Asia/Kolkata'))->toDateTimeString())
        ->toBe('2026-09-22 03:00:00');

    // The 8th at 04:00, asked from the 9th — next month, not next week.
    expect(EngineCadence::monthlyOn(8, '04:00')
        ->nextRunAfter(Carbon::parse('2026-09-09 04:00', 'Asia/Kolkata'))->toDateTimeString())
        ->toBe('2026-10-08 04:00:00');

    // A manual-only engine promises nothing rather than guessing.
    expect(EngineCadence::unscheduled()->nextRunAfter(Carbon::now()))->toBeNull();
});

it('reads the nightly run back as two instants an operator can act on', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-19 09:00', 'Asia/Kolkata'));

    $definition = EngineRegistry::get(EngineStatusService::CHAIN_KEY);

    $clock = new RunClock(
        $definition,
        EngineRun::make([
            'engine_key' => EngineStatusService::CHAIN_KEY,
            'period_start' => '2026-09-19',
            'status' => EngineRun::STATUS_SUCCEEDED,
            'started_at' => Carbon::parse('2026-09-19 00:05', 'Asia/Kolkata'),
        ]),
        $definition->cadence->nextRunAfter(Carbon::now('Asia/Kolkata')),
    );

    expect($clock->name())->toBe('Nightly Run')
        ->and($clock->lastRunLabel())->toBe('19 Sep 2026, 00:05 IST')
        ->and($clock->lastRunStatus())->toBe('succeeded')
        ->and($clock->lastRunPeriodLabel())->toBe('19 Sep 2026')
        ->and($clock->nextRunLabel())->toBe('20 Sep 2026, 00:05 IST')
        // A countdown, not a comparison of two timestamps.
        ->and($clock->nextRunRelative())->toBe('15 hours from now')
        ->and($clock->sentence())->toBe(
            'The Nightly Run last ran on 19 Sep 2026, 00:05 IST (succeeded); the next one is 20 Sep 2026, 00:05 IST.',
        );

    // The day in flight is worked by the run after it ends, which is the next
    // morning's — not tonight's, which has already been.
    expect($clock->firesAfterLabel(Carbon::parse('2026-09-19', 'Asia/Kolkata')->endOfDay()))
        ->toBe('20 Sep 2026, 00:05 IST');
});

it('promises nothing while the scheduler is held, and nothing before the first run', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-19 09:00', 'Asia/Kolkata'));

    $definition = EngineRegistry::get(EngineStatusService::CHAIN_KEY);
    $next = $definition->cadence->nextRunAfter(Carbon::now('Asia/Kolkata'));

    // A test environment replaying: the cadence still says 00:05, but the
    // scheduler will not honour it, so the page must not print a time.
    $held = new RunClock($definition, null, $next, schedulerPaused: true);

    expect($held->nextRunLabel())->toBe('held while this environment is replaying')
        ->and($held->nextRunRelative())->toBeNull()
        ->and($held->lastRunLabel())->toBe('not yet recorded')
        ->and($held->lastRunStatus())->toBe('never run');
});

it('gives an orchestrated engine its own day and the clock of the run that fires it', function (): void {
    // Saturday 19 Sep 2026, 09:00.
    Carbon::setTestNow(Carbon::parse('2026-09-19 09:00', 'Asia/Kolkata'));

    $now = Carbon::now('Asia/Kolkata');
    $next = fn (string $key): string => EngineRegistry::get($key)->nextRunAfter($now)->toDateTimeString();

    // The cut-off declares 00:10, but that is its POSITION in the nightly run,
    // not a promise about the clock — it fires when the step before it exits 0.
    expect($next('gsb.daily-cutoff'))->toBe('2026-09-20 00:05:00');

    // The half that the Engine Runs page caught. All three runs are NIGHTLY and
    // decide inside themselves whether their engine is owed, so borrowing the
    // run's cadence whole had the weekly payout landing on a Sunday and every
    // monthly bonus claiming to run tomorrow morning.
    expect($next('gsb.weekly-payout'))->toBe('2026-09-22 03:00:00')   // Tuesday, at the weekly run's clock
        ->and($next('gbb.monthly'))->toBe('2026-10-01 04:00:00')      // the 1st, at the monthly run's clock
        ->and($next('payout.monthly'))->toBe('2026-10-08 04:00:00');  // the 8th, ditto
});
