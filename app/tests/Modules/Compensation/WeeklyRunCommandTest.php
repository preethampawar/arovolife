<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Support\NightlyRunAlert;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;
use Tests\Support\StubEngineStepCommand;

uses(RefreshDatabase::class, WithConsoleEvents::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);

    StubEngineStepCommand::register(['gsb.weekly-payout']);
});

/** A weekly batch is the proof that a Tuesday was paid. */
function seedWeeklyRunBatch(string $tuesday): void
{
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => $tuesday,
        'earnings_through' => Carbon::parse($tuesday)->subDays(7)->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse($tuesday)->setTime(3, 5),
    ]);
}

/** Tonight's nightly run, in whatever state the ordering guard is being shown. */
function seedNightlyRun(string $night, string $status, string $startedAt, ?string $error = null): void
{
    EngineRun::create([
        'engine_key' => 'compensation.nightly-run',
        'period_start' => $night,
        'status' => $status,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'error' => $error,
        'started_at' => $startedAt,
        'finished_at' => $status === EngineRun::STATUS_RUNNING ? null : $startedAt,
    ]);
}

it('builds tonight\'s Tuesday batch once the nightly run is green', function (): void {
    // Tuesday 22 September 2026.
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_SUCCEEDED, '2026-09-22 00:05:00');

    $exitCode = Artisan::call('compensation:weekly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe(['gsb.weekly-payout']);
    expect(StubEngineStepCommand::$periods['gsb.weekly-payout'])->toBe('2026-09-22');
});

it('records its own run row', function (): void {
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_SUCCEEDED, '2026-09-22 00:05:00');

    Artisan::call('compensation:weekly-run');

    $run = EngineRun::where('engine_key', 'compensation.weekly-run')->sole();
    expect($run->status)->toBe(EngineRun::STATUS_SUCCEEDED);
    expect($run->period_start->toDateString())->toBe('2026-09-22');
});

