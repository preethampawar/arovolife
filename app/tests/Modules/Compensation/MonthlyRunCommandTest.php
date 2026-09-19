<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Console\Commands\MonthlyCloseCommand;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\NightlyRunAlert;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;
use Tests\Support\StubEngineStepCommand;

uses(RefreshDatabase::class, WithConsoleEvents::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    // Every flag on: MonthlyEngineCompletionGate treats a flag-off engine as
    // owing nothing, which would change which payout batches the run thinks are
    // due.
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

    StubEngineStepCommand::register(['compensation.monthly-close', 'compensation.monthly-payout-close']);
});

/** A succeeded cut-off row is what proves a day was CUT OFF TO COMPLETION. */
function seedMonthlyRunCutoffs(string $from, string $to): void
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

/** Tonight's nightly run — the close phase's ordering prerequisite. */
function seedMonthlyRunNightly(string $night, string $status = EngineRun::STATUS_SUCCEEDED, ?string $startedAt = null): void
{
    EngineRun::create([
        'engine_key' => 'compensation.nightly-run',
        'period_start' => $night,
        'status' => $status,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => $startedAt ?? $night.' 00:05:00',
        'finished_at' => $startedAt ?? $night.' 00:20:00',
    ]);
}

/**
 * The Tuesday batch that keeps the close phase's WEEKLY prerequisite quiet.
 *
 * A Tuesday still owed tonight makes the close wait for the weekly run, which
 * is the client's ordering rule — and not what most of these tests are about.
 */
function seedMonthlyRunWeekly(string $tuesday): void
{
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => $tuesday,
        'earnings_through' => Carbon::parse($tuesday)->subDays(7)->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse($tuesday)->setTime(3, 5),
    ]);
}

/** A monthly batch is the proof that a crediting month was paid. */
function seedMonthlyRunBatch(string $batchMonthStart): void
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
function seedMonthlyRunSale(string $month): void
{
    BvLedgerEntry::create([
        'distributor_id' => 1,
        'order_id' => 970_000 + (int) Carbon::parse($month.'-01')->format('Ym'),
        'bv_paise' => 300_000,
        'type' => BvLedgerEntry::TYPE_ACCRUAL,
        'effective_at' => Carbon::parse($month.'-01')->startOfMonth()->addDays(3),
    ]);
}

/** Every crediting engine of $month succeeded, which is what opens the payout gate. */
function seedCreditedMonth(string $month): void
{
    foreach (MonthlyEngineCompletionGate::ENGINE_KEYS as $key) {
        $definition = EngineRegistry::get($key);
        $period = MonthlyEngineCompletionGate::periodFor($definition, Carbon::parse($month.'-01'));

        EngineRun::create([
            'engine_key' => $key,
            'period_start' => $definition->periodStart($period),
            'status' => EngineRun::STATUS_SUCCEEDED,
            'trigger' => EngineRun::TRIGGER_CONSOLE,
            'started_at' => Carbon::parse($month.'-01')->addMonthNoOverflow()->setTime(1, 0),
            'finished_at' => Carbon::parse($month.'-01')->addMonthNoOverflow()->setTime(1, 5),
        ]);
    }
}

it('closes the month on the first night of the next one', function (): void {
    Carbon::setTestNow('2026-10-01 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-08-15']);
    seedMonthlyRunWeekly('2026-09-29');
    seedMonthlyRunCutoffs('2026-09-01', '2026-09-30');
    seedMonthlyRunNightly('2026-10-01');

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe(['compensation.monthly-close']);
    expect(StubEngineStepCommand::$periods['compensation.monthly-close'])->toBe('2026-09');
});

it('pays a month it closed the same night', function (): void {
    // The 8th, with August still unclosed: the close phase runs first and the
    // payout phase is PLANNED afterwards, so the completion gate reads the rows
    // tonight's close has just written rather than the state before it.
    Carbon::setTestNow('2026-09-08 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-07-15']);
    seedMonthlyRunWeekly('2026-09-08');
    seedMonthlyRunCutoffs('2026-08-01', '2026-08-31');
    seedMonthlyRunNightly('2026-09-08');

    // The close is stubbed, so its seven engines write nothing — seed what a
    // real close would have left behind.
    seedCreditedMonth('2026-08');

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([
        'compensation.monthly-close',
        'compensation.monthly-payout-close',
    ]);
    expect(StubEngineStepCommand::$periods['compensation.monthly-payout-close'])->toBe('2026-08');
});

