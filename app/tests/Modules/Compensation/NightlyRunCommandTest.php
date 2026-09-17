<?php

declare(strict_types=1);

use App\Modules\Compensation\Console\Commands\MonthlyCloseCommand;
use App\Modules\Compensation\Jobs\RetryNightlyChainJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\EngineHealthService;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\NightlyRunAlert;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;

// The recorder — and therefore the resume logic, which reads the rows it writes
// — depends on the Symfony→Laravel console event bridge that Laravel suppresses
// under tests unless this trait opts back in.
uses(RefreshDatabase::class, WithConsoleEvents::class);

/**
 * A stand-in for one step of the chain: same artisan name, same period option,
 * so RecordEngineRun still writes its `engine_runs` row, but it records the call
 * and the period it was given and returns whatever exit code the test asks for.
 *
 * Replacing the real engines is what makes the ORDER observable. Running the
 * genuine ones would prove only that nothing threw.
 */
final class StubChainStepCommand extends Command
{
    /** @var list<string> Engine keys, in the order they were invoked. */
    public static array $calls = [];

    /** @var array<string, string> Engine key => the period it was passed. */
    public static array $periods = [];

    /** @var array<string, int> Engine key => exit code to return. */
    public static array $exitCodes = [];

    public function __construct(private readonly string $engineKey, string $signature)
    {
        $this->signature = $signature;
        $this->description = 'Test stub for '.$engineKey;

        parent::__construct();
    }

    public function handle(): int
    {
        self::$calls[] = $this->engineKey;
        $period = $this->option(ltrim(EngineRegistry::get($this->engineKey)->periodOption, '-'));

        self::$periods[$this->engineKey] = is_string($period) ? $period : '';

        return self::$exitCodes[$this->engineKey] ?? self::SUCCESS;
    }
}

/** Every engine the chain can fire, swapped for a recording stub. */
function stubChainSteps(): void
{
    StubChainStepCommand::$calls = [];
    StubChainStepCommand::$periods = [];
    StubChainStepCommand::$exitCodes = [];

    foreach ([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.weekly-payout',
        'compensation.monthly-close',
        'compensation.monthly-payout-close',
    ] as $key) {
        $definition = EngineRegistry::get($key);

        app(Kernel::class)->registerCommand(new StubChainStepCommand(
            $key,
            sprintf('%s {%s=}', $definition->commandSignature, $definition->periodOption),
        ));
    }
}

beforeEach(function (): void {
    disableTestForeignKeys();
    // Every flag on. A step whose flag is off records as skipped rather than
    // succeeded, which would make the resume assertions meaningless — and
    // MonthlyEngineCompletionGate treats a flag-off engine as owing nothing,
    // which would change which payout batches the chain thinks are due.
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
    stubChainSteps();
});

it('runs only the two nightly steps on an ordinary night', function (): void {
    // Wednesday 16 September 2026: not the 1st, not a Tuesday, not the 8th.
    Carbon::setTestNow('2026-09-16 00:05:00');
    // Last Tuesday was paid, which is what a running platform looks like and
    // what makes tonight an ORDINARY night: with no weekly batch behind it at
    // all the chain has no frontier to backfill from, and builds the most
    // recent Tuesday rather than assuming it was never owed.
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubChainStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);
});

it('evaluates tonight and cuts off yesterday', function (): void {
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run');

    // The evaluation is dated tonight so it has seen the whole of yesterday,
    // which is exactly what the cut-off's own guard demands; the cut-off prices
    // a day that has ended.
    expect(StubChainStepCommand::$periods['repurchase.evaluate'])->toBe('2026-09-16');
    expect(StubChainStepCommand::$periods['gsb.daily-cutoff'])->toBe('2026-09-15');
});

