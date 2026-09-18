<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\MonthlyRunPlanner;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

function seedMonthlyPlannerRun(string $key, string $period, string $status, string $startedAt): EngineRun
{
    return EngineRun::create([
        'engine_key' => $key,
        'period_start' => $period,
        'status' => $status,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse($startedAt),
        'finished_at' => Carbon::parse($startedAt)->addMinutes(2),
    ]);
}

/** A completed cut-off is a succeeded run that started after its day had ended. */
function seedMonthlyPlannerCutoffs(string $from, string $to): void
{
    for ($day = Carbon::parse($from); $day->lessThanOrEqualTo(Carbon::parse($to)); $day->addDay()) {
        seedMonthlyPlannerRun(
            'gsb.daily-cutoff',
            $day->toDateString(),
            EngineRun::STATUS_SUCCEEDED,
            $day->copy()->addDay()->setTime(0, 6)->toDateTimeString(),
        );
    }
}

function seedMonthlyPlannerBatch(string $batchMonthStart): void
{
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => $batchMonthStart,
        'earnings_through' => Carbon::parse($batchMonthStart)->subMonthNoOverflow()->endOfMonth()->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse($batchMonthStart)->setTime(4, 5),
    ]);
}

/**
 * One product sale in the month. Without it the completion gate waves the month
 * through as owing no crediting (D1), and a test that meant to exercise the
 * gate would quietly stop doing so.
 */
function seedMonthlyPlannerSales(string $month): void
{
    BvLedgerEntry::create([
        'distributor_id' => 1,
        'order_id' => 960_000 + (int) Carbon::parse($month)->format('Ym'),
        'bv_paise' => 300_000,
        'type' => BvLedgerEntry::TYPE_ACCRUAL,
        'effective_at' => Carbon::parse($month)->startOfMonth()->addDays(3),
    ]);
}

/**
 * Every crediting engine recorded as having succeeded for the month, started
 * once the month had closed.
 *
 * @param  list<string>  $except
 */
function seedMonthlyPlannerCrediting(string $month, array $except = []): void
{
    $monthStart = Carbon::parse($month)->startOfMonth();

    foreach (MonthlyEngineCompletionGate::ENGINE_KEYS as $key) {
        if (in_array($key, $except, true)) {
            continue;
        }

        seedMonthlyPlannerRun(
            $key,
            MonthlyEngineCompletionGate::periodFor(EngineRegistry::get($key), $monthStart)->toDateString(),
            EngineRun::STATUS_SUCCEEDED,
            $monthStart->copy()->addMonthNoOverflow()->setTime(0, 20)->toDateTimeString(),
        );
    }
}

/**
 * The ordinary shape of 1 October 2026 (a Thursday): September traded from
 * mid-August's first distributor, every day of it is cut off, tonight's nightly
 * run is green, and no Tuesday batch is owed.
 */
function arrangeClosableSeptember(): void
{
    Distributor::factory()->create(['effective_date' => '2026-08-15']);
    seedMonthlyPlannerCutoffs('2026-09-01', '2026-09-30');
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-10-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:05:00');

    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-29',
        'earnings_through' => '2026-09-22',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-09-29 03:05:00'),
    ]);
}

/** @return list<string> */
function closeMonths(string $night): array
{
    return array_map(
        static fn (Carbon $month): string => $month->format('Y-m'),
        app(MonthlyRunPlanner::class)->closePhase(Carbon::parse($night))->months,
    );
}

/** @return list<string> */
function payoutMonths(string $night): array
{
    return array_map(
        static fn (Carbon $month): string => $month->format('Y-m'),
        app(MonthlyRunPlanner::class)->payoutPhase(Carbon::parse($night))->months,
    );
}

beforeEach(function (): void {
    disableTestForeignKeys();

    foreach ([
        GenosSalesBonusFeature::class,
        RepurchaseEngineFeature::class,
        RankBonusFeature::class,
        GrowthBoosterBonusFeature::class,
        FortuneBonusFeature::class,
        AreteDevelopmentCenterBonusFeature::class,
        PurchaseOffersFeature::class,
    ] as $feature) {
        Feature::for(null)->activate($feature);
    }
});

it('steps the month that has just ended once every owed day is cut off', function (): void {
    arrangeClosableSeptember();

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-10-01'));

    expect(array_map(static fn (Carbon $m): string => $m->format('Y-m'), $phase->months))->toBe(['2026-09']);
    expect($phase->deferrals)->toBe([]);
});

