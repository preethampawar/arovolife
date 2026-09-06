<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compliance\Models\AuditLog;
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
use Illuminate\Support\Sleep;
use Laravel\Pennant\Feature;

// The recorder — and therefore the resume logic, which reads the rows it writes
// — depends on the Symfony→Laravel console event bridge that Laravel suppresses
// under tests unless this trait opts back in.
uses(RefreshDatabase::class, WithConsoleEvents::class);

/**
 * A stand-in for one crediting engine: same artisan name, same period option,
 * so RecordEngineRun still writes its `engine_runs` row, but it records the call
 * and returns whatever exit code the test asks for.
 *
 * Replacing the real commands is what makes the ORDER observable. Running the
 * eight genuine engines would prove only that nothing threw.
 */
final class StubEngineCommand extends Command
{
    /** @var list<string> Engine keys, in the order they were invoked. */
    public static array $calls = [];

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

        return self::$exitCodes[$this->engineKey] ?? self::SUCCESS;
    }
}

/** Swap every crediting engine — plus the payout batch — for a recording stub. */
function stubCreditingEngines(): void
{
    StubEngineCommand::$calls = [];
    StubEngineCommand::$exitCodes = [];

    $keys = [...MonthlyEngineCompletionGate::ENGINE_KEYS, 'payout.monthly'];

    foreach ($keys as $key) {
        $definition = EngineRegistry::get($key);

        app(Kernel::class)->registerCommand(new StubEngineCommand(
            $key,
            sprintf('%s {%s=}', $definition->commandSignature, $definition->periodOption),
        ));
    }
}

/** Every flag the close's steps sit behind, so their runs record as real outcomes. */
function activateCompensationFeatures(): void
{
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
}

/**
 * The preflight waits for the closed month's last daily cut-off. A succeeded run
 * for that day is exactly the proof it looks for.
 */
function seedClosedCutoff(Carbon $month): void
{
    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => $month->copy()->endOfMonth()->startOfDay(),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => $month->copy()->addMonthNoOverflow()->startOfMonth()->setTime(0, 10),
        'finished_at' => $month->copy()->addMonthNoOverflow()->startOfMonth()->setTime(0, 15),
    ]);
}

beforeEach(function (): void {
    disableTestForeignKeys();
    activateCompensationFeatures();
    stubCreditingEngines();
    seedClosedCutoff(Carbon::parse('2026-08-01'));
});

it('runs the eight crediting engines in the declared order', function (): void {
    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);
    expect(StubEngineCommand::$calls)->toBe([
        'repurchase.snapshot',
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
        'adc.bonus',
        'fortune.payout',
        'offers.monthly',
    ]);
});

it('passes every step the closed month itself', function (): void {
    Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    foreach (MonthlyEngineCompletionGate::ENGINE_KEYS as $key) {
        expect(EngineRun::where('engine_key', $key)->sole()->period_start->toDateString())
            ->toBe('2026-08-01', "Step [{$key}] was recorded against the wrong period.");
    }
});

it('records every step against the same period the scheduler would', function (): void {
    // The resume check reads `period_start` back, so the close and the schedule
    // must agree on what a month's period IS. They did not for the repurchase
    // snapshot: the close passed the month's LAST day and the scheduler its
    // FIRST, so step 1 could never match a scheduled run and re-ran every time.
    Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    // The day the schedule fires for August: 1 September.
    $scheduledOn = Carbon::parse('2026-09-01');

    foreach (MonthlyEngineCompletionGate::ENGINE_KEYS as $key) {
        $definition = EngineRegistry::get($key);

        expect(EngineRun::where('engine_key', $key)->sole()->period_start->toDateString())
            ->toBe(
                $definition->periodStart($definition->periodRelativeTo($scheduledOn))->toDateString(),
                "Step [{$key}] records a different period than its own schedule does."
            );
    }
});

it('resumes past step 1 when a scheduled run already froze the month', function (): void {
    // Exactly the row the 00:06 scheduled snapshot leaves behind.
    EngineRun::create([
        'engine_key' => 'repurchase.snapshot',
        'period_start' => Carbon::parse('2026-08-01'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-01 00:06:00'),
        'finished_at' => Carbon::parse('2026-09-01 00:06:10'),
    ]);

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);
    expect(StubEngineCommand::$calls)->not->toContain('repurchase.snapshot');
    expect(EngineRun::where('engine_key', 'repurchase.snapshot')->count())->toBe(1);
});