it('closes the month on the first night of the next one', function (): void {
    Carbon::setTestNow('2026-10-01 00:05:00');
    seedWeeklyBatch('2026-09-29');
    seedComputedCutoffs('2026-09-01', '2026-09-29');

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'compensation.monthly-close',
    ]);
    // The month that ended last night — reached the moment its last cut-off is
    // in, with no clock offset and nothing to poll for.
    expect(StubChainStepCommand::$periods['gsb.daily-cutoff'])->toBe('2026-09-30');
    expect(StubChainStepCommand::$periods['compensation.monthly-close'])->toBe('2026-09');
});

it('adds the weekly payout batch on a Tuesday', function (): void {
    // Tuesday 22 September 2026.
    Carbon::setTestNow('2026-09-22 00:05:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.weekly-payout',
    ]);
    expect(StubChainStepCommand::$periods['gsb.weekly-payout'])->toBe('2026-09-22');
});

it('adds the monthly payout close on the eighth', function (): void {
    // Thursday 8 October 2026 — the 8th, and not a Tuesday.
    Carbon::setTestNow('2026-10-08 00:05:00');
    seedWeeklyBatch('2026-10-06');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'compensation.monthly-payout-close',
    ]);
    // The crediting month that closed on the 1st: the week between is the only
    // window in which a bad month can still be caught before it reaches a bank.
    expect(StubChainStepCommand::$periods['compensation.monthly-payout-close'])->toBe('2026-09');
});

it('runs every due step when a night is the 1st and a Tuesday at once', function (): void {
    // Tuesday 1 December 2026.
    Carbon::setTestNow('2026-12-01 00:05:00');
    seedComputedCutoffs('2026-11-01', '2026-11-29');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'compensation.monthly-close',
        'gsb.weekly-payout',
    ]);
});

it('resumes past a cut-off that already succeeded', function (): void {
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-15'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-16 00:06:00'),
        'finished_at' => Carbon::parse('2026-09-16 00:09:00'),
    ]);

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubChainStepCommand::$calls)->toBe(['repurchase.evaluate']);
    expect(EngineRun::where('engine_key', 'gsb.daily-cutoff')->count())->toBe(1);
});

it('re-runs a step whose succeeded run started while its period was still in flight', function (): void {
    // A mid-day manual run or recompute leaves a succeeded row dated the same
    // day. Read as "already done" it would let the night skip the cut-off and
    // leave the day priced on partial BV for good.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedMonthlyBatch('2026-09-01');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-15'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-09-15 14:00:00'),
        'finished_at' => Carbon::parse('2026-09-15 14:02:00'),
    ]);

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toContain('gsb.daily-cutoff');
});

it('stops the chain at the first failing step and says what did not run', function (): void {
    Carbon::setTestNow('2026-10-01 00:05:00');
    seedWeeklyBatch('2026-09-29');
    seedComputedCutoffs('2026-09-01', '2026-09-29');
    StubChainStepCommand::$exitCodes['gsb.daily-cutoff'] = 1;

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(1);
    // The close never sees a half-written cut-off.
    expect(StubChainStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);

    $chain = EngineRun::where('engine_key', 'compensation.nightly-run')->sole();
    expect($chain->status)->toBe(EngineRun::STATUS_FAILED);
    expect($chain->error)->toContain('The remaining 1 step(s) did not run.');

    expect(AuditLog::where('action', 'compensation.nightly_run.aborted')->exists())->toBeTrue();
});

it('refuses a night that has not arrived', function (): void {
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedMonthlyBatch('2026-09-01');

    $exitCode = Artisan::call('compensation:nightly-run', ['--date' => '2026-09-17']);

    expect($exitCode)->toBe(1);
    expect(StubChainStepCommand::$calls)->toBe([]);
});

it('re-runs every step under --restart', function (): void {
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-15'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-16 00:06:00'),
        'finished_at' => Carbon::parse('2026-09-16 00:09:00'),
    ]);

    Artisan::call('compensation:nightly-run', ['--restart' => true]);

    expect(StubChainStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);
});