it('defers a month with a day that was never cut off, naming how many are missing', function (): void {
    arrangeClosableSeptember();
    EngineRun::where('engine_key', 'gsb.daily-cutoff')->whereDate('period_start', '2026-09-17')->delete();

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-10-01'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals)->toHaveCount(1);
    expect($phase->deferrals[0]->cause)->toBe('coverage');
    expect($phase->deferrals[0]->missingDays)->toBe(1);
    expect($phase->deferrals[0]->reason)->toContain('1 of the 30 days in September 2026');
});

it('defers a month while a daily cut-off is still running', function (): void {
    // The cut-off commits per distributor, so closing beside one prices the
    // month against a day still being written.
    Carbon::setTestNow('2026-10-01 04:00:00');
    arrangeClosableSeptember();
    seedMonthlyPlannerRun('gsb.daily-cutoff', '2026-09-30', EngineRun::STATUS_RUNNING, '2026-10-01 03:58:00');

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-10-01'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals[0]->cause)->toBe('cutoff_in_flight');
    expect($phase->deferrals[0]->missingDays)->toBeNull();
});

it('asks for no cut-offs at all while GSB is off', function (): void {
    // The cut-off computes nothing while the flag is off, so the month owes
    // none — demanding them would deadlock every close.
    Feature::for(null)->deactivate(GenosSalesBonusFeature::class);
    Distributor::factory()->create(['effective_date' => '2026-08-15']);
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-10-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:05:00');

    expect(closeMonths('2026-10-01'))->toBe(['2026-09']);
});

it('owes nothing once the month has been closed', function (): void {
    arrangeClosableSeptember();
    seedMonthlyPlannerRun('compensation.monthly-close', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:20:00');

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-10-01'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals)->toBe([]);
});

it('closes a launch month although it began part-way through', function (): void {
    // A day with no distributor has nobody to match and nothing to carry
    // forward. Demanding one is what would make a launch month impossible to
    // close for ever.
    Distributor::factory()->create(['effective_date' => '2026-09-20']);
    seedMonthlyPlannerCutoffs('2026-09-20', '2026-09-30');
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-10-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:05:00');
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-29',
        'earnings_through' => '2026-09-22',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-09-29 03:05:00'),
    ]);

    expect(closeMonths('2026-10-01'))->toBe(['2026-09']);
});

it('still defers a month missing days on which distributors existed', function (): void {
    // D2 is not a general relaxation: a day on which distributors existed and
    // bought nothing is still a day the engines owe a cut-off for.
    Distributor::factory()->create(['effective_date' => '2026-09-01']);
    seedMonthlyPlannerCutoffs('2026-09-01', '2026-09-20');
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-10-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:05:00');
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-29',
        'earnings_through' => '2026-09-22',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-09-29 03:05:00'),
    ]);

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-10-01'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals[0]->missingDays)->toBe(10);
});

it('treats a month the platform did not exist in as whole', function (): void {
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-10-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:05:00');
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => '2026-09-29',
        'earnings_through' => '2026-09-22',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-09-29 03:05:00'),
    ]);

    expect(Distributor::query()->count())->toBe(0);
    expect(closeMonths('2026-10-01'))->toBe(['2026-09']);
});

it('defers the close when tonight\'s nightly run is not green', function (): void {
    arrangeClosableSeptember();
    EngineRun::where('engine_key', 'compensation.nightly-run')->delete();

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-10-01'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals[0]->cause)->toBe('prerequisite');
    expect($phase->deferrals[0]->reason)
        ->toContain("September 2026 was not closed tonight: tonight's nightly run (01 Oct 2026) has not succeeded");
});

it('defers the close on a Tuesday 1st whose weekly run is not green', function (): void {
    // 1 September 2026 is a Tuesday: a batch is owed tonight, so the close
    // waits for it as well as for the nightly run.
    Distributor::factory()->create(['effective_date' => '2026-07-15']);
    seedMonthlyPlannerCutoffs('2026-08-01', '2026-08-31');
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-09-01 00:05:00');

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-09-01'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals[0]->cause)->toBe('prerequisite');
    expect($phase->deferrals[0]->reason)->toContain('a Tuesday batch (2026-09-01) is owed');
});