it('records monthCloseDeferred with cause prerequisite when tonight\'s nightly run is not green', function (): void {
    Carbon::setTestNow('2026-10-01 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-08-15']);
    seedMonthlyRunWeekly('2026-09-29');
    seedMonthlyRunCutoffs('2026-09-01', '2026-09-30');
    seedMonthlyRunNightly('2026-10-01', EngineRun::STATUS_FAILED);

    $exitCode = Artisan::call('compensation:monthly-run');

    // A deferral, never a failed night (D3).
    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([]);
    expect(EngineRun::where('engine_key', 'compensation.monthly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_SKIPPED);

    $alert = AuditLog::where('action', NightlyRunAlert::ACTION_MONTH_DEFERRED)->sole();
    expect($alert->details['cause'])->toBe('prerequisite');
    expect($alert->details['month'])->toBe('2026-09');
});

it('closes the month anyway under --force', function (): void {
    Carbon::setTestNow('2026-10-01 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-08-15']);
    seedMonthlyRunWeekly('2026-09-29');
    seedMonthlyRunCutoffs('2026-09-01', '2026-09-30');
    seedMonthlyRunNightly('2026-10-01', EngineRun::STATUS_FAILED);

    Artisan::call('compensation:monthly-run', ['--force' => true]);

    expect(StubEngineStepCommand::$calls)->toBe(['compensation.monthly-close']);
    expect(AuditLog::where('action', NightlyRunAlert::ACTION_MONTH_DEFERRED)->count())->toBe(0);
});

it('defers a month whose days are not all cut off, once per night per cause', function (): void {
    // Every monthly engine prices the month from its cut-off results and then
    // FREEZES what it computed: a month closed three days short stays short,
    // and re-running the close reuses the frozen pool rather than repairing it.
    Carbon::setTestNow('2026-10-01 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-08-15']);
    seedMonthlyRunWeekly('2026-09-29');
    seedMonthlyRunCutoffs('2026-09-25', '2026-09-30');
    seedMonthlyRunNightly('2026-10-01');

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([]);

    $alert = AuditLog::where('action', NightlyRunAlert::ACTION_MONTH_DEFERRED)->sole();
    expect($alert->details['cause'])->toBe('coverage');
    expect($alert->details['missing_days'])->toBe(24);

    // Same night, same month, same cause — one row, however many times the run
    // is re-attempted.
    Artisan::call('compensation:monthly-run');

    expect(AuditLog::where('action', NightlyRunAlert::ACTION_MONTH_DEFERRED)->count())->toBe(1);
});

it('writes a fresh deferred-close alert on each new night', function (): void {
    // A9. The health digest reads a seven-day window: a month deferred once, on
    // the 1st, would be out of the digest by the 9th while nobody had yet been
    // credited for it.
    $month = Carbon::parse('2026-09-01');

    NightlyRunAlert::monthCloseDeferred(Carbon::parse('2026-10-01'), $month, 3, 'coverage', 'three days missing');
    NightlyRunAlert::monthCloseDeferred(Carbon::parse('2026-10-01'), $month, 3, 'coverage', 'three days missing');
    NightlyRunAlert::monthCloseDeferred(Carbon::parse('2026-10-02'), $month, 3, 'coverage', 'three days missing');

    expect(AuditLog::where('action', NightlyRunAlert::ACTION_MONTH_DEFERRED)->count())->toBe(2);
});

it('records payoutDeferred once per night per month when the crediting is incomplete', function (): void {
    // Queuing a month the gate refuses would file a failed run every night for
    // something nobody can fix tonight; the engines that are missing are
    // already reported as missing in their own right.
    Carbon::setTestNow('2026-09-08 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-07-15']);
    seedMonthlyRunWeekly('2026-09-08');
    seedMonthlyRunCutoffs('2026-08-01', '2026-08-31');
    seedMonthlyRunNightly('2026-09-08');
    // August traded, so it is genuinely owed a payout — but no crediting engine
    // has succeeded for it.
    seedMonthlyRunSale('2026-08');

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe(['compensation.monthly-close']);

    $alert = AuditLog::where('action', NightlyRunAlert::ACTION_PAYOUT_DEFERRED)->sole();
    expect($alert->details['month'])->toBe('2026-08');
    expect($alert->details['engine_key'])->not->toBeNull();
});

