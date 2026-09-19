<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\EngineStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function seedEngineStatusRun(string $key, string $period, string $status): EngineRun
{
    return EngineRun::create([
        'engine_key' => $key,
        'period_start' => $period,
        'status' => $status,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse($period.' 00:05:00'),
        'finished_at' => Carbon::parse($period.' 00:06:00'),
    ]);
}

it('hasSucceededRunOnOrAfter accepts a run on the period itself', function (): void {
    seedEngineStatusRun('repurchase.evaluate', '2026-08-25', EngineRun::STATUS_SUCCEEDED);

    expect(app(EngineStatusService::class)
        ->hasSucceededRunOnOrAfter('repurchase.evaluate', Carbon::parse('2026-08-25')))->toBeTrue();
});

it('hasSucceededRunOnOrAfter accepts a later run, because a later evaluation is a superset', function (): void {
    // `repurchase:evaluate --date=D` stamps every cycle through D, so a run for
    // D + 1 has already answered every question day D can ask.
    seedEngineStatusRun('repurchase.evaluate', '2026-08-26', EngineRun::STATUS_SUCCEEDED);

    expect(app(EngineStatusService::class)
        ->hasSucceededRunOnOrAfter('repurchase.evaluate', Carbon::parse('2026-08-25')))->toBeTrue();
});

it('hasSucceededRunOnOrAfter rejects an earlier run, a non-succeeded run and another engine', function (): void {
    $status = app(EngineStatusService::class);
    $asOf = Carbon::parse('2026-08-25');

    seedEngineStatusRun('repurchase.evaluate', '2026-08-24', EngineRun::STATUS_SUCCEEDED);
    seedEngineStatusRun('repurchase.evaluate', '2026-08-25', EngineRun::STATUS_FAILED);
    seedEngineStatusRun('repurchase.evaluate', '2026-08-26', EngineRun::STATUS_RUNNING);
    seedEngineStatusRun('repurchase.evaluate', '2026-08-27', EngineRun::STATUS_SKIPPED);
    seedEngineStatusRun('gsb.daily-cutoff', '2026-08-28', EngineRun::STATUS_SUCCEEDED);

    expect($status->hasSucceededRunOnOrAfter('repurchase.evaluate', $asOf))->toBeFalse();
});

it('hasSucceededRunAfterDay rejects the run scheduled at 00:05 on the day itself', function (): void {
    // The 00:05 run on D cannot have seen a fulfilment purchase made later on
    // D, so it is not proof the cut-off for D may rely on.
    seedEngineStatusRun('repurchase.evaluate', '2026-08-25', EngineRun::STATUS_SUCCEEDED);

    expect(app(EngineStatusService::class)
        ->hasSucceededRunAfterDay('repurchase.evaluate', Carbon::parse('2026-08-25')))->toBeFalse();
});

it('hasSucceededRunAfterDay accepts a run for the day that started after the day ended', function (): void {
    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => '2026-08-25',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-08-26 00:05:00'),
        'finished_at' => Carbon::parse('2026-08-26 00:06:00'),
    ]);

    expect(app(EngineStatusService::class)
        ->hasSucceededRunAfterDay('repurchase.evaluate', Carbon::parse('2026-08-25')))->toBeTrue();
});

it('hasSucceededRunAfterDay accepts a run for a later day whatever hour it started', function (): void {
    seedEngineStatusRun('repurchase.evaluate', '2026-08-26', EngineRun::STATUS_SUCCEEDED);

    expect(app(EngineStatusService::class)
        ->hasSucceededRunAfterDay('repurchase.evaluate', Carbon::parse('2026-08-25')))->toBeTrue();
});

it('hasSucceededRunAfterDay rejects earlier, unsucceeded and other-engine runs', function (): void {
    $status = app(EngineStatusService::class);
    $asOf = Carbon::parse('2026-08-25');

    seedEngineStatusRun('repurchase.evaluate', '2026-08-24', EngineRun::STATUS_SUCCEEDED);
    seedEngineStatusRun('repurchase.evaluate', '2026-08-26', EngineRun::STATUS_FAILED);
    seedEngineStatusRun('gsb.daily-cutoff', '2026-08-28', EngineRun::STATUS_SUCCEEDED);

    expect($status->hasSucceededRunAfterDay('repurchase.evaluate', $asOf))->toBeFalse();
});

it('hasSucceededRunAfterDay is false when the run log is empty', function (): void {
    expect(app(EngineStatusService::class)
        ->hasSucceededRunAfterDay('repurchase.evaluate', Carbon::parse('2026-08-25')))->toBeFalse();
});

it('hasSucceededRunOnOrAfter is false when the run log is empty', function (): void {
    expect(app(EngineStatusService::class)
        ->hasSucceededRunOnOrAfter('repurchase.evaluate', Carbon::parse('2026-08-25')))->toBeFalse();
});