/** A weekly batch is the proof that a Tuesday was paid. */
function seedWeeklyBatch(string $tuesday): void
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
function seedMonthlyBatch(string $batchMonthStart): void
{
    PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => $batchMonthStart,
        'earnings_through' => Carbon::parse($batchMonthStart)->subMonthNoOverflow()->endOfMonth()->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
        'processed_at' => Carbon::parse($batchMonthStart)->setTime(4, 5),
    ]);
}

/** A succeeded cut-off row is what proves a day was CUT OFF TO COMPLETION. */
function seedComputedCutoffs(string $from, string $to): void
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

it('backfills every missed night, oldest first', function (): void {
    // The chain last ran on the 13th, cutting off the 12th. Three nights were
    // lost; under the old fixed --date=yesterday schedule they were lost for
    // good, and a day never cut off is a day nobody is credited for.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');
    seedComputedCutoffs('2026-09-12', '2026-09-12');

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
    ]);
    expect(
        EngineRun::where('engine_key', 'gsb.daily-cutoff')
            ->where('trigger', EngineRun::TRIGGER_CONSOLE)
            ->whereIn('status', [EngineRun::STATUS_SUCCEEDED])
            ->orderBy('id')
            ->pluck('period_start')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all()
    )->toBe(['2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15']);
});

it('heals a single missed night on the next run', function (): void {
    // The 16th never started — the 15th was still running at 00:05.
    Carbon::setTestNow('2026-09-17 00:05:00');
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');
    seedComputedCutoffs('2026-09-14', '2026-09-14');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
    ]);
});

it('leaves a gap longer than the cap to a human', function (): void {
    // Nothing proven anywhere in the window: a brand-new environment or an
    // outage of months. Replaying either unattended at 00:05 is a decision
    // nobody made, so only last night runs and the rest reads as missing.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);
    expect(StubChainStepCommand::$periods['gsb.daily-cutoff'])->toBe('2026-09-15');
});

it('closes a month the backfill completed', function (): void {
    // The 29th and 30th were missed; the chain cuts them off and only then
    // closes September.
    Carbon::setTestNow('2026-10-01 00:05:00');
    seedWeeklyBatch('2026-09-29');
    seedComputedCutoffs('2026-09-01', '2026-09-28');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
        'compensation.monthly-close',
    ]);
    expect(StubChainStepCommand::$periods['compensation.monthly-close'])->toBe('2026-09');
});

it('refuses to close a month whose days are not all cut off', function (): void {
    // Every monthly engine prices the month from its cut-off results and then
    // FREEZES what it computed. A month closed three days short is a month
    // permanently priced short — and re-running the close reuses the frozen
    // pool rather than repairing it.
    Carbon::setTestNow('2026-10-01 00:05:00');
    seedWeeklyBatch('2026-09-29');
    seedComputedCutoffs('2026-09-25', '2026-09-28');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
    ]);
    expect(StubChainStepCommand::$calls)->not->toContain('compensation.monthly-close');
});

it('records a night the scheduler skipped, and reports it in the health digest', function (): void {
    // An overlap skip starts no command: no run, no exit code, no engine_runs
    // row. The audit row is the only trace the night gets.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedMonthlyBatch('2026-09-01');

    NightlyRunAlert::skippedNight(Carbon::parse('2026-09-16'), 'still running');
    NightlyRunAlert::skippedNight(Carbon::parse('2026-09-16'), 'still running');

    expect(AuditLog::where('action', NightlyRunAlert::ACTION_SKIPPED_NIGHT)->count())->toBe(1);

    $report = app(EngineHealthService::class)->report(Carbon::now());

    expect($report->chainAlerts)->toHaveCount(1);
    expect($report->chainAlerts[0]['date'])->toBe('16 Sep 2026');
    expect($report->isHealthy())->toBeFalse();
});