it('closes on a Tuesday 1st once the weekly run is green', function (): void {
    Distributor::factory()->create(['effective_date' => '2026-07-15']);
    seedMonthlyPlannerCutoffs('2026-08-01', '2026-08-31');
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-09-01 00:05:00');
    seedMonthlyPlannerRun('compensation.weekly-run', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-09-01 03:00:00');

    expect(closeMonths('2026-09-01'))->toBe(['2026-08']);
});

it('is not blocked by a flag-off Tuesday, because the weekly run still succeeds', function (): void {
    // With GSB off the weekly run is still due on a Tuesday, records a skipped
    // leaf and exits 0 — which is exactly what keeps it from blocking the close
    // for as long as the flag stays off.
    Feature::for(null)->deactivate(GenosSalesBonusFeature::class);
    Distributor::factory()->create(['effective_date' => '2026-07-15']);
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-09-01 00:05:00');
    seedMonthlyPlannerRun('compensation.weekly-run', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-09-01 03:00:00');

    expect(closeMonths('2026-09-01'))->toBe(['2026-08']);
});

it('owes no close for a frozen month, even with no close row at all', function (): void {
    // A2. A frozen month does not always carry a `compensation.monthly-close`
    // row: the engines can be run by hand, a close that died mid-way can be
    // finished by hand, and every month survives a full recompute, which
    // truncates `engine_runs` and replays the leaf engines only. Without this
    // the monthly run would plan a close for a month finance has paid, and be
    // refused on it every night by a guard that has no override.
    arrangeClosableSeptember();
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-10-01',
        'earnings_through' => '2026-09-30',
        'status' => PayoutBatch::STATUS_APPROVED,
        'approved_at' => Carbon::parse('2026-10-08 11:30:00'),
        'processed_at' => Carbon::parse('2026-10-08 04:05:00'),
    ]);

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-10-01'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals)->toBe([]);
    expect(app(MonthlyRunPlanner::class)->isDue(Carbon::parse('2026-10-05')))->toBeFalse();
});

it('owes no close for a month whose batch is built and awaiting approval', function (): void {
    // The planner asks exactly what the close command asks
    // (FrozenPayoutGuard::creditingRefusal): once the sweep has run, nothing
    // may be credited into the month, so planning a close for it would only
    // produce a refusal every night that nobody can clear.
    arrangeClosableSeptember();
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-10-01',
        'earnings_through' => '2026-09-30',
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse('2026-10-08 04:05:00'),
    ]);

    $phase = app(MonthlyRunPlanner::class)->closePhase(Carbon::parse('2026-10-01'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals)->toBe([]);
    expect(app(MonthlyRunPlanner::class)->isDue(Carbon::parse('2026-10-05')))->toBeFalse();
});

it('still owes the close while the month\'s batch has not been swept', function (): void {
    // A batch row the sweep created and has not yet filled (`processed_at`
    // null) has decided nothing: nobody has signed anything off, nothing has
    // been collected, and the close is owed as usual.
    arrangeClosableSeptember();
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-10-01',
        'earnings_through' => '2026-09-30',
        'status' => PayoutBatch::STATUS_PENDING,
    ]);

    expect(closeMonths('2026-10-01'))->toBe(['2026-09']);
    expect(app(MonthlyRunPlanner::class)->isDue(Carbon::parse('2026-10-05')))->toBeTrue();
});

it('builds the payout from the 8th once every crediting engine has succeeded', function (): void {
    seedMonthlyPlannerBatch('2026-09-01');
    seedMonthlyPlannerSales('2026-09-01');
    seedMonthlyPlannerCrediting('2026-09-01');

    expect(payoutMonths('2026-10-08'))->toBe(['2026-09']);
});