it('rebuilds a missed Tuesday the next night, still dated that Tuesday', function (): void {
    // Wednesday. Tuesday's run never built its batch; waiting for the following
    // Tuesday would cost distributors a week for an outage that had nothing to
    // do with them.
    Carbon::setTestNow('2026-09-23 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedNightlyRun('2026-09-23', EngineRun::STATUS_SUCCEEDED, '2026-09-23 00:05:00');

    Artisan::call('compensation:weekly-run');

    expect(StubEngineStepCommand::$periods['gsb.weekly-payout'])->toBe('2026-09-22');
});

it('leaves a Tuesday alone once its batch exists', function (): void {
    // A batch finance has already approved is returned unchanged by the runner,
    // which then reports FAILURE — re-invoking it would abort the run over a
    // batch that is not merely fine but signed off.
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedWeeklyRunBatch('2026-09-22');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_SUCCEEDED, '2026-09-22 00:05:00');

    $exitCode = Artisan::call('compensation:weekly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([]);
    expect(EngineRun::where('engine_key', 'compensation.weekly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_SKIPPED);
});

it('records a skipped run when nothing is owed tonight', function (): void {
    // Wednesday with last Tuesday paid. The scheduler would not have started it
    // at all; typed by hand it says so rather than inventing a batch.
    Carbon::setTestNow('2026-09-23 03:00:00');
    seedWeeklyRunBatch('2026-09-22');
    seedNightlyRun('2026-09-23', EngineRun::STATUS_SUCCEEDED, '2026-09-23 00:05:00');

    $exitCode = Artisan::call('compensation:weekly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([]);

    $run = EngineRun::where('engine_key', 'compensation.weekly-run')->sole();
    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->error)->toBe('No Tuesday batch is owed tonight.');
});

it('defers the batch when tonight\'s nightly run failed', function (): void {
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_FAILED, '2026-09-22 00:05:00', "GSB Daily Cut-off exited 1.\nmore");

    $exitCode = Artisan::call('compensation:weekly-run');

    // A deferral, never a failed night (D3): it costs a day and no figure.
    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([]);

    $run = EngineRun::where('engine_key', 'compensation.weekly-run')->sole();
    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->error)->toContain('its last attempt is failed: GSB Daily Cut-off exited 1.');

    $alert = AuditLog::where('action', NightlyRunAlert::ACTION_WEEKLY_DEFERRED)->sole();
    expect($alert->details['tuesdays'])->toBe(['2026-09-22']);
});

it('defers the batch when tonight\'s nightly run has not run at all', function (): void {
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');

    $exitCode = Artisan::call('compensation:weekly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([]);
    expect(EngineRun::where('engine_key', 'compensation.weekly-run')->sole()->error)
        ->toContain('it has not run at all tonight');
});

it('defers the batch when tonight\'s nightly row is a replay that started yesterday', function (): void {
    // Dated tonight but started before the night began: a hand-typed replay of
    // a past night, not the process that has just finished writing tonight's
    // cut-off.
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_SUCCEEDED, '2026-09-21 14:00:00');

    $exitCode = Artisan::call('compensation:weekly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([]);
    expect(AuditLog::where('action', NightlyRunAlert::ACTION_WEEKLY_DEFERRED)->count())->toBe(1);
});

it('builds the batch anyway under --force', function (): void {
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_FAILED, '2026-09-22 00:05:00', 'boom');

    Artisan::call('compensation:weekly-run', ['--force' => true]);

    expect(StubEngineStepCommand::$calls)->toBe(['gsb.weekly-payout']);
    expect(AuditLog::where('action', NightlyRunAlert::ACTION_WEEKLY_DEFERRED)->count())->toBe(0);
});

it('aborts on a failing step and audits it under its own action name', function (): void {
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_SUCCEEDED, '2026-09-22 00:05:00');
    StubEngineStepCommand::$exitCodes['gsb.weekly-payout'] = 1;

    $exitCode = Artisan::call('compensation:weekly-run');

    expect($exitCode)->toBe(1);
    expect(EngineRun::where('engine_key', 'compensation.weekly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_FAILED);

    $audit = AuditLog::where('action', 'compensation.weekly_run.aborted')->sole();
    expect($audit->details['stage'])->toBe('gsb.weekly-payout');
});

it('refuses a night that has not arrived', function (): void {
    Carbon::setTestNow('2026-09-22 03:00:00');

    $exitCode = Artisan::call('compensation:weekly-run', ['--date' => '2026-09-23']);

    expect($exitCode)->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe([]);
});

it('refuses to run while a projection is standing', function (): void {
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedWeeklyRunBatch('2026-09-15');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_SUCCEEDED, '2026-09-22 00:05:00');

    AuditLog::create([
        'actor_id' => null,
        'action' => 'compensation.recompute_all',
        'subject_type' => 'platform',
        'subject_id' => 0,
        'details' => ['horizon' => 'projection', 'simulated_through' => '2026-09-30 23:59:59'],
    ]);

    $exitCode = Artisan::call('compensation:weekly-run');

    expect($exitCode)->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe([]);
    expect(EngineRun::where('engine_key', 'compensation.weekly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_SKIPPED);
});

it('succeeds on a flag-off Tuesday although its only step is skipped', function (): void {
    // A13. With GSB off the sweep records `skipped` and builds nothing — and if
    // the weekly run recorded itself the same way, RunPrerequisites would read
    // it as "not succeeded" and every monthly close on a Tuesday would be
    // deferred for as long as the flag stayed off.
    Feature::for(null)->deactivate(GenosSalesBonusFeature::class);

    // The step is still invoked: that `skipped` row is the only record the
    // engine was off on a night it was due, and RecordEngineRun writes it from
    // the registry's flag, stub or no stub.
    Carbon::setTestNow('2026-09-22 03:00:00');
    seedNightlyRun('2026-09-22', EngineRun::STATUS_SUCCEEDED, '2026-09-22 00:05:00');

    $exitCode = Artisan::call('compensation:weekly-run');

    expect($exitCode)->toBe(0);
    expect(EngineRun::where('engine_key', 'compensation.weekly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_SUCCEEDED);
    expect(EngineRun::where('engine_key', 'gsb.weekly-payout')->sole()->status)
        ->toBe(EngineRun::STATUS_SKIPPED);
});
