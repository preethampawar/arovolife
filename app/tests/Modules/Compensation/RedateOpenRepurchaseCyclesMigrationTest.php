<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** A cycle row with an explicit window and verdict state. */
function cycleWithWindow(int $distributorId, string $start, string $due, ?string $resolvedAt = null): int
{
    return (int) DB::table('repurchase_cycles')->insertGetId([
        'distributor_id' => $distributorId,
        'cycle_start_date' => $start,
        'due_date' => $due,
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'status' => $resolvedAt === null ? 'active' : 'completed',
        'resolved_at' => $resolvedAt,
        'created_at' => $start.' 00:05:00',
        'updated_at' => $start.' 00:05:00',
    ]);
}

function runWindowRedate(): void
{
    $migration = require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_09_11_100000_redate_open_repurchase_cycles_to_full_window.php'
    );

    $migration->up();
}

it('gives an open window the full 30 days', function (): void {
    // F22: the pre-2026-09-06 engine dated the window start + 29. Open windows
    // were never re-dated, so those distributors were judged a day early.
    $dist = Distributor::factory()->create();
    $id = cycleWithWindow($dist->id, '2026-09-04', '2026-10-03');

    runWindowRedate();

    expect(RepurchaseCycle::findOrFail($id)->due_date->toDateString())->toBe('2026-10-04');
});

it('leaves a window that already runs the full 30 days alone', function (): void {
    $dist = Distributor::factory()->create();
    $id = cycleWithWindow($dist->id, '2026-09-04', '2026-10-04');

    runWindowRedate();

    expect(RepurchaseCycle::findOrFail($id)->due_date->toDateString())->toBe('2026-10-04');
});

it('is idempotent', function (): void {
    $dist = Distributor::factory()->create();
    $id = cycleWithWindow($dist->id, '2026-09-04', '2026-10-03');

    runWindowRedate();
    runWindowRedate();

    expect(RepurchaseCycle::findOrFail($id)->due_date->toDateString())->toBe('2026-10-04');
});

it('never moves the goalposts of a window that has already been judged', function (): void {
    // A resolved cycle carries a frozen verdict measured against its own due
    // date. Re-dating it would make the freeze describe a window that no longer
    // exists.
    $dist = Distributor::factory()->create();
    $id = cycleWithWindow($dist->id, '2026-05-01', '2026-05-30', '2026-05-31 00:05:00');

    runWindowRedate();

    expect(RepurchaseCycle::findOrFail($id)->due_date->toDateString())->toBe('2026-05-30');
});

it('leaves a window of some other length alone', function (): void {
    // Only the documented off-by-one is corrected; a window that is short for
    // any other reason is a different question and needs a decision, not a
    // silent nudge.
    $dist = Distributor::factory()->create();
    $id = cycleWithWindow($dist->id, '2026-09-04', '2026-09-20');

    runWindowRedate();

    expect(RepurchaseCycle::findOrFail($id)->due_date->toDateString())->toBe('2026-09-20');
});
