<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function narrowLegacyHeldStatusesMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_101200_narrow_legacy_repurchase_held_statuses.php'
    );
}

/** The MySQL column type of a status column, e.g. "enum('pending',…)"; null on other drivers. */
function legacyStatusColumnType(string $table): ?string
{
    if (DB::getDriverName() !== 'mysql') {
        return null;
    }

    return (string) DB::selectOne("SHOW COLUMNS FROM {$table} LIKE 'status'")->Type;
}

it('refuses to narrow while a gsb row still carries repurchase_held, and changes nothing', function (): void {
    // RefreshDatabase already narrowed the enums; widen them so a legacy row can exist.
    narrowLegacyHeldStatusesMigration()->down();
    $gsbBefore = legacyStatusColumnType('gsb_cutoff_results');
    $gbbBefore = legacyStatusColumnType('gbb_monthly_results');

    $id = DB::table('gsb_cutoff_results')->insertGetId([
        'distributor_id' => 990_001, 'cutoff_date' => '2026-08-01', 'status' => 'repurchase_held',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        expect(fn () => narrowLegacyHeldStatusesMigration()->up())
            ->toThrow(RuntimeException::class, 'Refusing to narrow status enums: 1 gsb_cutoff_results and 0 gbb_monthly_results rows');

        expect(legacyStatusColumnType('gsb_cutoff_results'))->toBe($gsbBefore)
            ->and(legacyStatusColumnType('gbb_monthly_results'))->toBe($gbbBefore)
            ->and(DB::table('gsb_cutoff_results')->where('id', $id)->value('status'))->toBe('repurchase_held');
    } finally {
        DB::table('gsb_cutoff_results')->where('id', $id)->delete();
        narrowLegacyHeldStatusesMigration()->up();
    }
});

it('refuses to narrow while a gbb row still carries repurchase_suspended', function (): void {
    narrowLegacyHeldStatusesMigration()->down();

    $id = DB::table('gbb_monthly_results')->insertGetId([
        'distributor_id' => 990_002, 'year_month' => '2026-08-01', 'status' => 'repurchase_suspended',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        expect(fn () => narrowLegacyHeldStatusesMigration()->up())
            ->toThrow(RuntimeException::class, '0 gsb_cutoff_results and 1 gbb_monthly_results rows');
    } finally {
        DB::table('gbb_monthly_results')->where('id', $id)->delete();
        narrowLegacyHeldStatusesMigration()->up();
    }
});

it('narrows both enums when no legacy row exists and records the run', function (): void {
    narrowLegacyHeldStatusesMigration()->down();

    narrowLegacyHeldStatusesMigration()->up();

    if (DB::getDriverName() === 'mysql') {
        expect(legacyStatusColumnType('gsb_cutoff_results'))
            ->toBe("enum('no_match','calculated','credited','failed','frozen','below_600bv','reversed','repurchase_forfeited')")
            ->and(legacyStatusColumnType('gbb_monthly_results'))
            ->toBe("enum('pending','credited','reversed','repurchase_wallet_blocked','repurchase_failed_blocked')");
    }

    $audit = (array) AuditLog::query()
        ->where('action', 'plan.migration.narrow_legacy_repurchase_held_statuses')
        ->orderByDesc('id')
        ->firstOrFail()
        ->details;

    expect($audit['gsb_cutoff_results'])->toBe(['removed' => ['repurchase_held', 'repurchase_suspended'], 'rows_carrying' => 0])
        ->and($audit['gbb_monthly_results'])->toBe(['removed' => ['repurchase_held', 'repurchase_suspended'], 'rows_carrying' => 0]);
});
