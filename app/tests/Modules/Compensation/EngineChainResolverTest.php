<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\DTOs\EngineChainStep;
use App\Modules\Compensation\Services\EngineChainResolver;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    // A fixed "now" in mid-month keeps the yesterday cap deterministic.
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

    // Flags on: the resolver leaves flag-off prerequisites out of a chain, so
    // the dependency-shape tests need every engine live.
    foreach (EngineRegistry::all() as $definition) {
        if ($definition->featureFlagClass !== null) {
            Feature::activate($definition->featureFlagClass);
        }
    }
});

it('leaves a flag-off prerequisite out of the chain and warns about it', function (): void {
    Feature::deactivate(RankBonusFeature::class);
    markMonthOfCutoffsDone('2026-05', '2026-05-31');

    $engine = EngineRegistry::get('fortune.enroll');
    $plan = app(EngineChainResolver::class)->resolve('fortune.enroll', $engine->parsePeriod('2026-05'));

    $ids = array_map(fn (EngineChainStep $step): string => $step->id(), $plan->steps);

    expect($ids)->not->toContain('rank.check|2026-05');
    expect(end($ids))->toBe('fortune.enroll|2026-05');
    expect(implode(' ', $plan->warnings))->toContain('Rank Qualification Check');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return list<string> */
function chainIds(string $engineKey, string $period): array
{
    $engine = EngineRegistry::get($engineKey);

    return array_map(
        fn (EngineChainStep $step): string => $step->id(),
        app(EngineChainResolver::class)->resolve($engineKey, $engine->parsePeriod($period))->steps,
    );
}

/** Mark a GSB cut-off day as done via the run log (no distributor rows needed). */
function markCutoffDone(string $date): void
{
    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => $date,
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
}

function markMonthOfCutoffsDone(string $month, string $upTo): void
{
    $day = Carbon::parse($month.'-01');
    $end = Carbon::parse($upTo);

    for (; $day->lte($end); $day->addDay()) {
        markCutoffDone($day->toDateString());
    }
}

it('always runs the target engine even when the period is already computed', function (): void {
    RankQualification::create([
        'distributor_id' => 1, 'rank_number' => 1, 'month_start' => '2026-05-01',
        'left_genos_bv_paise' => 0, 'right_genos_bv_paise' => 0,
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => 'qualified',
    ]);
    markMonthOfCutoffsDone('2026-05', '2026-05-31');

    expect(chainIds('rank.check', '2026-05'))->toBe(['rank.check|2026-05']);
});

it('runs prerequisites before the target, deepest first', function (): void {
    markMonthOfCutoffsDone('2026-05', '2026-05-31');

    // rank.bonus → rank.check (same month) → daily cut-offs (all present).
    expect(chainIds('rank.bonus', '2026-05'))->toBe([
        'rank.check|2026-05',
        'rank.bonus|2026-05',
    ]);
});

it('skips a prerequisite that is already computed', function (): void {
    markMonthOfCutoffsDone('2026-05', '2026-05-31');
    RankQualification::create([
        'distributor_id' => 1, 'rank_number' => 1, 'month_start' => '2026-05-01',
        'left_genos_bv_paise' => 0, 'right_genos_bv_paise' => 0,
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => 'qualified',
    ]);

    expect(chainIds('rank.bonus', '2026-05'))->toBe(['rank.bonus|2026-05']);
});

it('expands a month engine into only the missing daily cut-offs', function (): void {
    // Everything except the 3rd and the 7th of May is done.
    $day = Carbon::parse('2026-05-01');
    for (; $day->lte(Carbon::parse('2026-05-31')); $day->addDay()) {
        if (! in_array($day->toDateString(), ['2026-05-03', '2026-05-07'], true)) {
            markCutoffDone($day->toDateString());
        }
    }

    // Rank qualifications for April already exist, so GBB's prev-month rank
    // check is skipped and only the two gaps remain.
    RankQualification::create([
        'distributor_id' => 1, 'rank_number' => 1, 'month_start' => '2026-04-01',
        'left_genos_bv_paise' => 0, 'right_genos_bv_paise' => 0,
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => 'qualified',
    ]);

    expect(chainIds('gbb.monthly', '2026-05'))->toBe([
        'gsb.daily-cutoff|2026-05-03',
        'gsb.daily-cutoff|2026-05-07',
        'gbb.monthly|2026-05',
    ]);
});

it('accepts existing cut-off result rows as proof a day already ran', function (): void {
    markMonthOfCutoffsDone('2026-05', '2026-05-31');
    // Blank the run log for one day and prove the derived fallback covers it.
    EngineRun::whereDate('period_start', '2026-05-09')->delete();

    GsbCutoffResult::create([
        'distributor_id' => 1, 'cutoff_date' => '2026-05-09',
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0, 'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_NO_MATCH,
    ]);

    expect(chainIds('rank.check', '2026-05'))->toBe(['rank.check|2026-05']);
});

it('pulls the previous month rank check in for the growth booster', function (): void {
    markMonthOfCutoffsDone('2026-05', '2026-05-31');
    markMonthOfCutoffsDone('2026-04', '2026-04-30');

    expect(chainIds('gbb.monthly', '2026-05'))->toBe([
        'rank.check|2026-04',
        'gbb.monthly|2026-05',
    ]);
});

it('treats a frozen fortune pool as enrolment already done', function (): void {
    markMonthOfCutoffsDone('2026-05', '2026-05-31');
    RankQualification::create([
        'distributor_id' => 1, 'rank_number' => 1, 'month_start' => '2026-05-01',
        'left_genos_bv_paise' => 0, 'right_genos_bv_paise' => 0,
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => 'qualified',
    ]);

    // Without the pool, enrolment (and its rank-check prerequisite) is chained.
    expect(chainIds('fortune.payout', '2026-05'))->toBe([
        'fortune.enroll|2026-05',
        'fortune.payout|2026-05',
    ]);

    FortuneMonthlyPool::create([
        'month_start' => '2026-05-01', 'company_bv_paise' => 0, 'pool_rate_bp' => 0, 'pool_paise' => 0,
        'total_points' => 0, 'point_value_paise' => 0, 'payout_paise' => 0, 'leftover_paise' => 0,
    ]);

    // With it, chaining the payout must not re-trigger enrolment, which would
    // only refuse with `refused_pool_frozen`.
    expect(chainIds('fortune.payout', '2026-05'))->toBe(['fortune.payout|2026-05']);
});

it('leaves repurchase evaluation out of a historical cut-off backfill', function (): void {
    $engine = EngineRegistry::get('gsb.daily-cutoff');
    $plan = app(EngineChainResolver::class)->resolve('gsb.daily-cutoff', $engine->parsePeriod('2026-03-04'));

    expect(array_map(fn (EngineChainStep $s): string => $s->id(), $plan->steps))
        ->toBe(['gsb.daily-cutoff|2026-03-04']);
    expect($plan->warnings)->toHaveCount(1);
    expect($plan->warnings[0])->toContain('Repurchase Evaluation was left out');
});

it('does chain repurchase evaluation for today and yesterday', function (): void {
    $engine = EngineRegistry::get('gsb.daily-cutoff');

    foreach (['2026-06-15', '2026-06-14'] as $date) {
        $plan = app(EngineChainResolver::class)->resolve('gsb.daily-cutoff', $engine->parsePeriod($date));

        expect(array_map(fn (EngineChainStep $s): string => $s->id(), $plan->steps))->toBe([
            'repurchase.evaluate|'.$date,
            'gsb.daily-cutoff|'.$date,
        ]);
    }
});

it('never backfills a cut-off for today or a future day', function (): void {
    // Resolving the current month stops at yesterday (14 June).
    $ids = chainIds('rank.check', '2026-06');

    expect($ids)->toContain('gsb.daily-cutoff|2026-06-14');
    expect($ids)->not->toContain('gsb.daily-cutoff|2026-06-15');
    expect($ids)->not->toContain('gsb.daily-cutoff|2026-06-16');
    expect(end($ids))->toBe('rank.check|2026-06');
});

it('expands the weekly payout over the seven days ending on the batch date', function (): void {
    $ids = chainIds('gsb.weekly-payout', '2026-06-10');

    expect($ids)->toContain('gsb.daily-cutoff|2026-06-04');
    expect($ids)->toContain('gsb.daily-cutoff|2026-06-10');
    expect($ids)->not->toContain('gsb.daily-cutoff|2026-06-03');
    expect(end($ids))->toBe('gsb.weekly-payout|2026-06-10');
});

it('de-duplicates an engine that several branches depend on', function (): void {
    markMonthOfCutoffsDone('2026-05', '2026-05-31');
    markMonthOfCutoffsDone('2026-04', '2026-04-30');

    // payout.monthly → gbb (→ rank.check Apr), rank.bonus (→ rank.check May),
    // fortune.payout (→ fortune.enroll → rank.check May), adc.
    $ids = chainIds('payout.monthly', '2026-05');

    expect(array_count_values($ids)['rank.check|2026-05'])->toBe(1);
    expect($ids)->toBe([
        'rank.check|2026-04',
        'gbb.monthly|2026-05',
        'rank.check|2026-05',
        'rank.bonus|2026-05',
        'fortune.enroll|2026-05',
        'fortune.payout|2026-05',
        'adc.bonus|2026-05',
        'payout.monthly|2026-05',
    ]);
});

it('chains flag-off engines anyway, matching what cron does', function (): void {
    // Every bonus flag is off by default; the commands self-guard and no-op,
    // so the chain still lists them rather than silently dropping steps.
    markMonthOfCutoffsDone('2026-05', '2026-05-31');
    markMonthOfCutoffsDone('2026-04', '2026-04-30');

    expect(chainIds('gbb.monthly', '2026-05'))->toBe([
        'rank.check|2026-04',
        'gbb.monthly|2026-05',
    ]);
});

it('counts dependencies and lists their engine labels for the confirm modal', function (): void {
    markMonthOfCutoffsDone('2026-05', '2026-05-31');
    markMonthOfCutoffsDone('2026-04', '2026-04-30');
    GbbMonthlyPool::create([
        'month_start' => '2026-05-01', 'company_bv_paise' => 0, 'pool_rate_bp' => 0, 'pool_paise' => 0,
        'total_agp' => 0, 'point_value_paise' => 0, 'payout_paise' => 0, 'leftover_paise' => 0,
    ]);

    $engine = EngineRegistry::get('gbb.monthly');
    $plan = app(EngineChainResolver::class)->resolve('gbb.monthly', $engine->parsePeriod('2026-05'));

    expect($plan->dependencyCount())->toBe(1);
    expect($plan->dependencyEngineLabels())->toBe(['Rank Qualification Check']);
    expect($plan->toAuditPreview())->toBe(['rank.check|2026-04', 'gbb.monthly|2026-05']);
});
