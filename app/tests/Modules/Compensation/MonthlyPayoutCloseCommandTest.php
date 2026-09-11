<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\EngineStatusService;
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

uses(RefreshDatabase::class, WithConsoleEvents::class);

/** Stands in for `payout:monthly-run`, recording the batch month it was given. */
final class StubMonthlyPayoutCommand extends Command
{
    /** @var list<string> The `--month` value of each invocation. */
    public static array $calls = [];

    protected $signature = 'payout:monthly-run {--month=}';

    protected $description = 'Test stub for the monthly payout batch';

    public function handle(): int
    {
        $month = $this->option('month');
        self::$calls[] = is_string($month) ? $month : '';

        return self::SUCCESS;
    }
}

/**
 * All eight crediting engines recorded as having succeeded for the month.
 *
 * @param  list<string>  $except
 */
function seedSucceededCrediting(Carbon $month, array $except = []): void
{
    foreach (MonthlyEngineCompletionGate::ENGINE_KEYS as $key) {
        if (in_array($key, $except, true)) {
            continue;
        }

        $definition = EngineRegistry::get($key);

        EngineRun::create([
            'engine_key' => $key,
            'period_start' => MonthlyEngineCompletionGate::periodFor($definition, $month),
            'status' => EngineRun::STATUS_SUCCEEDED,
            'trigger' => EngineRun::TRIGGER_CONSOLE,
            'started_at' => $month->copy()->addMonthNoOverflow()->startOfMonth()->setTime(0, 20),
            'finished_at' => $month->copy()->addMonthNoOverflow()->startOfMonth()->setTime(0, 25),
        ]);
    }
}

/**
 * @param  array<string, mixed>|null  $summary
 */
function seedEngineRun(string $key, Carbon $month, string $status, Carbon $startedAt, ?array $summary = null): EngineRun
{
    $definition = EngineRegistry::get($key);

    return EngineRun::create([
        'engine_key' => $key,
        'period_start' => MonthlyEngineCompletionGate::periodFor($definition, $month),
        'status' => $status,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'summary' => $summary,
        'started_at' => $startedAt,
        'finished_at' => $startedAt->copy()->addMinutes(2),
    ]);
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

    StubMonthlyPayoutCommand::$calls = [];
    app(Kernel::class)->registerCommand(new StubMonthlyPayoutCommand);
});

it('pays out when every crediting engine has succeeded, dating the batch the following month', function (): void {
    seedSucceededCrediting(Carbon::parse('2026-08-01'));

    $exitCode = Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);
    // August's credits are paid in the September batch — the month the money
    // actually moves, which is what payout:monthly-run has always been given.
    expect(StubMonthlyPayoutCommand::$calls)->toBe(['2026-09']);
});

it('refuses when a crediting engine failed, naming it and the command that re-runs it', function (): void {
    seedSucceededCrediting(Carbon::parse('2026-08-01'), except: ['rank.bonus']);
    seedEngineRun('rank.bonus', Carbon::parse('2026-08-01'), EngineRun::STATUS_FAILED, Carbon::parse('2026-09-01 00:30'));

    $exitCode = Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08']);
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::FAILURE);
    expect(StubMonthlyPayoutCommand::$calls)->toBe([]);
    expect($output)->toContain('Rank Bonus');
    expect($output)->toContain('rank:monthly-run --month=2026-08');
    expect($output)->toContain('compensation:monthly-payout-close --month=2026-08');

    $audit = AuditLog::where('action', 'compensation.monthly_payout_close.refused')->sole();
    expect($audit->details['engine_key'])->toBe('rank.bonus');
    expect($audit->details['reason'])->toBe('failed');
});

it('refuses when a crediting engine never ran for the month', function (): void {
    seedSucceededCrediting(Carbon::parse('2026-08-01'), except: ['fortune.payout']);

    $exitCode = Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(StubMonthlyPayoutCommand::$calls)->toBe([]);
    expect(AuditLog::where('action', 'compensation.monthly_payout_close.refused')->sole()->details['reason'])
        ->toBe('never_succeeded');
});

