<?php

declare(strict_types=1);

use App\Modules\Compensation\Listeners\RecordEngineRun;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\EngineRunService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// Laravel suppresses the Symfony→Laravel console event bridge under tests
// unless this trait opts back in; the recorder listens to exactly those events.
uses(RefreshDatabase::class, WithConsoleEvents::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('records a console run of a month engine against the requested month', function (): void {
    Feature::activate(RankBonusFeature::class);

    $exitCode = Artisan::call('rank:check-qualifications', ['--month' => '2026-05']);

    expect($exitCode)->toBe(0);

    $run = EngineRun::sole();

    expect($run->engine_key)->toBe('rank.check');
    expect($run->period_start->toDateString())->toBe('2026-05-01');
    expect($run->status)->toBe(EngineRun::STATUS_SUCCEEDED);
    expect($run->trigger)->toBe(EngineRun::TRIGGER_CONSOLE);
    expect($run->actor_id)->toBeNull();
    expect($run->chain_id)->toBeNull();
    expect($run->started_at)->not->toBeNull();
    expect($run->finished_at)->not->toBeNull();
});

it('falls back to the command own default period when no option is passed', function (): void {
    // rank:check-qualifications defaults to the CURRENT month...
    Artisan::call('rank:check-qualifications');
    // ...while gbb:monthly-run defaults to the PREVIOUS month.
    Artisan::call('gbb:monthly-run');

    $rankRun = EngineRun::where('engine_key', 'rank.check')->sole();
    $gbbRun = EngineRun::where('engine_key', 'gbb.monthly')->sole();

    expect($rankRun->period_start->toDateString())->toBe(today()->startOfMonth()->toDateString());
    expect($gbbRun->period_start->toDateString())
        ->toBe(today()->startOfMonth()->subMonthNoOverflow()->toDateString());
});

it('records a date engine against the requested day', function (): void {
    Feature::activate(GenosSalesBonusFeature::class);

    Artisan::call('gsb:daily-cutoff', ['--date' => '2026-05-04']);

    $run = EngineRun::sole();

    expect($run->engine_key)->toBe('gsb.daily-cutoff');
    expect($run->period_start->toDateString())->toBe('2026-05-04');
    expect($run->status)->toBe(EngineRun::STATUS_SUCCEEDED);
});

it('records a feature-flag-off no-op as skipped, never succeeded', function (): void {
    // Compliance-critical (2026-08-13 review): a flag-off command exits 0
    // without computing anything. Were it recorded as succeeded, the dependency
    // resolver would treat the period as computed and later freeze immutable
    // pool economics against data that was never produced.
    Artisan::call('gsb:daily-cutoff', ['--date' => '2026-05-04']);

    $run = EngineRun::sole();

    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->summary['reason'])->toBe('feature_flag_off');
});

it('captures no PII patterns in the recorded console output', function (): void {
    Feature::activate(RankBonusFeature::class);

    $context = app(EngineRunContext::class);
    $context->attribute(EngineRun::TRIGGER_MANUAL, 1, 'chain-pii');

    app(EngineRunService::class)->runOne(
        EngineRegistry::get('rank.check'),
        Carbon::parse('2026-05-01'),
        EngineRun::TRIGGER_MANUAL,
        1,
        'chain-pii',
    );

    $output = EngineRun::sole()->summary['output'] ?? '';

    // 10-digit mobiles, PAN (AAAAA9999A) and 12-digit Aadhaar must never land
    // in engine_runs.summary — it is readable by the whole admin role family.
    expect(preg_match('/\b[6-9]\d{9}\b/', $output))->toBe(0);
    expect(preg_match('/\b[A-Z]{5}\d{4}[A-Z]\b/', $output))->toBe(0);
    expect(preg_match('/\b\d{12}\b/', $output))->toBe(0);
});

it('does not record a single-distributor run, which never computes the whole day', function (): void {
    Artisan::call('gsb:daily-cutoff', ['--date' => '2026-05-04', '--distributor' => '1']);

    expect(EngineRun::count())->toBe(0);
});

