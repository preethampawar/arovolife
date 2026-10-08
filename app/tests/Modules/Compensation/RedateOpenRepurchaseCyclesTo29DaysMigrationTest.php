<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** A cycle row with an explicit window and verdict state. */
function cycleRowForRedate29(int $distributorId, string $start, string $due, ?string $resolvedAt = null): int
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

function redateTo29Migration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_100000_redate_open_repurchase_cycles_to_29_days.php'
    );
}

function runRedateTo29(): void
{
    redateTo29Migration()->up();
}

/**
 * The audit row of the run the test itself made — the latest one, because
 * RefreshDatabase already ran this migration once over an empty table.
 *
 * @return array<string, mixed>
 */
function redateTo29AuditDetails(): array
{
    $row = AuditLog::query()
        ->where('action', 'plan.migration.redate_open_repurchase_cycles_to_29_days')
        ->orderByDesc('id')
        ->firstOrFail();

    return (array) $row->details;
}

it('pulls an open start + 30 window back to start + 29', function (): void {
    $dist = Distributor::factory()->create();
    $id = cycleRowForRedate29($dist->id, '2026-07-07', '2026-08-06');

    runRedateTo29();

    expect(RepurchaseCycle::findOrFail($id)->due_date->toDateString())->toBe('2026-08-05');
});

it('never moves a window that has already been judged', function (): void {
    $dist = Distributor::factory()->create();
    $id = cycleRowForRedate29($dist->id, '2026-07-07', '2026-08-06', '2026-08-07 00:05:00');

    runRedateTo29();

    expect(RepurchaseCycle::findOrFail($id)->due_date->toDateString())->toBe('2026-08-06');
});

it('leaves a window already on the new rule, or of any other length, alone (idempotent)', function (): void {
    $dist = Distributor::factory()->create();
    $onNewRule = cycleRowForRedate29($dist->id, '2026-09-04', '2026-10-03');
    $odd = cycleRowForRedate29(Distributor::factory()->create()->id, '2026-09-04', '2026-09-20');
    $old = cycleRowForRedate29(Distributor::factory()->create()->id, '2026-09-04', '2026-10-04');

    runRedateTo29();
    runRedateTo29();

    expect(RepurchaseCycle::findOrFail($onNewRule)->due_date->toDateString())->toBe('2026-10-03')
        ->and(RepurchaseCycle::findOrFail($odd)->due_date->toDateString())->toBe('2026-09-20')
        ->and(RepurchaseCycle::findOrFail($old)->due_date->toDateString())->toBe('2026-10-03');
});

it('writes one audit row listing every moved id with its before and after due date', function (): void {
    $dist = Distributor::factory()->create();
    $id = cycleRowForRedate29($dist->id, '2026-07-07', '2026-08-06');
    cycleRowForRedate29(Distributor::factory()->create()->id, '2026-07-07', '2026-08-06', '2026-08-07 00:05:00');

    runRedateTo29();

    $details = redateTo29AuditDetails();

    expect($details['moved_count'])->toBe(1)
        ->and($details['moved_ids'])->toBe([$id])
        // toEqual: a MySQL JSON column hands keys back in its own order.
        ->and($details['rows'])->toEqual([[
            'id' => $id,
            'distributor_id' => $dist->id,
            'before' => '2026-08-06',
            'after' => '2026-08-05',
        ]]);
});

it('lists an open cycle whose new due date is already past in now_past_due_ids (F-1)', function (): void {
    Carbon::setTestNow('2026-10-09 09:00:00');

    $past = cycleRowForRedate29(Distributor::factory()->create()->id, '2026-09-04', '2026-10-04');   // → 10-03, past
    $future = cycleRowForRedate29(Distributor::factory()->create()->id, '2026-09-20', '2026-10-20'); // → 10-19, future

    runRedateTo29();

    $details = redateTo29AuditDetails();

    expect($details['moved_count'])->toBe(2)
        ->and($details['now_past_due_ids'])->toBe([$past])
        ->and($details['now_past_due_ids'])->not->toContain($future);

    Carbon::setTestNow();
});

it('refuses to guess on rollback', function (): void {
    expect(fn () => redateTo29Migration()->down())
        ->toThrow(RuntimeException::class, 'restore from the plan.migration.* audit row');
});
