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