it('defers the payout naming the engine the gate refused on', function (): void {
    seedMonthlyPlannerBatch('2026-09-01');
    seedMonthlyPlannerSales('2026-09-01');
    seedMonthlyPlannerCrediting('2026-09-01', except: ['fortune.payout']);

    $phase = app(MonthlyRunPlanner::class)->payoutPhase(Carbon::parse('2026-10-08'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals)->toHaveCount(1);
    expect($phase->deferrals[0]->kind)->toBe('payout');
    expect($phase->deferrals[0]->engineKey)->toBe('fortune.payout');
    expect($phase->deferrals[0]->reason)->toContain('The September 2026 payout was not built');
});

it('does not build this month\'s payout while the month before it is deferred', function (): void {
    // A7. A monthly batch sweeps every unswept monthly credit earned in its own
    // month OR EARLIER, so building September while August is deferred would pay
    // August's half-computed credits under September's batch — and the rest of
    // August would then be credited into a month nothing sweeps again.
    seedMonthlyPlannerSales('2026-08-01');
    seedMonthlyPlannerCrediting('2026-08-01', except: ['fortune.payout']);
    seedMonthlyPlannerSales('2026-09-01');
    seedMonthlyPlannerCrediting('2026-09-01');

    $phase = app(MonthlyRunPlanner::class)->payoutPhase(Carbon::parse('2026-10-08'));

    expect($phase->months)->toBe([]);
    expect($phase->deferrals)->toHaveCount(2);
    expect($phase->deferrals[0]->month->format('Y-m'))->toBe('2026-08');
    expect($phase->deferrals[1]->month->format('Y-m'))->toBe('2026-09');
    expect($phase->deferrals[1]->engineKey)->toBe('fortune.payout');
    expect($phase->deferrals[1]->reason)
        ->toContain('The September 2026 payout waits on August 2026')
        ->toContain('its own month or earlier');
});

it('builds both months oldest first once the older one is complete', function (): void {
    seedMonthlyPlannerSales('2026-08-01');
    seedMonthlyPlannerCrediting('2026-08-01');
    seedMonthlyPlannerSales('2026-09-01');
    seedMonthlyPlannerCrediting('2026-09-01');

    expect(payoutMonths('2026-10-08'))->toBe(['2026-08', '2026-09']);
});

it('does not look back at a month the platform never traded in', function (): void {
    // D1b: the lookback catches a batch up, it never invents one. Without the
    // bound a platform opening in October builds an empty September batch for
    // an August the company did not exist in.
    expect(payoutMonths('2026-10-05'))->toBe([]);
});

it('looks back at a month that did trade and was never paid', function (): void {
    seedMonthlyPlannerSales('2026-08-01');
    seedMonthlyPlannerCrediting('2026-08-01');

    expect(payoutMonths('2026-10-05'))->toBe(['2026-08']);
});

it('pays a sales-free month rather than deferring it', function (): void {
    // Hard rule 2 makes it exact: no product sale, no credit, nothing stranded.
    // The batch must still run — it is what sweeps an older credit whose hold
    // has since cleared.
    seedMonthlyPlannerBatch('2026-09-01');

    expect(payoutMonths('2026-10-08'))->toBe(['2026-09']);
});

it('plans the payout without asking anything of tonight\'s runs', function (): void {
    // The 8th is independent: it pays what the 1st credited, and neither
    // tonight's cut-off nor tonight's Tuesday batch can change a figure it
    // sweeps.
    seedMonthlyPlannerBatch('2026-09-01');
    seedMonthlyPlannerSales('2026-09-01');
    seedMonthlyPlannerCrediting('2026-09-01');
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-10-08', EngineRun::STATUS_FAILED, '2026-10-08 00:05:00');

    expect(payoutMonths('2026-10-08'))->toBe(['2026-09']);
});

it('is due on the 1st of every month', function (): void {
    expect(app(MonthlyRunPlanner::class)->isDue(Carbon::parse('2026-10-01')))->toBeTrue();
});

it('is not due mid-month once the month is closed and paid', function (): void {
    arrangeClosableSeptember();
    seedMonthlyPlannerRun('compensation.monthly-close', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:20:00');
    seedMonthlyPlannerBatch('2026-09-01');

    expect(app(MonthlyRunPlanner::class)->isDue(Carbon::parse('2026-10-05')))->toBeFalse();
});

it('is due mid-month while a closable month is still open, even after a failed night', function (): void {
    // The self-heal: the close is owed, so the run starts and re-attempts it.
    arrangeClosableSeptember();
    seedMonthlyPlannerBatch('2026-09-01');
    seedMonthlyPlannerRun('compensation.nightly-run', '2026-10-05', EngineRun::STATUS_FAILED, '2026-10-05 00:05:00');

    expect(app(MonthlyRunPlanner::class)->isDue(Carbon::parse('2026-10-05')))->toBeTrue();
});

it('is due from the 8th while this month\'s batch is missing', function (): void {
    arrangeClosableSeptember();
    seedMonthlyPlannerRun('compensation.monthly-close', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:20:00');
    seedMonthlyPlannerBatch('2026-09-01');

    expect(app(MonthlyRunPlanner::class)->isDue(Carbon::parse('2026-10-08')))->toBeTrue();
});

it('is not due late in the month once the batch has been built', function (): void {
    arrangeClosableSeptember();
    seedMonthlyPlannerRun('compensation.monthly-close', '2026-09-01', EngineRun::STATUS_SUCCEEDED, '2026-10-01 00:20:00');
    seedMonthlyPlannerBatch('2026-09-01');
    seedMonthlyPlannerBatch('2026-10-01');

    expect(app(MonthlyRunPlanner::class)->isDue(Carbon::parse('2026-10-20')))->toBeFalse();
});
