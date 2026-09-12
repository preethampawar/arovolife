<?php

declare(strict_types=1);

/**
 * `platform.engine_runs_failed` reads `EngineHealthService::report()` rather
 * than re-querying `engine_runs` (plan §4, Platform) — so it is exercised
 * here against real engine data, at the frozen clock and healthy-period
 * fixture `EngineHealthDigestTest` uses, rather than a mock (the service is
 * `final`).
 */

use App\Modules\ActionCenter\Providers\Platform\EngineRunsFailedProvider;
use App\Modules\Compensation\Models\EngineRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

const ENGINE_RUNS_FAILED_HEALTHY_PERIODS = [
    'repurchase.evaluate' => '2026-09-08',
    'gsb.daily-cutoff' => '2026-09-07',
    'gsb.weekly-payout' => '2026-09-08',
    'gbb.monthly' => '2026-08-01',
    'rank.check' => '2026-08-01',
    'rank.bonus' => '2026-08-01',
    'adc.bonus' => '2026-08-01',
    'offers.monthly' => '2026-08-01',
    'fortune.enroll' => '2026-08-01',
    'fortune.payout' => '2026-08-01',
    'payout.monthly' => '2026-09-01',
];

function seedEngineRunsFailedFixture(string $exceptKey = '', string $exceptStatus = ''): void
{
    foreach (ENGINE_RUNS_FAILED_HEALTHY_PERIODS as $key => $period) {
        EngineRun::create([
            'engine_key' => $key,
            'period_start' => $period,
            'status' => $key === $exceptKey ? $exceptStatus : EngineRun::STATUS_SUCCEEDED,
            'trigger' => 'console',
            'error' => $key === $exceptKey && $exceptStatus === EngineRun::STATUS_FAILED ? 'Timeout calling the GSB service.' : null,
            'started_at' => '2026-09-08 04:30:00',
            'finished_at' => $key === $exceptKey && $exceptStatus === EngineRun::STATUS_RUNNING ? null : '2026-09-08 04:30:00',
        ]);
    }
}

beforeEach(function (): void {
    disableTestForeignKeys();
    Carbon::setTestNow('2026-09-08 08:00:00');
    $this->provider = app(EngineRunsFailedProvider::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('reports zero when every scheduled engine ran successfully for its period', function (): void {
    seedEngineRunsFailedFixture();

    expect($this->provider->count())->toBe(0);
    expect($this->provider->items())->toBeEmpty();
});

it('reports a failed run', function (): void {
    seedEngineRunsFailedFixture('gsb.weekly-payout', EngineRun::STATUS_FAILED);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subtitle)->toContain('Timeout');
});

it('reports a missing run when no row exists at all for a scheduled period', function (): void {
    seedEngineRunsFailedFixture();
    EngineRun::where('engine_key', 'rank.check')->delete();

    expect($this->provider->count())->toBe(1);
});
