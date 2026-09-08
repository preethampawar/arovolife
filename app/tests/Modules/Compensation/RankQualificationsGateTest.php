<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Support\RankQualificationsGate;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

function rankCheckRun(string $status, ?string $reason = null): void
{
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => '2026-08-01',
        'status' => $status,
        'trigger' => 'console',
        'summary' => $reason !== null ? ['reason' => $reason] : null,
        'started_at' => '2026-09-01 00:15:00',
        'finished_at' => '2026-09-01 00:15:01',
    ]);
}

it('opens on a succeeded check', function (): void {
    rankCheckRun(EngineRun::STATUS_SUCCEEDED);

    expect(RankQualificationsGate::checkedFor(Carbon::parse('2026-08-01')))->toBeTrue();
});

it('stays shut with no run at all', function (): void {
    expect(RankQualificationsGate::checkedFor(Carbon::parse('2026-08-01')))->toBeFalse();
});

it('opens on a flag-off skip only while the Rank Bonus flag is still off', function (): void {
    // With the engine off nobody can hold a rank, so the exclusion set the
    // dependants read is legitimately empty — but the moment the flag is on
    // again the month needs a real check before GBB/Fortune may run.
    rankCheckRun(EngineRun::STATUS_SKIPPED, 'feature_flag_off');

    Feature::for(null)->deactivate(RankBonusFeature::class);
    expect(RankQualificationsGate::checkedFor(Carbon::parse('2026-08-01')))->toBeTrue();

    Feature::for(null)->activate(RankBonusFeature::class);
    expect(RankQualificationsGate::checkedFor(Carbon::parse('2026-08-01')))->toBeFalse();
});

it('keeps the gate shut for every other skip reason', function (string $reason): void {
    // These mean the check did NOT happen. An empty exclusion set here would
    // credit GBB to distributors the plan bars and enrol barred seniors into
    // the capacity-capped Fortune matrix.
    rankCheckRun(EngineRun::STATUS_SKIPPED, $reason);
    Feature::for(null)->deactivate(RankBonusFeature::class);

    expect(RankQualificationsGate::checkedFor(Carbon::parse('2026-08-01')))->toBeFalse();
})->with(['already_running', 'upstream_failed', 'upstream_skipped', 'stale_worker']);

it('keeps the gate shut on a failed check', function (): void {
    rankCheckRun(EngineRun::STATUS_FAILED);

    expect(RankQualificationsGate::checkedFor(Carbon::parse('2026-08-01')))->toBeFalse();
});