it('is not blocked by an engine whose feature flag is off', function (): void {
    Feature::for(null)->deactivate(PurchaseOffersFeature::class);

    seedSucceededCrediting(Carbon::parse('2026-08-01'), except: ['offers.monthly']);
    // A flag-off engine no-ops and is recorded SKIPPED, never SUCCEEDED.
    $skipped = seedEngineRun(
        'offers.monthly',
        Carbon::parse('2026-08-01'),
        EngineRun::STATUS_SKIPPED,
        Carbon::parse('2026-09-01 00:20'),
        ['reason' => 'feature_flag_off'],
    );

    expect($skipped->status)->toBe(EngineRun::STATUS_SKIPPED);

    $exitCode = Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08']);

    expect($exitCode)->toBe(0);
    expect(StubMonthlyPayoutCommand::$calls)->toBe(['2026-09']);
});

it('refuses, then proceeds once the failed engine has been re-run successfully', function (): void {
    // This sequence is the whole point of splitting crediting from payment: the
    // refusal has to be recoverable, not just loud.
    seedSucceededCrediting(Carbon::parse('2026-08-01'), except: ['gbb.monthly']);
    seedEngineRun('gbb.monthly', Carbon::parse('2026-08-01'), EngineRun::STATUS_FAILED, Carbon::parse('2026-09-01 00:45'));

    expect(Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08']))->toBe(Command::FAILURE);
    expect(StubMonthlyPayoutCommand::$calls)->toBe([]);

    // The operator re-runs the engine the refusal named; it succeeds.
    seedEngineRun('gbb.monthly', Carbon::parse('2026-08-01'), EngineRun::STATUS_SUCCEEDED, Carbon::parse('2026-09-02 09:00'));

    expect(Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08']))->toBe(0);
    expect(StubMonthlyPayoutCommand::$calls)->toBe(['2026-09']);
});

it('keeps refusing when the re-run failed again', function (): void {
    seedSucceededCrediting(Carbon::parse('2026-08-01'), except: ['adc.bonus']);
    seedEngineRun('adc.bonus', Carbon::parse('2026-08-01'), EngineRun::STATUS_SUCCEEDED, Carbon::parse('2026-09-01 01:15'));
    // A later failure for the same period is unresolved again.
    seedEngineRun('adc.bonus', Carbon::parse('2026-08-01'), EngineRun::STATUS_FAILED, Carbon::parse('2026-09-03 11:00'));

    expect(Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08']))->toBe(Command::FAILURE);
    expect(StubMonthlyPayoutCommand::$calls)->toBe([]);
});

it('--force pays out over an incomplete month', function (): void {
    seedSucceededCrediting(Carbon::parse('2026-08-01'), except: ['rank.check']);

    $exitCode = Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08', '--force' => true]);

    expect($exitCode)->toBe(0);
    expect(StubMonthlyPayoutCommand::$calls)->toBe(['2026-09']);
});

it('counts an unresolved failure for the admin sidebar badge and clears it on a successful re-run', function (): void {
    $status = app(EngineStatusService::class);

    expect($status->unresolvedFailureCount())->toBe(0);

    seedEngineRun('rank.bonus', Carbon::now()->startOfMonth()->subMonthNoOverflow(), EngineRun::STATUS_FAILED, Carbon::now()->subDay());
    expect($status->unresolvedFailureCount())->toBe(1);

    seedEngineRun('rank.bonus', Carbon::now()->startOfMonth()->subMonthNoOverflow(), EngineRun::STATUS_SUCCEEDED, Carbon::now());
    expect($status->unresolvedFailureCount())->toBe(0);
});

it('records the refusal as a skipped run carrying its reason', function (): void {
    // F50: the refusal recorded `failed` with `error NULL`. The engine that is
    // actually at fault is already reported as a failure in its own right; the
    // close is reporting a decision, and it must say what that decision was.
    seedSucceededCrediting(Carbon::parse('2026-08-01'), except: ['rank.bonus']);
    seedEngineRun('rank.bonus', Carbon::parse('2026-08-01'), EngineRun::STATUS_FAILED, Carbon::parse('2026-09-01 00:30'));

    Artisan::call('compensation:monthly-payout-close', ['--month' => '2026-08']);

    $run = EngineRun::where('engine_key', 'compensation.monthly-payout-close')->sole();

    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->error)->toContain('Rank Bonus');
    expect($run->summary['reason'])->toContain('Rank Bonus');
});