it('hasSucceededRunOnOrAfter compares dates, not timestamps', function (): void {
    // A run recorded late in the evening of the cut-off day still counts: the
    // guard asks which DAY was evaluated, never at what hour.
    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => '2026-08-25',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-08-25 23:50:00'),
        'finished_at' => Carbon::parse('2026-08-25 23:51:00'),
    ]);

    expect(app(EngineStatusService::class)
        ->hasSucceededRunOnOrAfter('repurchase.evaluate', Carbon::parse('2026-08-25 09:00:00')))->toBeTrue();
});

it('hasSucceededRun rejects a month-typed run that started while the month was still in flight', function (): void {
    // F05: the 05 Sep recompute stamped every September step SUCCEEDED for
    // 2026-09-01 on a month that was 12% short. The monthly close read those
    // rows as "already done" and never repriced the month.
    EngineRun::create([
        'engine_key' => 'rank.bonus',
        'period_start' => '2026-09-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-05 14:03:29'),
        'finished_at' => Carbon::parse('2026-09-05 14:03:31'),
    ]);

    expect(app(EngineStatusService::class)
        ->hasSucceededRun('rank.bonus', Carbon::parse('2026-09-01')))->toBeFalse();
});

it('hasSucceededRun accepts a month-typed run that started once the month had closed', function (): void {
    EngineRun::create([
        'engine_key' => 'rank.bonus',
        'period_start' => '2026-09-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-10-01 00:30:00'),
        'finished_at' => Carbon::parse('2026-10-01 00:31:00'),
    ]);

    expect(app(EngineStatusService::class)
        ->hasSucceededRun('rank.bonus', Carbon::parse('2026-09-01')))->toBeTrue();
});

it('hasSucceededRun rejects a date-typed run that started before the day ended', function (): void {
    seedEngineStatusRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_SUCCEEDED);

    $status = app(EngineStatusService::class);

    expect($status->hasSucceededRun('gsb.daily-cutoff', Carbon::parse('2026-08-25')))->toBeFalse();

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => '2026-08-25',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-08-26 00:10:00'),
        'finished_at' => Carbon::parse('2026-08-26 00:12:00'),
    ]);

    expect($status->hasSucceededRun('gsb.daily-cutoff', Carbon::parse('2026-08-25')))->toBeTrue();
});

/**
 * D13 — the latest FINISHED attempt decides whether a period is computed.
 *
 * A run row every one of these tests could have written before the rule
 * changed; what changed is which of them is read.
 */
function seedFinishedRun(string $key, string $period, string $status, string $startedAt): EngineRun
{
    return EngineRun::create([
        'engine_key' => $key,
        'period_start' => $period,
        'status' => $status,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse($startedAt),
        'finished_at' => Carbon::parse($startedAt)->addMinutes(2),
    ]);
}

it('hasSucceededRun is un-proven by a later failed attempt', function (): void {
    // A rebuild wipes the period's rows before re-running it. If the re-run
    // fails, an earlier success proves nothing about rows that no longer exist
    // — and the resume logic would skip a step whose results are gone.
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_SUCCEEDED, '2026-08-26 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_FAILED, '2026-08-27 09:00:00');

    expect(app(EngineStatusService::class)
        ->hasSucceededRun('gsb.daily-cutoff', Carbon::parse('2026-08-25')))->toBeFalse();
});

it('hasSucceededRun is proven again by a success after a failure', function (): void {
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_FAILED, '2026-08-26 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_SUCCEEDED, '2026-08-27 09:00:00');

    expect(app(EngineStatusService::class)
        ->hasSucceededRun('gsb.daily-cutoff', Carbon::parse('2026-08-25')))->toBeTrue();
});

it('hasSucceededRun ignores skipped and running rows written after a success', function (): void {
    // A preflight refusal decided nothing about the period, and a run in flight
    // has not finished deciding. Neither may un-prove a completed period.
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_SUCCEEDED, '2026-08-26 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_SKIPPED, '2026-08-27 09:00:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_RUNNING, '2026-08-28 09:00:00');

    expect(app(EngineStatusService::class)
        ->hasSucceededRun('gsb.daily-cutoff', Carbon::parse('2026-08-25')))->toBeTrue();
});

it('completedCutoffDatesBetween drops a day whose latest attempt failed', function (): void {
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_SUCCEEDED, '2026-08-26 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-26', EngineRun::STATUS_SUCCEEDED, '2026-08-27 00:10:00');
    // The 26th is rebuilt and the re-run breaks: the day is not cut off any more.
    seedFinishedRun('gsb.daily-cutoff', '2026-08-26', EngineRun::STATUS_FAILED, '2026-08-28 11:00:00');

    expect(app(EngineStatusService::class)
        ->completedCutoffDatesBetween(Carbon::parse('2026-08-25'), Carbon::parse('2026-08-26')))
        ->toBe(['2026-08-25']);
});

