<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Identity\Models\Distributor;
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

it('names the distributors the evaluation could not judge when it refuses', function (): void {
    // F23: `repurchase:evaluate` isolates a throwing distributor and carries
    // on, so the run finishes — as `failed`, with a `failed_partial` summary.
    // The gate is unmoved (a non-zero failure count means somebody's verdict is
    // stale, and the day's pools are frozen once), but the operator is now told
    // which ADNs to fix instead of being sent to the log.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => '2026-08-26',
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-08-26 00:05:00'),
        'finished_at' => Carbon::parse('2026-08-26 00:06:00'),
        'summary' => [
            'outcome' => 'failed_partial',
            'evaluated' => 6,
            'failed' => 1,
            'failed_adns' => ['ADN12345'],
            'failure_classes' => ['RuntimeException'],
        ],
    ]);

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'gsb.cutoff.refused_missing_evaluate'
            && $context['evaluate_failures'] === 1
            && $context['evaluate_failed_adns'] === ['ADN12345']);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1)
        ->and(Artisan::output())->toContain('ADN12345')
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('says nothing about failures when the evaluation simply never ran', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    expect(Artisan::call('gsb:daily-cutoff', ['--date' => '2026-08-25']))->toBe(1)
        ->and(Artisan::output())->not->toContain('threw and still carry');
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

it('refuses a cut-off for today, because the day has not ended', function (): void {
    // F31: the CLI default is today. The cut-off freezes the day's GSB and MSB
    // pools on whatever BV exists at that instant and the scheduled run after
    // midnight keeps that pricing — the 24 Aug 2026 staging incident.
    $exitCode = Artisan::call('gsb:daily-cutoff');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('that day has not ended')
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('refuses a cut-off dated in the future', function (): void {
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => Carbon::tomorrow()->toDateString()]))->toBe(1)
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('--force does not lift the closed-day guard, because it answers a different question', function (): void {
    // --force says "run without a repurchase verdict for the day". It is not a
    // statement that a partial day may be frozen, and reading it as one is how
    // the only barrier in front of a same-day cut-off came to be liftable.
    expect(Artisan::call('gsb:daily-cutoff', ['--force' => true]))->toBe(1)
        ->and(GsbCutoffResult::count())->toBe(0);
});

it('--in-flight runs today deliberately, for a provisional test cut-off', function (): void {
    expect(Artisan::call('gsb:daily-cutoff', ['--in-flight' => true]))->toBe(0);
});

it('runs a closed day with no override at all', function (): void {
    expect(Artisan::call('gsb:daily-cutoff', ['--date' => Carbon::yesterday()->toDateString()]))->toBe(0);
});

it('records a SKIPPED run when a later cut-off has already passed the day, and the day stays proven', function (): void {
    // A1/R-91. The carry-forward store is rolling, so a day a later cut-off has
    // passed cannot be recomputed — an ordering decision, not an attempt that
    // failed. Recorded as `failed` it would un-prove a day that was cut off
    // correctly (D13: the latest finished attempt decides), and the month
    // containing it would be unclosable over a refusal nobody can clear.
    $day = Carbon::today()->subDays(2);
    $next = $day->copy()->addDay();

    $distributor = Distributor::factory()->create(['status' => 'active', 'adn' => '100000001']);
    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => 999_101,
        'bv_paise' => 300_000,
        'type' => 'accrual',
        'effective_at' => $day->copy()->subDay(),
    ]);

    // Below the slab-1 threshold on both days: the cut-off records `no_match`,
    // which still advances the rolling store. A CREDITED day would never reach
    // the guard — the engine's idempotency check returns first.
    foreach ([$day, $next] as $date) {
        GroupBvDaily::create([
            'distributor_id' => $distributor->id,
            'date' => $date->toDateString(),
            'left_bv_paise' => 1_000_000,
            'right_bv_paise' => 800_000,
        ]);
    }

    // The day was cut off correctly; the next day then advanced the store.
    app(GsbCutoffService::class)->runForDistributor($distributor->id, $day);
    app(GsbCutoffService::class)->runForDistributor($distributor->id, $next);

    // The run row the chain recorded for the day, started once the day ended.
    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => $day->toDateString(),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => $next->copy()->setTime(0, 6),
        'finished_at' => $next->copy()->setTime(0, 8),
    ]);

    $exitCode = Artisan::call('gsb:daily-cutoff', ['--date' => $day->toDateString()]);

    $run = EngineRun::where('engine_key', 'gsb.daily-cutoff')->latest('id')->first();

    // Non-zero all the same: the caller asked for work that did not happen.
    expect($exitCode)->toBe(1)
        ->and($run->period_start->toDateString())->toBe($day->toDateString())
        ->and($run->status)->toBe(EngineRun::STATUS_SKIPPED)
        ->and($run->error)->toContain('only while it is the newest one')
        ->and(app(EngineStatusService::class)->completedCutoffDatesBetween($day, $day))
        ->toBe([$day->toDateString()]);
});
