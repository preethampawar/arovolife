<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\EngineHealthService;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Support\NightlyRunAlert;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;
use Tests\Support\StubEngineStepCommand;

// The recorder — and therefore the resume logic, which reads the rows it writes
// — depends on the Symfony→Laravel console event bridge that Laravel suppresses
// under tests unless this trait opts back in.
uses(RefreshDatabase::class, WithConsoleEvents::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    // Both flags on. A step whose flag is off records as skipped rather than
    // succeeded, which would make the resume assertions meaningless.
    foreach ([GenosSalesBonusFeature::class, RepurchaseEngineFeature::class] as $feature) {
        Feature::for(null)->activate($feature);
    }

    StubEngineStepCommand::register(['repurchase.evaluate', 'gsb.daily-cutoff']);
});

/** A succeeded cut-off row is what proves a day was CUT OFF TO COMPLETION. */
function seedComputedCutoffs(string $from, string $to): void
{
    for ($day = Carbon::parse($from); $day->lessThanOrEqualTo(Carbon::parse($to)); $day->addDay()) {
        EngineRun::create([
            'engine_key' => 'gsb.daily-cutoff',
            'period_start' => $day->copy(),
            'status' => EngineRun::STATUS_SUCCEEDED,
            'trigger' => EngineRun::TRIGGER_CONSOLE,
            'started_at' => $day->copy()->addDay()->setTime(0, 6),
            'finished_at' => $day->copy()->addDay()->setTime(0, 9),
        ]);
    }
}

it('runs only the two nightly steps on an ordinary night', function (): void {
    // Wednesday 16 September 2026: not the 1st, not a Tuesday, not the 8th.
    Carbon::setTestNow('2026-09-16 00:05:00');

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);
});

it('runs only evaluate and cut-off on the 1st and on a Tuesday', function (): void {
    // Tuesday 1 December 2026 — the one night the old chain ran four engines:
    // the month close AND the weekly batch on top of the two daily steps. Both
    // belong to their own runs now, and a nightly run that still reached them
    // would fail this night over work it does not own.
    Carbon::setTestNow('2026-12-01 00:05:00');
    seedComputedCutoffs('2026-11-01', '2026-11-29');

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);
    expect(StubEngineStepCommand::$periods['gsb.daily-cutoff'])->toBe('2026-11-30');
    expect(EngineRun::whereIn('engine_key', [
        'compensation.monthly-close',
        'gsb.weekly-payout',
        'compensation.monthly-payout-close',
    ])->count())->toBe(0);
});

it('evaluates tonight and cuts off yesterday', function (): void {
    Carbon::setTestNow('2026-09-16 00:05:00');

    Artisan::call('compensation:nightly-run');

    // Tonight, never yesterday: the evaluation stamps the cycle state as at the
    // date it is given, and it has to have seen the whole of the day being cut
    // off.
    expect(StubEngineStepCommand::$periods['repurchase.evaluate'])->toBe('2026-09-16');
    expect(StubEngineStepCommand::$periods['gsb.daily-cutoff'])->toBe('2026-09-15');
});

it('resumes past a cut-off that already succeeded', function (): void {
    Carbon::setTestNow('2026-09-16 00:05:00');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-15'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-16 00:06:00'),
        'finished_at' => Carbon::parse('2026-09-16 00:09:00'),
    ]);

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe(['repurchase.evaluate']);
    expect(EngineRun::where('engine_key', 'gsb.daily-cutoff')->count())->toBe(1);
});

it('re-runs a step whose succeeded run started while its period was still in flight', function (): void {
    // A mid-day manual run or recompute leaves a succeeded row dated the same
    // day. Read as "already done" it would let the night skip the cut-off and
    // leave the day priced on partial BV for good.
    Carbon::setTestNow('2026-09-16 00:05:00');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-15'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-09-15 14:00:00'),
        'finished_at' => Carbon::parse('2026-09-15 14:02:00'),
    ]);

    Artisan::call('compensation:nightly-run');

    expect(StubEngineStepCommand::$calls)->toContain('gsb.daily-cutoff');
});

it('re-runs a cut-off whose latest attempt succeeded under --restart', function (): void {
    // The one path that deliberately recomputes a settled step: the night
    // rebuild wipes the day and passes --restart, and D13 keeps the pre-wipe
    // success counting until the re-run has finished.
    Carbon::setTestNow('2026-09-16 00:05:00');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-15'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-16 00:06:00'),
        'finished_at' => Carbon::parse('2026-09-16 00:09:00'),
    ]);

    Artisan::call('compensation:nightly-run', ['--restart' => true]);

    expect(StubEngineStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);
});

it('stops the run at the first failing step and says what did not run', function (): void {
    // Two cut-offs are owed, so a failure on the first leaves one step unrun —
    // which is the number the abort message has to be able to state.
    Carbon::setTestNow('2026-10-01 00:05:00');
    seedComputedCutoffs('2026-09-01', '2026-09-28');
    StubEngineStepCommand::$exitCodes['gsb.daily-cutoff'] = 1;

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);

    $run = EngineRun::where('engine_key', 'compensation.nightly-run')->sole();
    expect($run->status)->toBe(EngineRun::STATUS_FAILED);
    expect($run->error)->toContain('The remaining 1 step(s) did not run.');

    // Byte-identical to what the runbook and the staging history read.
    expect(AuditLog::where('action', 'compensation.nightly_run.aborted')->exists())->toBeTrue();
});

