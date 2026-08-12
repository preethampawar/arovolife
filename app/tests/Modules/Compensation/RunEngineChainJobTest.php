<?php

declare(strict_types=1);

use App\Modules\Compensation\Jobs\RunEngineChainJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\EngineChainResolver;
use App\Modules\Compensation\Services\EngineRunService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class, WithConsoleEvents::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

    // Flags on: flag-off engines record `skipped` and are left out of chains,
    // so the execution-order tests need the engines live.
    foreach (EngineRegistry::all() as $definition) {
        if ($definition->featureFlagClass !== null) {
            Feature::activate($definition->featureFlagClass);
        }
    }
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function markChainCutoffsDone(string $month, string $upTo): void
{
    $day = Carbon::parse($month.'-01');
    $end = Carbon::parse($upTo);

    for (; $day->lte($end); $day->addDay()) {
        EngineRun::create([
            'engine_key' => 'gsb.daily-cutoff',
            'period_start' => $day->toDateString(),
            'status' => EngineRun::STATUS_SUCCEEDED,
            'trigger' => EngineRun::TRIGGER_CONSOLE,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }
}

function runChain(string $engineKey, string $period, string $chainId = 'chain-1'): void
{
    (new RunEngineChainJob($engineKey, $period, 77, $chainId))->handle(
        app(EngineChainResolver::class),
        app(EngineRunService::class),
    );
}

it('runs prerequisites before the target and labels each trigger', function (): void {
    markChainCutoffsDone('2026-05', '2026-05-31');

    runChain('rank.bonus', '2026-05');

    $chain = EngineRun::where('chain_id', 'chain-1')->orderBy('id')->get();

    expect($chain)->toHaveCount(2);

    expect($chain[0]->engine_key)->toBe('rank.check');
    expect($chain[0]->trigger)->toBe(EngineRun::TRIGGER_DEPENDENCY);
    expect($chain[0]->status)->toBe(EngineRun::STATUS_SUCCEEDED);
    expect($chain[0]->actor_id)->toBe(77);

    expect($chain[1]->engine_key)->toBe('rank.bonus');
    expect($chain[1]->trigger)->toBe(EngineRun::TRIGGER_MANUAL);
    expect($chain[1]->status)->toBe(EngineRun::STATUS_SUCCEEDED);
});

it('records the period it was asked for, not the command default', function (): void {
    markChainCutoffsDone('2026-05', '2026-05-31');

    runChain('rank.check', '2026-05');

    $run = EngineRun::where('chain_id', 'chain-1')->sole();

    expect($run->engine_key)->toBe('rank.check');
    // rank:check-qualifications defaults to the CURRENT month (June here), so
    // this proves the chain passes --month explicitly.
    expect($run->period_start->toDateString())->toBe('2026-05-01');
});

it('captures console output onto the run row', function (): void {
    runChain('adc.bonus', '2026-05');

    $run = EngineRun::where('chain_id', 'chain-1')->sole();

    expect($run->summary)->toHaveKey('exit_code');
    expect($run->summary['exit_code'])->toBe(0);
    expect($run->summary['output'])->not->toBe('');
});

it('keeps a flag-off manual run skipped, never succeeded', function (): void {
    Feature::deactivate(AreteDevelopmentCenterBonusFeature::class);

    runChain('adc.bonus', '2026-05');

    $run = EngineRun::where('chain_id', 'chain-1')->sole();

    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->summary['reason'])->toBe('feature_flag_off');
    // The captured output is merged in without promoting the status.
    expect($run->summary['output'])->toContain('OFF');
});

it('skips a prerequisite that is already computed', function (): void {
    markChainCutoffsDone('2026-05', '2026-05-31');
    RankQualification::create([
        'distributor_id' => 1, 'rank_number' => 1, 'month_start' => '2026-05-01',
        'left_genos_bv_paise' => 0, 'right_genos_bv_paise' => 0,
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => 'qualified',
    ]);

    runChain('rank.bonus', '2026-05');

    $chain = EngineRun::where('chain_id', 'chain-1')->get();

    expect($chain)->toHaveCount(1);
    expect($chain[0]->engine_key)->toBe('rank.bonus');
});

it('stands down when the engine lock is already held', function (): void {
    $lock = Cache::lock('compensation:engine:adc.bonus', 60);
    expect($lock->get())->toBeTrue();

    try {
        runChain('adc.bonus', '2026-05');
    } finally {
        $lock->release();
    }

    $run = EngineRun::where('chain_id', 'chain-1')->sole();

    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->summary['reason'])->toBe('already_running');
});

it('stands down when the scheduler has a live run in flight', function (): void {
    EngineRun::create([
        'engine_key' => 'adc.bonus',
        'period_start' => '2026-05-01',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now()->subMinute(),
    ]);

    runChain('adc.bonus', '2026-05');

    $run = EngineRun::where('chain_id', 'chain-1')->sole();

    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->summary['reason'])->toBe('already_running');
});

it('ignores a stale running row left behind by a dead process', function (): void {
    EngineRun::create([
        'engine_key' => 'adc.bonus',
        'period_start' => '2026-05-01',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now()->subHours(4),
    ]);

    runChain('adc.bonus', '2026-05');

    expect(EngineRun::where('chain_id', 'chain-1')->sole()->status)
        ->toBe(EngineRun::STATUS_SUCCEEDED);
});

it('aborts the chain and marks the remaining steps skipped when a step does not succeed', function (): void {
    markChainCutoffsDone('2026-05', '2026-05-31');

    // A live cron run of the prerequisite (EngineRunService cannot be mocked —
    // it is final — so the abort path is driven through its real stand-down
    // check): the manual chain must not run alongside it.
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => '2026-05-01',
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
    ]);

    (new RunEngineChainJob('rank.bonus', '2026-05', 77, 'chain-abort'))->handle(
        app(EngineChainResolver::class),
        app(EngineRunService::class),
    );

    $chain = EngineRun::where('chain_id', 'chain-abort')->orderBy('id')->get();

    expect($chain)->toHaveCount(2);

    // The prerequisite stood down because the scheduler already holds it...
    expect($chain[0]->engine_key)->toBe('rank.check');
    expect($chain[0]->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($chain[0]->summary['reason'])->toBe('already_running');

    // ...so the target never ran and the log says which step stopped it.
    expect($chain[1]->engine_key)->toBe('rank.bonus');
    expect($chain[1]->trigger)->toBe(EngineRun::TRIGGER_MANUAL);
    expect($chain[1]->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($chain[1]->summary['reason'])->toBe('upstream_skipped');
    expect($chain[1]->summary['failed_step'])->toBe('rank.check|2026-05');
});

it('does not retry, so a half-run chain is never replayed automatically', function (): void {
    $job = new RunEngineChainJob('adc.bonus', '2026-05', null, 'chain-x');

    expect($job->tries)->toBe(1);
    expect(EngineRegistry::has($job->engineKey))->toBeTrue();
});