it('does not defer a sales-free month', function (): void {
    // D1: no product sale means nothing could have been credited (hard rule 2),
    // so the month owes no crediting and the payout is not held back on it.
    Carbon::setTestNow('2026-09-08 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-07-15']);
    seedMonthlyRunWeekly('2026-09-08');
    seedMonthlyRunCutoffs('2026-08-01', '2026-08-31');
    seedMonthlyRunNightly('2026-09-08');

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(0);
    expect(AuditLog::where('action', NightlyRunAlert::ACTION_PAYOUT_DEFERRED)->count())->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([
        'compensation.monthly-close',
        'compensation.monthly-payout-close',
    ]);
});

it('does not attempt the payout after a failed close, and aborts under its own action name', function (): void {
    Carbon::setTestNow('2026-09-08 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-07-15']);
    seedMonthlyRunWeekly('2026-09-08');
    seedMonthlyRunCutoffs('2026-08-01', '2026-08-31');
    seedMonthlyRunNightly('2026-09-08');
    StubEngineStepCommand::$exitCodes['compensation.monthly-close'] = 1;

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe(['compensation.monthly-close']);
    expect(EngineRun::where('engine_key', 'compensation.monthly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_FAILED);

    $audit = AuditLog::where('action', 'compensation.monthly_run.aborted')->sole();
    expect($audit->details['stage'])->toBe('compensation.monthly-close');
});

it('records a skipped run with no reason when nothing is owed', function (): void {
    Carbon::setTestNow('2026-09-20 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-07-15']);
    seedMonthlyRunWeekly('2026-09-15');
    seedMonthlyRunCutoffs('2026-08-01', '2026-08-31');
    seedMonthlyRunNightly('2026-09-20');
    seedMonthlyRunBatch('2026-09-01');
    // August is closed already, so nothing is owed at all tonight.
    EngineRun::create([
        'engine_key' => 'compensation.monthly-close',
        'period_start' => '2026-08-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => '2026-09-01 04:00:00',
        'finished_at' => '2026-09-01 04:30:00',
    ]);

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([]);

    $run = EngineRun::where('engine_key', 'compensation.monthly-run')->sole();
    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->error)->toBe('Nothing owed tonight.');
});

it('refuses a night that has not arrived', function (): void {
    Carbon::setTestNow('2026-10-01 04:00:00');

    $exitCode = Artisan::call('compensation:monthly-run', ['--date' => '2026-10-02']);

    expect($exitCode)->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe([]);
});

it('refuses to run while a projection is standing', function (): void {
    Carbon::setTestNow('2026-10-01 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-08-15']);
    seedMonthlyRunWeekly('2026-09-29');
    seedMonthlyRunCutoffs('2026-09-01', '2026-09-30');
    seedMonthlyRunNightly('2026-10-01');

    AuditLog::create([
        'actor_id' => null,
        'action' => 'compensation.recompute_all',
        'subject_type' => 'platform',
        'subject_id' => 0,
        'details' => ['horizon' => 'projection', 'simulated_through' => '2026-10-30 23:59:59'],
    ]);

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(1);
    expect(StubEngineStepCommand::$calls)->toBe([]);
    expect(EngineRun::where('engine_key', 'compensation.monthly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_SKIPPED);
});

it('satisfies the real monthly close preflight once the nightly run has cut off the month', function (): void {
    // The nightly run and the close each count the month's completed cut-offs
    // through MonthCutoffCoverage, and the nightly run's own last cut-off is
    // what makes the close's count add up. Everywhere else in this file the
    // close is a stub, so nothing else proves the two halves actually agree.
    Carbon::setTestNow('2026-10-01 04:00:00');
    Distributor::factory()->create(['effective_date' => '2026-08-15']);
    seedMonthlyRunWeekly('2026-09-29');
    seedMonthlyRunCutoffs('2026-09-01', '2026-09-30');
    seedMonthlyRunNightly('2026-10-01');

    // The real close, over the stub — with its seven steps still stubbed, so
    // this exercises the preflight and the sequencing, not the engines.
    StubEngineStepCommand::register([
        'compensation.monthly-payout-close',
        ...MonthlyEngineCompletionGate::ENGINE_KEYS,
    ]);
    app(Kernel::class)->registerCommand(new MonthlyCloseCommand(app(EngineStatusService::class)));

    $exitCode = Artisan::call('compensation:monthly-run');

    expect($exitCode)->toBe(0);
    expect(StubEngineStepCommand::$calls)->toBe([
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
        'fortune.payout',
        'adc.bonus',
        'offers.monthly',
    ]);
    expect(EngineRun::where('engine_key', 'compensation.monthly-close')->sole()->status)
        ->toBe(EngineRun::STATUS_SUCCEEDED);
});