it('completedCutoffDatesBetween keeps a day whose failed re-run is behind the frontier', function (): void {
    // A6. The 25th cannot be rebuilt — the 26th has already advanced the rolling
    // carry-forward store (R-91) — so a failed re-run of it destroyed nothing:
    // its rows are intact and the earlier success still describes them. Dropping
    // it would let anyone who can trigger an engine block the month's crediting
    // for ever with a re-run that can never succeed.
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_SUCCEEDED, '2026-08-26 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-26', EngineRun::STATUS_SUCCEEDED, '2026-08-27 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_FAILED, '2026-08-28 11:00:00');

    expect(app(EngineStatusService::class)
        ->completedCutoffDatesBetween(Carbon::parse('2026-08-25'), Carbon::parse('2026-08-26')))
        ->toBe(['2026-08-25', '2026-08-26']);
});

it('completedCutoffDatesBetween keeps the last day of a range whose proven successor is outside it', function (): void {
    // The frontier is the carry-forward store's, not the caller's window. The
    // 31st cannot be rebuilt — 1 September has already advanced the store — so
    // its rows are intact and August is not short. With the frontier bounded by
    // the range, a failed re-run of the 31st would read short for ever: the
    // re-run can only ever record `skipped` (A1), which never displaces the
    // `failed` row, leaving --force as the only way to close the month.
    seedFinishedRun('gsb.daily-cutoff', '2026-08-31', EngineRun::STATUS_SUCCEEDED, '2026-09-01 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-09-02 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-31', EngineRun::STATUS_FAILED, '2026-09-03 11:00:00');

    expect(app(EngineStatusService::class)
        ->completedCutoffDatesBetween(Carbon::parse('2026-08-31'), Carbon::parse('2026-08-31')))
        ->toBe(['2026-08-31']);
});

it('completedCutoffDatesBetween drops a day whose latest attempt failed and nothing later is proven', function (): void {
    // D13's real purpose: the newest night is the one a rebuild can wipe, so a
    // wiped-then-failed night must read "not computed".
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_SUCCEEDED, '2026-08-26 00:10:00');
    seedFinishedRun('gsb.daily-cutoff', '2026-08-25', EngineRun::STATUS_FAILED, '2026-08-26 11:00:00');

    expect(app(EngineStatusService::class)
        ->completedCutoffDatesBetween(Carbon::parse('2026-08-25'), Carbon::parse('2026-08-25')))
        ->toBe([]);
});

it('hasSucceededRunTonight accepts only a succeeded row dated tonight that started tonight', function (): void {
    $status = app(EngineStatusService::class);
    $night = Carbon::parse('2026-09-16');

    expect($status->hasSucceededRunTonight('compensation.nightly-run', $night))->toBeFalse();

    // Dated tonight but started yesterday: a replay of a past night.
    $replay = seedFinishedRun('compensation.nightly-run', '2026-09-16', EngineRun::STATUS_SUCCEEDED, '2026-09-15 22:00:00');
    expect($status->hasSucceededRunTonight('compensation.nightly-run', $night))->toBeFalse();

    $replay->delete();
    seedFinishedRun('compensation.nightly-run', '2026-09-16', EngineRun::STATUS_SUCCEEDED, '2026-09-16 00:05:00');
    expect($status->hasSucceededRunTonight('compensation.nightly-run', $night))->toBeTrue();

    // And a failed re-run tonight un-proves it again.
    seedFinishedRun('compensation.nightly-run', '2026-09-16', EngineRun::STATUS_FAILED, '2026-09-16 02:00:00');
    expect($status->hasSucceededRunTonight('compensation.nightly-run', $night))->toBeFalse();
});

it('failedRootRun reports the latest failed attempt of each root orchestrator', function (): void {
    $status = app(EngineStatusService::class);

    foreach (['compensation.nightly-run', 'compensation.weekly-run', 'compensation.monthly-run'] as $key) {
        expect($status->failedRootRun($key))->toBeNull();

        seedFinishedRun($key, '2026-09-16', EngineRun::STATUS_FAILED, '2026-09-16 00:05:00');
        expect($status->failedRootRun($key)?->engine_key)->toBe($key);

        // A later success closes it — whatever period it was for.
        seedFinishedRun($key, '2026-09-17', EngineRun::STATUS_SUCCEEDED, '2026-09-17 00:05:00');
        expect($status->failedRootRun($key))->toBeNull();
    }

    // failedChainRun() is the nightly run's own case of the same question.
    seedFinishedRun('compensation.nightly-run', '2026-09-18', EngineRun::STATUS_FAILED, '2026-09-18 00:05:00');
    expect($status->failedChainRun()?->period_start->toDateString())->toBe('2026-09-18');
});