it('aborts at the first non-zero exit and never reaches the later steps', function (): void {
    StubEngineCommand::$exitCodes = ['fortune.enroll' => Command::FAILURE];

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(StubEngineCommand::$calls)->toBe([
        'repurchase.snapshot',
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
    ]);
    expect(EngineRun::where('engine_key', 'adc.bonus')->exists())->toBeFalse();
});

it('records an engine_runs row for the close itself and for every nested step', function (): void {
    // The whole design rests on this: the steps are invoked with Artisan::call
    // from inside a running command, and RecordEngineRun listens to console
    // events. If nested invocations did not fire them, the resume logic would
    // have nothing to read and every re-run would restart from step 1.
    Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    $closeRun = EngineRun::where('engine_key', 'compensation.monthly-close')->firstOrFail();
    expect($closeRun->status)->toBe(EngineRun::STATUS_SUCCEEDED);
    expect($closeRun->period_start->toDateString())->toBe('2026-08-01');

    foreach (MonthlyEngineCompletionGate::ENGINE_KEYS as $key) {
        expect(EngineRun::where('engine_key', $key)->where('status', EngineRun::STATUS_SUCCEEDED)->exists())
            ->toBeTrue("No succeeded run recorded for [{$key}].");
    }
});

it('resumes at the failed step and leaves the rows the earlier steps wrote untouched', function (): void {
    StubEngineCommand::$exitCodes = ['fortune.enroll' => Command::FAILURE];
    Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    $before = EngineRun::query()
        ->whereIn('engine_key', ['repurchase.snapshot', 'rank.check', 'rank.bonus', 'gbb.monthly'])
        ->orderBy('id')
        ->get()
        ->map(fn (EngineRun $run): array => [
            $run->id,
            $run->engine_key,
            $run->status,
            $run->period_start->toDateString(),
            $run->started_at->toDateTimeString(),
            $run->finished_at?->toDateTimeString(),
        ])
        ->all();

    expect($before)->toHaveCount(4);

    StubEngineCommand::$calls = [];
    StubEngineCommand::$exitCodes = [];

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);

    // Steps 1–4 were not invoked a second time…
    expect(StubEngineCommand::$calls)->toBe([
        'fortune.enroll',
        'adc.bonus',
        'fortune.payout',
        'offers.monthly',
    ]);

    // …and their rows are byte-identical to what the first run left behind.
    $after = EngineRun::query()
        ->whereIn('engine_key', ['repurchase.snapshot', 'rank.check', 'rank.bonus', 'gbb.monthly'])
        ->orderBy('id')
        ->get()
        ->map(fn (EngineRun $run): array => [
            $run->id,
            $run->engine_key,
            $run->status,
            $run->period_start->toDateString(),
            $run->started_at->toDateTimeString(),
            $run->finished_at?->toDateTimeString(),
        ])
        ->all();

    expect($after)->toBe($before);
});

it('--restart forces the full sequence even when every step already succeeded', function (): void {
    Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    StubEngineCommand::$calls = [];

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08', '--restart' => true]);

    expect($exitCode)->toBe(0);
    expect(StubEngineCommand::$calls)->toBe([
        'repurchase.snapshot',
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
        'adc.bonus',
        'fortune.payout',
        'offers.monthly',
    ]);
});

it('aborts with a log-and-audit trail when the closed month has no daily cut-off', function (): void {
    Sleep::fake();
    EngineRun::where('engine_key', 'gsb.daily-cutoff')->delete();

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(StubEngineCommand::$calls)->toBe([]);

    $audit = AuditLog::where('action', 'compensation.monthly_close.aborted')->sole();
    expect($audit->details['month'])->toBe('2026-08');
    expect($audit->details['stage'])->toBe('preflight');
    expect($audit->details['reason'])->toContain('gsb:daily-cutoff --date=2026-08-31');
});

it('writes an audit entry naming the step that failed', function (): void {
    StubEngineCommand::$exitCodes = ['rank.bonus' => Command::FAILURE];

    Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    $audit = AuditLog::where('action', 'compensation.monthly_close.aborted')->sole();
    expect($audit->details['stage'])->toBe('rank.bonus');
    expect($audit->details['reason'])->toContain('compensation:monthly-close --month=2026-08');
});

it('rejects a malformed month without invoking anything', function (): void {
    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-8']);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(StubEngineCommand::$calls)->toBe([]);
});
