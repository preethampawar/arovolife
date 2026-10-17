<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Support\RankQualificationsGate;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

/** A rank.check run for any month. */
function rankCheckRunFor(string $monthStart, string $status, ?string $reason = null): void
{
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => $monthStart,
        'status' => $status,
        'trigger' => 'console',
        'summary' => $reason !== null ? ['reason' => $reason] : null,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
}

/** One Genos BV row dated inside the month — enough for it to need a check. */
function genosBvOn(string $date): void
{
    disableTestForeignKeys();

    DB::table('group_bv_daily')->insert([
        'distributor_id' => 1,
        'date' => $date,
        'left_bv_paise' => 100_000,
        'right_bv_paise' => 0,
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/** @return list<string> */
function monthsMissingCheck(string $lastMonth): array
{
    return array_map(
        fn (Carbon $month): string => $month->format('Y-m'),
        RankQualificationsGate::monthsMissingCheck(Carbon::parse($lastMonth)),
    );
}

it('lists every month since the first Genos BV that lacks a succeeded check, oldest first', function (): void {
    genosBvOn('2026-06-10');
    genosBvOn('2026-07-10');
    genosBvOn('2026-08-10');
    rankCheckRunFor('2026-07-01', EngineRun::STATUS_SUCCEEDED);
    rankCheckRunFor('2026-08-01', EngineRun::STATUS_FAILED);

    expect(monthsMissingCheck('2026-08-01'))->toBe(['2026-06', '2026-08']);
});

it('starts the walk at the first rank qualification row when it precedes any Genos BV', function (): void {
    disableTestForeignKeys();
    DB::table('rank_qualifications')->insert([
        'distributor_id' => 1,
        'rank_number' => 1,
        'month_start' => '2026-05-01',
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'status' => 'qualified',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    genosBvOn('2026-06-10');

    expect(monthsMissingCheck('2026-06-01'))->toBe(['2026-05', '2026-06']);
});

it('excuses a month with no Genos BV and no qualification row, M-1 included', function (): void {
    // Nobody could have ranked in July or September, so their exclusion sets
    // are provably empty; June and August carry BV and must be checked.
    genosBvOn('2026-06-10');
    genosBvOn('2026-08-10');
    Log::spy();

    expect(monthsMissingCheck('2026-09-01'))->toBe(['2026-06', '2026-08']);

    // Each waiver leaves a trace; a month that is listed is not "waived".
    foreach (['2026-07', '2026-09'] as $month) {
        Log::shouldHaveReceived('info')
            ->with('rank.check.prerequisite_waived', ['month' => $month, 'reason' => 'no_genos_bv'])
            ->once();
    }
    Log::shouldNotHaveReceived('info', ['rank.check.prerequisite_waived', ['month' => '2026-06', 'reason' => 'no_genos_bv']]);
    Log::shouldNotHaveReceived('info', ['rank.check.prerequisite_waived', ['month' => '2026-08', 'reason' => 'no_genos_bv']]);
});

it('excuses a flag-off skipped check before M-1 only, whatever the flag is now', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);
    genosBvOn('2026-07-10');
    genosBvOn('2026-08-10');
    rankCheckRunFor('2026-07-01', EngineRun::STATUS_SKIPPED, 'feature_flag_off');
    rankCheckRunFor('2026-08-01', EngineRun::STATUS_SKIPPED, 'feature_flag_off');
    Log::spy();

    expect(monthsMissingCheck('2026-08-01'))->toBe(['2026-08']);

    Log::shouldHaveReceived('info')
        ->with('rank.check.prerequisite_waived', ['month' => '2026-07', 'reason' => 'rank_engine_off'])
        ->once();
    Log::shouldNotHaveReceived('info', ['rank.check.prerequisite_waived', ['month' => '2026-08', 'reason' => 'rank_engine_off']]);
});

it('lists nothing when every month is checked or excused', function (): void {
    genosBvOn('2026-07-10');
    rankCheckRunFor('2026-07-01', EngineRun::STATUS_SUCCEEDED);

    expect(monthsMissingCheck('2026-08-01'))->toBe([]);
});
