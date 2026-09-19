<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;

/**
 * D9 / A10 at the engine level: once finance has approved August's payout
 * batch, no monthly engine may run for August again — and between the 8th's
 * sweep and that approval nothing may be CREDITED into it either, because the
 * batch would never pick the credit up and finance would approve a batch that
 * no longer matches the ledger.
 *
 * The lock lives in FrozenPayoutGuard and is asked the same question by all
 * seven, so this file proves each one actually asks it — a guard six of seven
 * engines consult is not a lock.
 */
uses(RefreshDatabase::class, WithConsoleEvents::class);

const FROZEN_MONTH = '2026-08';

/** The monthly batch that pays August: dated the 1st of September. */
function seedAugustBatch(string $status, ?string $approvedAt, ?string $processedAt): PayoutBatch
{
    return PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_MONTHLY,
        'batch_date' => '2026-09-01',
        'earnings_through' => '2026-08-31',
        'status' => $status,
        'approved_at' => $approvedAt,
        'processed_at' => $processedAt,
    ]);
}

beforeEach(function (): void {
    disableTestForeignKeys();
    Carbon::setTestNow('2026-09-10 04:00:00');

    foreach ([
        RankBonusFeature::class,
        GrowthBoosterBonusFeature::class,
        FortuneBonusFeature::class,
        AreteDevelopmentCenterBonusFeature::class,
        PurchaseOffersFeature::class,
    ] as $feature) {
        Feature::for(null)->activate($feature);
    }
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('refuses a frozen month, records it as skipped and credits nothing', function (string $key): void {
    seedAugustBatch(PayoutBatch::STATUS_APPROVED, '2026-09-09 11:00:00', '2026-09-08 04:05:00');

    $definition = EngineRegistry::get($key);

    $exitCode = Artisan::call($definition->commandSignature, ['--month' => FROZEN_MONTH]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('August 2026 is frozen');

    // A decision, not a breakage: recorded `skipped`, so neither the Engine
    // Runs page nor the health digest reports it for thirty days as an engine
    // somebody should re-run.
    $run = EngineRun::where('engine_key', $key)->sole();
    expect($run->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect($run->error)->toContain('is frozen');

    expect(WalletLedgerEntry::count())->toBe(0);
})->with(MonthlyEngineCompletionGate::ENGINE_KEYS);

it('refuses a month whose batch is built and awaiting approval', function (string $key): void {
    // A10. Not frozen — the batch can still be rebuilt — but the sweep is over,
    // so a credit written now would never be picked up by it.
    seedAugustBatch(PayoutBatch::STATUS_PENDING, null, '2026-09-08 04:05:00');

    $definition = EngineRegistry::get($key);

    $exitCode = Artisan::call($definition->commandSignature, ['--month' => FROZEN_MONTH]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('awaits approval');
    expect(EngineRun::where('engine_key', $key)->sole()->status)->toBe(EngineRun::STATUS_SKIPPED);
    expect(WalletLedgerEntry::count())->toBe(0);
})->with(MonthlyEngineCompletionGate::ENGINE_KEYS);

it('lets the engine run for a month whose batch has not been swept', function (string $key): void {
    // A pending batch that was never processed is a row nobody has acted on:
    // the month is open, and the lock must not close it. Each engine may still
    // refuse on a gate of its own from here — what must NOT appear is this
    // guard's refusal.
    seedAugustBatch(PayoutBatch::STATUS_PENDING, null, null);

    $definition = EngineRegistry::get($key);

    Artisan::call($definition->commandSignature, ['--month' => FROZEN_MONTH]);
    $output = Artisan::output();

    expect($output)->not->toContain('is frozen');
    expect($output)->not->toContain('awaits approval');
})->with(MonthlyEngineCompletionGate::ENGINE_KEYS);

it('is not overridable by --in-flight or --force', function (): void {
    seedAugustBatch(PayoutBatch::STATUS_APPROVED, '2026-09-09 11:00:00', '2026-09-08 04:05:00');

    // `--in-flight` is the testing override for OpenMonthGuard and `--force`
    // the one for the rank-qualification gates. Neither may pass this: an
    // operator can accept a partial month, but nobody can accept paying one
    // twice.
    expect(Artisan::call('rank:monthly-run', ['--month' => FROZEN_MONTH, '--in-flight' => true]))->toBe(1);
    expect(Artisan::output())->toContain('August 2026 is frozen');

    expect(Artisan::call('rank:check-qualifications', ['--month' => FROZEN_MONTH, '--force' => true]))->toBe(1);
    expect(Artisan::output())->toContain('August 2026 is frozen');
});