it('refuses a night that has not arrived', function (): void {
    Carbon::setTestNow('2026-09-16 00:05:00');

    $exitCode = Artisan::call('compensation:nightly-run', ['--date' => '2026-09-17']);

    expect($exitCode)->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe([]);
});

it('backfills every missed night, oldest first', function (): void {
    // The run last cut off the 12th. Three nights were lost; under the old fixed
    // --date=yesterday schedule they were lost for good, and a day never cut off
    // is a day nobody is credited for.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedComputedCutoffs('2026-09-12', '2026-09-12');

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
    ]);
    expect(
        EngineRun::where('engine_key', 'gsb.daily-cutoff')
            ->where('trigger', EngineRun::TRIGGER_CONSOLE)
            ->whereIn('status', [EngineRun::STATUS_SUCCEEDED])
            ->orderBy('id')
            ->pluck('period_start')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all()
    )->toBe(['2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15']);
});

it('heals a single missed night on the next run', function (): void {
    // The 16th never started — the 15th was still running at 00:05.
    Carbon::setTestNow('2026-09-17 00:05:00');
    seedComputedCutoffs('2026-09-14', '2026-09-14');

    Artisan::call('compensation:nightly-run');

    expect(StubEngineStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
    ]);
});

it('leaves a gap longer than the cap to a human', function (): void {
    // Nothing proven anywhere in the window: a brand-new environment or an
    // outage of months. Replaying either unattended at 00:05 is a decision
    // nobody made, so only last night runs and the rest reads as missing.
    Carbon::setTestNow('2026-09-16 00:05:00');

    Artisan::call('compensation:nightly-run');

    expect(StubEngineStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);
    expect(StubEngineStepCommand::$periods['gsb.daily-cutoff'])->toBe('2026-09-15');
});

it('records a night the scheduler skipped, and reports it in the health digest', function (): void {
    // An overlap skip starts no command: no run, no exit code, no engine_runs
    // row. The audit row is the only trace the night gets — and it names WHICH
    // run was skipped, because three of them fire on their own clocks now.
    Carbon::setTestNow('2026-09-16 00:05:00');

    NightlyRunAlert::skippedNight(Carbon::parse('2026-09-16'), 'still running', EngineStatusService::CHAIN_KEY);
    NightlyRunAlert::skippedNight(Carbon::parse('2026-09-16'), 'still running', EngineStatusService::CHAIN_KEY);

    $alert = AuditLog::where('action', NightlyRunAlert::ACTION_SKIPPED_NIGHT)->sole();
    expect($alert->details['orchestrator'])->toBe('compensation.nightly-run');

    $report = app(EngineHealthService::class)->report(Carbon::now());

    expect($report->chainAlerts)->toHaveCount(1);
    expect($report->chainAlerts[0]['date'])->toBe('16 Sep 2026');
    expect($report->isHealthy())->toBeFalse();
});

it('records a gap it will not heal, because nothing else can see one', function (): void {
    // Over the cap: last night runs, the rest does not, and the run exits 0.
    // EngineHealthService::missing() judges each engine on its most recent fire
    // — which just succeeded — so this is recorded here or nowhere.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedComputedCutoffs('2026-06-01', '2026-06-01');

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);

    $alert = AuditLog::where('action', NightlyRunAlert::ACTION_BACKFILL_GAP)->sole();
    expect($alert->details['date'])->toBe('2026-09-16');
    // No result rows were ever written here, so there is no older proof to name
    // — the alert says so rather than inventing one.
    expect($alert->details['last_proven_cutoff'])->toBeNull();
    expect(app(EngineHealthService::class)->report(Carbon::now())->chainAlerts)->toHaveCount(1);
});

it('does not count a cut-off that only left result rows behind', function (): void {
    // The cut-off commits per distributor, so a run that died half way through
    // leaves rows for a day nobody finished. Counting that day as done would
    // close the month against it, and the monthly pools freeze what they price.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedComputedCutoffs('2026-09-12', '2026-09-12');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-13'),
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-14 00:06:00'),
        'finished_at' => Carbon::parse('2026-09-14 00:07:00'),
    ]);

    Artisan::call('compensation:nightly-run');

    // The 13th is still owed, so it is backfilled along with the 14th and 15th.
    expect(StubEngineStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
    ]);
});

it('does not count a cut-off run inside its own day', function (): void {
    // A manual run at noon cannot have seen the evening's sales.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedComputedCutoffs('2026-09-12', '2026-09-12');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-13'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-09-13 12:00:00'),
        'finished_at' => Carbon::parse('2026-09-13 12:05:00'),
    ]);

    Artisan::call('compensation:nightly-run');

    expect(StubEngineStepCommand::$calls)->toHaveCount(4);
});

it('refuses to run while a projection is standing', function (): void {
    // The scheduler entry is filtered, but this command is also typed by hand —
    // it is what the abort message tells an operator to run.
    Carbon::setTestNow('2026-09-16 00:05:00');

    // The state a standing projection actually leaves behind — the audit row
    // RecomputeState reads uncached, on purpose, so a Redis eviction can never
    // answer "not projected" on this path.
    AuditLog::create([
        'actor_id' => null,
        'action' => 'compensation.recompute_all',
        'subject_type' => 'platform',
        'subject_id' => 0,
        'details' => ['horizon' => 'projection', 'simulated_through' => '2026-09-30 23:59:59'],
    ]);

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe([]);
    expect(EngineRun::where('engine_key', 'compensation.nightly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_SKIPPED);
});