it('records a gap it will not heal, because nothing else can see one', function (): void {
    // Over the cap: last night runs, the rest does not, and the chain exits 0.
    // EngineHealthService::missing() judges each engine on its most recent fire
    // — which just succeeded — so this is recorded here or nowhere.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');
    seedComputedCutoffs('2026-06-01', '2026-06-01');

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    expect(StubChainStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);

    $alert = AuditLog::where('action', NightlyRunAlert::ACTION_BACKFILL_GAP)->sole();
    expect($alert->details['date'])->toBe('2026-09-16');
    // No result rows were ever written here, so there is no older proof to name
    // — the alert says so rather than inventing one.
    expect($alert->details['last_proven_cutoff'])->toBeNull();
    expect(app(EngineHealthService::class)->report(Carbon::now())->chainAlerts)->toHaveCount(1);
});

it('records the month it would not close', function (): void {
    // Nobody receives Growth Booster, Rank Bonus, Fortune or ADC for a month
    // that is never closed, so the refusal cannot be a line on stdout.
    Carbon::setTestNow('2026-10-01 00:05:00');
    seedComputedCutoffs('2026-09-25', '2026-09-28');

    Artisan::call('compensation:nightly-run');

    $alert = AuditLog::where('action', NightlyRunAlert::ACTION_MONTH_DEFERRED)->sole();
    expect($alert->details['month'])->toBe('2026-09');
    // Four days proven (25th–28th) and two queued tonight (29th, 30th) leaves
    // twenty-four of September's thirty days with no cut-off at all.
    expect($alert->details['missing_days'])->toBe(24);
});

it('does not count a cut-off that only left result rows behind', function (): void {
    // The cut-off commits per distributor, so a run that died half way through
    // leaves rows for a day nobody finished. Counting that day as done would
    // close the month against it, and the monthly pools freeze what they price.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');
    seedComputedCutoffs('2026-09-12', '2026-09-12');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-13'),
        'status' => EngineRun::STATUS_FAILED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-14 00:06:00'),
        'finished_at' => Carbon::parse('2026-09-14 00:07:00'),
    ]);

    Artisan::call('compensation:nightly-run');

    // The 13th is still owed, so it is backfilled along with the 14th and 15th.
    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
        'gsb.daily-cutoff',
    ]);
});

it('does not count a cut-off run inside its own day', function (): void {
    // An admin retry at noon cannot have seen the evening's sales.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedWeeklyBatch('2026-09-15');
    seedMonthlyBatch('2026-09-01');
    seedComputedCutoffs('2026-09-12', '2026-09-12');

    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-09-13'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-09-13 12:00:00'),
        'finished_at' => Carbon::parse('2026-09-13 12:05:00'),
    ]);

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toHaveCount(4);
});

it('rebuilds a missed Tuesday batch the very next night, still dated that Tuesday', function (): void {
    // Wednesday. Tuesday's chain never ran, so its batch was never built.
    // Waiting for the following Tuesday would cost distributors a week for an
    // outage that had nothing to do with them.
    Carbon::setTestNow('2026-09-23 00:05:00');
    seedMonthlyBatch('2026-09-01');
    seedComputedCutoffs('2026-09-21', '2026-09-21');
    seedWeeklyBatch('2026-09-15');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
        'gsb.weekly-payout',
    ]);
    // The batch date is the Tuesday, so the week it pays is unchanged.
    expect(StubChainStepCommand::$periods['gsb.weekly-payout'])->toBe('2026-09-22');
});

it('leaves a Tuesday alone once its batch exists', function (): void {
    // A batch finance has already approved is returned unchanged by the runner,
    // which then reports FAILURE — re-invoking it would abort the whole chain
    // over a batch that is not merely fine but signed off.
    Carbon::setTestNow('2026-09-22 00:05:00');
    seedMonthlyBatch('2026-09-01');
    seedComputedCutoffs('2026-09-20', '2026-09-20');
    seedWeeklyBatch('2026-09-22');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toBe(['repurchase.evaluate', 'gsb.daily-cutoff']);
});

