<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(RankBonusFeature::class);
});

/** A succeeded `repurchase:evaluate` run for the given date. */
function seedRankCheckEvaluateRun(string $period): void
{
    EngineRun::create([
        'engine_key' => 'repurchase.evaluate',
        'period_start' => $period,
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse($period.' 00:05:00'),
        'finished_at' => Carbon::parse($period.' 00:06:00'),
    ]);
}

it('refuses when no evaluate run is dated after the checked month ended', function (): void {
    // Rank qualification sums only the days the distributor was not failed. A
    // cycle due on the last day of the month is resolved by the evaluate run on
    // the 1st of the next month, and a late fulfilment is stamped there too —
    // run before it and forfeited days would silently count toward the rank.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedRankCheckEvaluateRun('2026-08-31'); // inside the month — not far enough

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'rank.check.refused_missing_evaluate'
            && $context['month'] === '2026-08');

    $exitCode = Artisan::call('rank:check-qualifications', ['--month' => '2026-08']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('repurchase:evaluate --date=2026-09-01')
        ->and(RankQualification::count())->toBe(0);
});

it('records a FAILED engine run when refused', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    expect(Artisan::call('rank:check-qualifications', ['--month' => '2026-08']))->toBe(1);

    $run = EngineRun::where('engine_key', 'rank.check')->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(EngineRun::STATUS_FAILED)
        ->and($run->period_start->toDateString())->toBe('2026-08-01');
});

it('runs when the evaluate run is dated the 1st of the following month', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    seedRankCheckEvaluateRun('2026-09-01');

    expect(Artisan::call('rank:check-qualifications', ['--month' => '2026-08']))->toBe(0);
});

it('runs with --force despite the missing evaluate run', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    expect(Artisan::call('rank:check-qualifications', ['--month' => '2026-08', '--force' => true]))->toBe(0);
});

it('skips the guard entirely when the repurchase engine is off', function (): void {
    // Flag off = no forfeits anywhere, so the qualification sum has nothing to
    // wait for.
    expect(Artisan::call('rank:check-qualifications', ['--month' => '2026-08']))->toBe(0);
});
