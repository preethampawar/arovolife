<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\IncomeEligibilityService;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** A cycle row exactly as the engine wrote it before the 2026-09-06 rules. */
function legacyCycle(int $distributorId, string $status, string $start, string $due): int
{
    return (int) DB::table('repurchase_cycles')->insertGetId([
        'distributor_id' => $distributorId,
        'cycle_start_date' => $start,
        'due_date' => $due,
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => $status === 'completed' ? 60_000 : 0,
        'status' => $status,
        'completed_at' => $status === 'completed' ? $due.' 00:05:00' : null,
        'created_at' => $start.' 00:05:00',
        'updated_at' => $due.' 00:05:00',
    ]);
}

function runVerdictBackfill(): void
{
    $migration = require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_09_06_100003_backfill_verdicts_on_existing_repurchase_cycles.php'
    );

    $migration->up();
}

it('stamps a legacy completed cycle as fulfilled on its own due date', function (): void {
    $dist = Distributor::factory()->create();
    $id = legacyCycle($dist->id, 'completed', '2026-05-01', '2026-05-31');

    runVerdictBackfill();

    $cycle = RepurchaseCycle::findOrFail($id);

    expect($cycle->fulfilled_on->toDateString())->toBe('2026-05-31')
        ->and($cycle->fulfilledOnTime())->toBeTrue()
        ->and($cycle->resolved_at)->not->toBeNull()
        ->and($cycle->failure_reason)->toBeNull()
        // Never guessed: the old engine did not measure it.
        ->and($cycle->wallet_zeroed)->toBeNull();
});

it('leaves a legacy completed cycle eligible for every date after it closed', function (): void {
    // Without the backfill, a null fulfilled_on would read as "held" forever.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    legacyCycle($dist->id, 'completed', '2026-05-01', '2026-05-31');

    runVerdictBackfill();

    expect(app(IncomeEligibilityService::class)
        ->verdictAsOf($dist->id, Carbon::parse('2026-06-15'))->isEligible())
        ->toBeTrue();
});

it('records a legacy lapsed cycle as a BV shortfall, not a wallet failure', function (): void {
    $dist = Distributor::factory()->create();
    $id = legacyCycle($dist->id, 'suspended', '2026-05-01', '2026-05-31');

    runVerdictBackfill();

    $cycle = RepurchaseCycle::findOrFail($id);

    expect($cycle->failure_reason)->toBe(RepurchaseCycle::REASON_BV_SHORT)
        ->and($cycle->fulfilled_on)->toBeNull()
        ->and($cycle->resolved_at)->not->toBeNull();
});

it('stops the engine re-judging a closed legacy window under the new wallet rule', function (): void {
    // The distributor met the old BV-only obligation. Re-resolving the window
    // today would apply a wallet condition that did not exist while it was
    // open — the backfill's resolved_at is what prevents that.
    $dist = Distributor::factory()->create();
    $id = legacyCycle($dist->id, 'completed', '2026-05-01', '2026-05-31');

    runVerdictBackfill();

    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $dist->id,
        'type' => 'repurchase_deduction',
        'amount_paise' => 50_000,
        'reference_type' => 'test',
        'reference_id' => 1,
        'memo' => 'test',
        'created_at' => '2026-05-10 09:00:00',
    ]);

    app(RepurchaseCycleService::class)->evaluate($dist->id, Carbon::parse('2026-06-10'));

    expect(RepurchaseCycle::findOrFail($id)->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);
});

it('leaves a cycle still inside its window untouched', function (): void {
    $dist = Distributor::factory()->create();
    $id = legacyCycle($dist->id, 'active', '2026-05-01', '2026-05-31');

    runVerdictBackfill();

    $cycle = RepurchaseCycle::findOrFail($id);

    expect($cycle->resolved_at)->toBeNull()
        ->and($cycle->fulfilled_on)->toBeNull()
        ->and($cycle->failure_reason)->toBeNull();
});
