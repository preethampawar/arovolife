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
 * Every day of the month cut off to completion — a succeeded run that started
 * after the day it processed had ended, which is what the preflight demands
 * before a month may be priced and frozen.
 */
function seedWholeMonthOfCutoffs(Carbon $month): void
{
    for ($day = $month->copy()->startOfMonth(); $day->month === $month->month; $day->addDay()) {
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

beforeEach(function (): void {
    disableTestForeignKeys();
    activateCompensationFeatures();
    stubCreditingEngines();
    seedWholeMonthOfCutoffs(Carbon::parse('2026-08-01'));
});

it('runs the eight crediting engines in the declared order', function (): void {
    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);
    expect(StubEngineCommand::$calls)->toBe([
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
        'fortune.payout',
        'adc.bonus',
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

it('resumes past step 1 when a scheduled run already completed it', function (): void {
    // Exactly the row the scheduled rank check leaves behind.
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => Carbon::parse('2026-08-01'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => Carbon::parse('2026-09-01 00:06:00'),
        'finished_at' => Carbon::parse('2026-09-01 00:06:10'),
    ]);

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);
    expect(StubEngineCommand::$calls)->not->toContain('rank.check');
    expect(EngineRun::where('engine_key', 'rank.check')->count())->toBe(1);
});

it('does not resume past a step whose succeeded run started while the month was still in flight', function (): void {
    // F05: a mid-month recompute (or a manual trigger, or a pre-deploy
    // schedule) records a SUCCEEDED run dated the month's first day. Read as
    // "already done" it made the 1st-of-next-month close skip every step and
    // leave the month priced on partial BV for good.
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => Carbon::parse('2026-08-01'),
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        'started_at' => Carbon::parse('2026-08-14 14:03:00'),
        'finished_at' => Carbon::parse('2026-08-14 14:03:10'),
    ]);

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);
    expect(StubEngineCommand::$calls)->toContain('rank.check');
});

it('aborts at the first non-zero exit and never reaches the later steps', function (): void {
    StubEngineCommand::$exitCodes = ['fortune.enroll' => Command::FAILURE];

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(StubEngineCommand::$calls)->toBe([
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
        ->whereIn('engine_key', ['rank.check', 'rank.bonus', 'gbb.monthly'])
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

    expect($before)->toHaveCount(3);

    StubEngineCommand::$calls = [];
    StubEngineCommand::$exitCodes = [];

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);

    // Steps 1–3 were not invoked a second time…
    expect(StubEngineCommand::$calls)->toBe([
        'fortune.enroll',
        'fortune.payout',
        'adc.bonus',
        'offers.monthly',
    ]);

    // …and their rows are byte-identical to what the first run left behind.
    $after = EngineRun::query()
        ->whereIn('engine_key', ['rank.check', 'rank.bonus', 'gbb.monthly'])
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
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
        'fortune.payout',
        'adc.bonus',
        'offers.monthly',
    ]);
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

it('records a broken step as a failure carrying the step message', function (): void {
    // The other half of the same rule: a step that actually broke stays a
    // failure — a refusal is a decision, a broken step is not.
    StubEngineCommand::$exitCodes = ['rank.bonus' => Command::FAILURE];

    Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    $run = EngineRun::where('engine_key', 'compensation.monthly-close')->sole();

    expect($run->status)->toBe(EngineRun::STATUS_FAILED);
    expect($run->error)->toContain('Rank Bonus');
});

it('refuses to close a month with a day that was never cut off', function (): void {
    // Every monthly engine prices the month from its cut-off results and then
    // freezes what it computed. The chain guarantees the days are in because
    // the cut-off is the step before this one — but this command is still
    // runnable by hand, and that is exactly what the chain's own "month not
    // closed" message recommends.
    EngineRun::where('engine_key', 'gsb.daily-cutoff')
        ->whereDate('period_start', '2026-08-17')
        ->delete();

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(StubEngineCommand::$calls)->toBe([]);

    $audit = AuditLog::where('action', 'compensation.monthly_close.aborted')->sole();
    expect($audit->details['stage'])->toBe('preflight');
    expect($audit->details['reason'])->toContain('1 of the 31 days in August 2026');

    // A refusal is a decision, not a breakage: recorded as failed it would be
    // reported for thirty days as an engine to re-run (F39).
    expect(EngineRun::where('engine_key', 'compensation.monthly-close')->sole()->status)
        ->toBe(EngineRun::STATUS_SKIPPED);
});

it('does not count a cut-off run inside its own day as a day that was cut off', function (): void {
    // An admin retry at noon cannot have seen the evening's sales.
    EngineRun::where('engine_key', 'gsb.daily-cutoff')
        ->whereDate('period_start', '2026-08-17')
        ->update(['started_at' => Carbon::parse('2026-08-17 12:00:00')]);

    expect(Artisan::call('compensation:monthly-close', ['--month' => '2026-08']))->toBe(Command::FAILURE);
    expect(StubEngineCommand::$calls)->toBe([]);
});

it('refuses to close a month while a daily cut-off is still running', function (): void {
    // The cut-off commits per distributor, so a close that starts beside one
    // prices the month while its last day is still being written.
    EngineRun::create([
        'engine_key' => 'gsb.daily-cutoff',
        'period_start' => Carbon::parse('2026-08-31'),
        'status' => EngineRun::STATUS_RUNNING,
        'trigger' => EngineRun::TRIGGER_MANUAL,
        // In flight means recently started, not merely unfinished: a run left
        // RUNNING for hours is reported as stuck, which has its own remedy.
        'started_at' => Carbon::now()->subMinutes(2),
    ]);

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(StubEngineCommand::$calls)->toBe([]);
    expect(AuditLog::where('action', 'compensation.monthly_close.aborted')->sole()->details['reason'])
        ->toContain('running right now');
});

it('closes a short month anyway under --force', function (): void {
    EngineRun::where('engine_key', 'gsb.daily-cutoff')
        ->whereDate('period_start', '2026-08-17')
        ->delete();

    $exitCode = Artisan::call('compensation:monthly-close', ['--month' => '2026-08', '--force' => true]);

    expect($exitCode)->toBe(0);
    expect(StubEngineCommand::$calls)->toHaveCount(7);
});

it('asks for no cut-offs at all while GSB is off', function (): void {
    // The cut-off cannot compute anything while the flag is off, so the month
    // owes none — demanding them would deadlock every close.
    Feature::for(null)->deactivate(GenosSalesBonusFeature::class);
    EngineRun::where('engine_key', 'gsb.daily-cutoff')->delete();

    expect(Artisan::call('compensation:monthly-close', ['--month' => '2026-08']))->toBe(0);
});