it('builds no payout batch for a past night unless asked', function (): void {
    // Catching up cut-offs by hand used to mean typing gsb:daily-cutoff, from
    // which a payout batch was unreachable.
    Carbon::setTestNow('2026-09-25 00:05:00');
    seedMonthlyBatch('2026-09-01');
    seedComputedCutoffs('2026-09-20', '2026-09-20');
    seedWeeklyBatch('2026-09-15');

    Artisan::call('compensation:nightly-run', ['--date' => '2026-09-22']);

    expect(StubChainStepCommand::$calls)->not->toContain('gsb.weekly-payout');

    StubChainStepCommand::$calls = [];

    Artisan::call('compensation:nightly-run', ['--date' => '2026-09-22', '--with-payouts' => true]);

    expect(StubChainStepCommand::$calls)->toContain('gsb.weekly-payout');
});

it('re-queues the monthly payout the next night when the 8th was missed', function (): void {
    // The 9th. The 8th never ran, so October's batch (September's credits) does
    // not exist. Under the old monthlyOn(8) entry the payment slipped a month.
    Carbon::setTestNow('2026-10-09 00:05:00');
    seedComputedCutoffs('2026-10-07', '2026-10-07');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toContain('compensation.monthly-payout-close');
    expect(StubChainStepCommand::$periods['compensation.monthly-payout-close'])->toBe('2026-09');
});

it('leaves the monthly payout alone once its batch exists', function (): void {
    Carbon::setTestNow('2026-10-09 00:05:00');
    seedComputedCutoffs('2026-10-07', '2026-10-07');
    seedMonthlyBatch('2026-10-01');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->not->toContain('compensation.monthly-payout-close');
});

it('does not re-queue a previous month the payout gate would refuse', function (): void {
    // Queuing a month the gate refuses would abort the chain and file a failed
    // run every night for something nobody can fix tonight. The crediting
    // engines that are missing are already reported as missing in their own
    // right.
    Carbon::setTestNow('2026-11-03 00:05:00');
    seedComputedCutoffs('2026-11-01', '2026-11-01');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->not->toContain('compensation.monthly-payout-close');
});

it('refuses to run while a projection is standing', function (): void {
    // The scheduler entry is filtered, but this command is also typed by hand —
    // it is what the abort message tells an operator to run.
    Carbon::setTestNow('2026-09-16 00:05:00');
    seedMonthlyBatch('2026-09-01');

    // The state a standing projection actually leaves behind — the audit row
    // RecomputeState reads uncached, on purpose, so a Redis eviction can never
    // answer "not projected" on this path.
    AuditLog::create([
        'actor_id' => null,
        'action' => 'compensation.recompute_all',
        'subject_type' => 'platform',
        'subject_id' => 0,
        'details' => ['horizon' => 'projection', 'simulated_through' => '2026-09-30 23:59:59'],
    ]);

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(1);
    expect(StubChainStepCommand::$calls)->toBe([]);
    expect(EngineRun::where('engine_key', 'compensation.nightly-run')->sole()->status)
        ->toBe(EngineRun::STATUS_SKIPPED);
});

