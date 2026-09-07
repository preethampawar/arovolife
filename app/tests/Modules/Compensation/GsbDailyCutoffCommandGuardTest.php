<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

/**
 * A succeeded `repurchase:evaluate` run for the given period.
 *
 * `$startedAt` defaults to the scheduled 00:05 of the period itself — the run
 * the cron actually produces. Pass it explicitly to model a re-run or a manual
 * trigger that happened after the day it was dated for had ended.
 */
function seedEvaluateRun(string $period, ?string $startedAt = null): void
{
    $started = Carbon::parse($startedAt ?? $period.' 00:05:00');

    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => $period,
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => $started,
        'finished_at' => $started->copy()->addMinute(),
    ]);
}

it('refuses when no evaluate run has seen the whole cut-off day', function (): void {
    // The repurchase verdict this cut-off reads is written by exactly one
    // process. Running before it would credit days the client's rules forfeit —
    // permanently, with no later correction.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-24'); // the day BEFORE — not far enough

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'gsb.cutoff.refused_missing_evaluate'
            && $context['date'] === '2026-08-25');

    $exitCode = Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('repurchase:evaluate --date=2026-08-26')
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('records a FAILED engine run when refused', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1);

    $run = EngineRun::where('engine_key', 'gsb.daily-cutoff')->latest('id')->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe(EngineRun::STATUS_FAILED);
    expect($run->period_start->toDateString())->toBe('2026-08-25');
});

it('runs when a later evaluate run exists', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-26');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);
});

it('refuses an evaluate run dated the cut-off day that ran at 00:05 that morning', function (): void {
    // The scheduled run for D starts at 00:05 ON D, so a fulfilment purchase
    // made later that day is invisible to it. Accepting it would let the
    // cut-off forfeit a day the distributor actually fulfilled.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-25');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1)
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('runs when the evaluate run for the cut-off day started after that day ended', function (): void {
    // A re-run or manual trigger the next morning has seen every purchase made
    // on the cut-off day, so it is proof enough even dated for that day.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedEvaluateRun('2026-08-25', '2026-08-26 00:05:00');

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);
});

it('runs with --force despite the missing evaluate run', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25', '--force' => true]))->toBe(0);
});

it('skips the guard entirely when the repurchase engine is off', function (): void {
    // Flag off = no forfeits anywhere, so the cut-off has nothing to wait for.
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(0);
});

it('the replay fires repurchase:evaluate before the cut-off on every replayed day', function (): void {
    // EngineReplayService orders each day's engines by their cadence time, so
    // the guard is satisfiable during a replay only while evaluate's scheduled
    // time precedes the cut-off's. If that ever inverts, `recompute-all` starts
    // refusing every day it replays.
    $evaluate = EngineRegistry::get('repurchase.evaluate')->cadence;
    $cutoff = EngineRegistry::get('gsb.daily-cutoff')->cadence;

    expect($evaluate->isScheduled())->toBeTrue();
    expect($cutoff->isScheduled())->toBeTrue();
    expect($evaluate->time)->toBeLessThan($cutoff->time);
});