it('marks a non-zero exit as failed', function (): void {
    // Driven through the console events directly: none of the ten engines can
    // be made to exit non-zero on demand, but the recorder must still close a
    // crashed run out as `failed` rather than leaving it `running` forever.
    $input = new ArrayInput(['--month' => '2026-05']);
    $output = new NullOutput;

    event(new CommandStarting('gbb:monthly-run', $input, $output));
    event(new CommandFinished('gbb:monthly-run', $input, $output, 1));

    $run = EngineRun::sole();

    expect($run->status)->toBe(EngineRun::STATUS_FAILED);
    expect($run->period_start->toDateString())->toBe('2026-05-01');
    expect($run->finished_at)->not->toBeNull();
});

it('attributes the run to the admin and chain bound on the run context', function (): void {
    $context = app(EngineRunContext::class);
    $context->attribute(EngineRun::TRIGGER_MANUAL, 42, 'chain-abc');

    Artisan::call('adc:monthly-run', ['--month' => '2026-04']);

    $run = EngineRun::sole();

    expect($run->trigger)->toBe(EngineRun::TRIGGER_MANUAL);
    expect($run->actor_id)->toBe(42);
    expect($run->chain_id)->toBe('chain-abc');
    expect($context->runId())->toBe($run->id);

    // Resetting the context returns subsequent runs to plain console runs.
    $context->reset();
    Artisan::call('adc:monthly-run', ['--month' => '2026-03']);

    expect(EngineRun::whereDate('period_start', '2026-03-01')->sole()->trigger)
        ->toBe(EngineRun::TRIGGER_CONSOLE);
});

it('ignores commands that are not compensation engines', function (): void {
    Artisan::call('inspire');

    expect(EngineRun::count())->toBe(0);
});

it('stamps ledger entries written during an engine run with that run id', function (): void {
    // Driven through the console events directly: the point under test is the
    // window between CommandStarting and CommandFinished, not any one engine.
    $recorder = app(RecordEngineRun::class);
    $input = new ArrayInput(['--month' => '2026-05']);
    $output = new NullOutput;

    $recorder->starting(new CommandStarting('rank:check-qualifications', $input, $output));

    $distributor = Distributor::factory()->create();
    $duringRun = app(WalletService::class)->credit($distributor->id, 1000, 'manual_credit');

    expect($duringRun->engine_run_id)->toBe(EngineRun::sole()->id);

    $recorder->finished(new CommandFinished('rank:check-qualifications', $input, $output, 0));

    $afterRun = app(WalletService::class)->credit($distributor->id, 1000, 'manual_credit');

    // Nothing written after the run belongs to it — the recompute replay places
    // orders between runs in the same process.
    expect($afterRun->engine_run_id)->toBeNull();
    expect(WalletLedgerEntry::whereNotNull('engine_run_id')->count())->toBe(1);

    // finalise() reads runId() after Artisan::call has already returned, so
    // CommandFinished must not clear it — only the active run is cleared.
    expect(app(EngineRunContext::class)->runId())->toBe(EngineRun::sole()->id);
});

it('restores the outer run id when a nested command finishes', function (): void {
    $recorder = app(RecordEngineRun::class);
    $context = app(EngineRunContext::class);
    $input = new ArrayInput(['--month' => '2026-05']);
    $output = new NullOutput;

    $recorder->starting(new CommandStarting('rank:check-qualifications', $input, $output));
    $outerId = EngineRun::where('engine_key', 'rank.check')->sole()->id;

    $recorder->starting(new CommandStarting('gbb:monthly-run', $input, $output));
    $innerId = EngineRun::where('engine_key', 'gbb.monthly')->sole()->id;

    expect($context->activeRunId())->toBe($innerId);

    $recorder->finished(new CommandFinished('gbb:monthly-run', $input, $output, 0));

    expect($context->activeRunId())->toBe($outerId);

    $recorder->finished(new CommandFinished('rank:check-qualifications', $input, $output, 0));

    expect($context->activeRunId())->toBeNull();
    // endRun() never touches runId: it stays the last run *started* — the inner
    // one here — so EngineRunService::finalise() can still find its row.
    expect($context->runId())->toBe($innerId);
});