it('satisfies the real monthly close preflight with its own cut-off step', function (): void {
    // The chain and the close each count the month's completed cut-offs with
    // the same arithmetic, and the chain's own cut-off step is what makes the
    // close's count add up. Everywhere else in this file the close is a stub,
    // so nothing else proves the two halves actually agree.
    Carbon::setTestNow('2026-10-01 00:05:00');
    seedWeeklyBatch('2026-09-29');
    seedComputedCutoffs('2026-09-01', '2026-09-29');

    // The real close, over the stub — with its seven steps still stubbed, so
    // this exercises the preflight and the sequencing, not the engines.
    foreach (MonthlyEngineCompletionGate::ENGINE_KEYS as $key) {
        $definition = EngineRegistry::get($key);

        app(Kernel::class)->registerCommand(new StubChainStepCommand(
            $key,
            sprintf('%s {%s=}', $definition->commandSignature, $definition->periodOption),
        ));
    }

    app(Kernel::class)->registerCommand(new MonthlyCloseCommand(app(EngineStatusService::class)));

    $exitCode = Artisan::call('compensation:nightly-run');

    expect($exitCode)->toBe(0);
    // Step 2 cut off 30 September; the close then found all thirty days and ran
    // its seven steps rather than refusing.
    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
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

/*
|--------------------------------------------------------------------------
| --without-payouts (the admin retry path)
|--------------------------------------------------------------------------
|
| The fail-closed half of the retry path, used when no actor can be recorded as
| the batch's maker. `finance.approve` is held only by `admin` and `developer`,
| never by the `admin-finance` holder of `finance.record`; for a user holding
| both, `payout_batches.created_by` is what bars them from approving their own
| batch. An unattributed run can stamp no maker, so it builds nothing at all.
*/

it('builds no weekly payout batch when the night is retried without payouts', function (): void {
    // Tuesday 22 September 2026 — the day the weekly sweep is due, and the
    // exact case isToday() would otherwise let through.
    Carbon::setTestNow('2026-09-22 09:00:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run', ['--without-payouts' => true]);

    expect(StubChainStepCommand::$calls)->toBe([
        'repurchase.evaluate',
        'gsb.daily-cutoff',
    ])->and(StubChainStepCommand::$calls)->not->toContain('gsb.weekly-payout');
});

it('builds no monthly payout close when the eighth is retried without payouts', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run', ['--without-payouts' => true]);

    expect(StubChainStepCommand::$calls)->not->toContain('compensation.monthly-payout-close');
});

it('still credits the night when payouts are excluded, because the income is the point', function (): void {
    // Excluding the sweep must not exclude the crediting engines — a retry that
    // skipped those would leave distributors uncredited, which is the opposite
    // of what the button is for.
    Carbon::setTestNow('2026-09-22 09:00:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run', ['--without-payouts' => true]);

    expect(StubChainStepCommand::$calls)->toContain('repurchase.evaluate')
        ->and(StubChainStepCommand::$calls)->toContain('gsb.daily-cutoff');
});

it('still allows the scheduler its payout steps when the switch is not passed', function (): void {
    // The guard must be opt-in only: the scheduler's own nightly run is
    // unchanged, or the weekly sweep would simply stop happening.
    Carbon::setTestNow('2026-09-22 00:05:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toContain('gsb.weekly-payout');
});

/*
|--------------------------------------------------------------------------
| The first payout Tuesday
|--------------------------------------------------------------------------
|
| Weekly batches are backfilled from the last one that EXISTS, so a Tuesday with
| no predecessor is reachable only from its own night. If that night failed, the
| batch was owed and nothing would ever build it — the one Tuesday on the
| platform that could be lost for a week.
*/

it('builds the first payout Tuesday on a later night when nothing was ever paid', function (): void {
    // Wednesday. No weekly batch has ever been built, so there is no frontier —
    // which used to mean "nothing to backfill" and cost this Tuesday a week.
    Carbon::setTestNow('2026-09-23 00:05:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run');

    expect(StubChainStepCommand::$calls)->toContain('gsb.weekly-payout')
        ->and(StubChainStepCommand::$periods['gsb.weekly-payout'])->toBe('2026-09-22');
});

it('builds one Tuesday and not a month when nothing was ever paid', function (): void {
    // Friday, four days after that first Tuesday. The guard that mattered is
    // still in force: with no frontier the chain reaches exactly the most
    // recent Tuesday, never the four behind it.
    Carbon::setTestNow('2026-09-25 00:05:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run');

    $weekly = array_filter(StubChainStepCommand::$calls, fn (string $call): bool => $call === 'gsb.weekly-payout');

    expect($weekly)->toHaveCount(1)
        ->and(StubChainStepCommand::$periods['gsb.weekly-payout'])->toBe('2026-09-22');
});

/*
|--------------------------------------------------------------------------
| --weekly-payouts-only (the admin retry path)
|--------------------------------------------------------------------------
|
| What the retry button may rebuild. A missed Tuesday is in scope because
| nothing else can reach it; the monthly payout close never is, because every
| night from the 8th rebuilds it unaided — so the largest sweep on the platform
| stays out of admin hands.
*/

it('rebuilds a missed Tuesday under --weekly-payouts-only', function (): void {
    Carbon::setTestNow('2026-09-23 09:00:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run', ['--weekly-payouts-only' => true]);

    expect(StubChainStepCommand::$calls)->toContain('gsb.weekly-payout')
        ->and(StubChainStepCommand::$periods['gsb.weekly-payout'])->toBe('2026-09-22');
});

it('never reaches the monthly payout close under --weekly-payouts-only', function (): void {
    // The 8th: the night the monthly sweep is due, and the one case where
    // letting the retry through would put an admin's hand on Groups B/C/D.
    Carbon::setTestNow('2026-10-08 09:00:00');

    Artisan::call('compensation:nightly-run', ['--weekly-payouts-only' => true]);

    expect(StubChainStepCommand::$calls)->not->toContain('compensation.monthly-payout-close');
});

it('excludes every payout when both switches are passed', function (): void {
    // Fail closed, not open: --without-payouts is read first, so a caller that
    // somehow sets both builds nothing rather than a batch.
    Carbon::setTestNow('2026-09-23 09:00:00');
    seedMonthlyBatch('2026-09-01');

    Artisan::call('compensation:nightly-run', [
        '--weekly-payouts-only' => true,
        '--without-payouts' => true,
    ]);

    expect(StubChainStepCommand::$calls)->not->toContain('gsb.weekly-payout');
});

it('lets an attributed retry rebuild the Tuesday nothing else can reach', function (): void {
    Carbon::setTestNow('2026-09-23 09:00:00');
    seedMonthlyBatch('2026-09-01');
    $admin = User::factory()->create();

    (new RetryNightlyChainJob('2026-09-23', $admin->id, 'chain-1'))->handle();

    expect(StubChainStepCommand::$calls)->toContain('gsb.weekly-payout')
        ->and(StubChainStepCommand::$periods['gsb.weekly-payout'])->toBe('2026-09-22');
});

it('carries the clicking admin into the run the weekly sweep is recorded under', function (): void {
    // The load-bearing claim of the whole retry path, and it depends on a chain
    // of three things holding at once: the job attributes EngineRunContext, the
    // context survives the nested Artisan::call into the step, and
    // PayoutService::batchCreatorId() reads it when Auth::id() is null. This
    // pins the middle link, which is the one a refactor could silently break —
    // if the context were lost, this row would name nobody and the batch would
    // be built with `created_by` NULL, approvable by whoever asked for it.
    Carbon::setTestNow('2026-09-23 09:00:00');
    seedMonthlyBatch('2026-09-01');
    $admin = User::factory()->create();

    (new RetryNightlyChainJob('2026-09-23', $admin->id, 'chain-3'))->handle();

    $sweep = EngineRun::where('engine_key', 'gsb.weekly-payout')->sole();

    expect($sweep->actor_id)->toBe($admin->id)
        ->and($sweep->trigger)->toBe(EngineRun::TRIGGER_MANUAL);
});

it('builds no batch for a retry with nobody to record as its maker', function (): void {
    // No actor means `payout_batches.created_by` would land NULL, and a batch
    // with no maker is approvable by anyone — including whoever caused it.
    Carbon::setTestNow('2026-09-23 09:00:00');
    seedMonthlyBatch('2026-09-01');

    (new RetryNightlyChainJob('2026-09-23', null, 'chain-2'))->handle();

    expect(StubChainStepCommand::$calls)->not->toContain('gsb.weekly-payout');
});
