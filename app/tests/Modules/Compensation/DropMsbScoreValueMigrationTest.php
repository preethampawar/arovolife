<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The migration that retires the Mentorship Bonus rate ladder writes an
 * audit_log row for the settings it deletes. That row is only reachable when
 * the settings actually exist, which they never do during a fresh migrate — so
 * a wrong column list in the insert survived all the way to a staging deploy.
 */
const RETIRED_LADDER = [
    'comp.mb.step_paise' => '3000000',
    'comp.mb.start_rate_pct' => '10',
    'comp.mb.floor_rate_pct' => '1',
];

function dropMsbScoreValueMigration(): object
{
    return require app_path('Modules/Compensation/Database/Migrations/2026_07_30_100001_drop_msb_score_value_from_gsb_slabs.php');
}

/** @return array<string, mixed> */
function migrationAuditRows(): array
{
    return DB::table('audit_log')
        ->where('action', 'settings.migration_delete')
        ->get()
        ->map(static fn (object $row): array => (array) json_decode((string) $row->details, true))
        ->all();
}

it('MSBM-01: deletes the ladder settings and audits the deletion', function (): void {
    $migration = dropMsbScoreValueMigration();

    // Rewind to the pre-migration state: the column back, the settings seeded.
    $migration->down();
    expect(Schema::hasColumn('gsb_slabs', 'msb_score_value_paise'))->toBeTrue();
    expect(DB::table('settings')->whereIn('key', array_keys(RETIRED_LADDER))->count())->toBe(3);

    $migration->up();

    expect(Schema::hasColumn('gsb_slabs', 'msb_score_value_paise'))->toBeFalse();
    expect(DB::table('settings')->whereIn('key', array_keys(RETIRED_LADDER))->count())->toBe(0);

    $audit = migrationAuditRows();
    expect($audit)->toHaveCount(1);
    expect($audit[0]['before'])->toEqual(RETIRED_LADDER);
    expect($audit[0]['after'])->toBeNull();
    expect($audit[0])->not->toHaveKey('before_reconstructed');
});

it('MSBM-02: resuming after a partial run still records the deletion', function (): void {
    // RefreshDatabase has already run the migration, so the column is gone and
    // the settings are absent — the exact state the failed staging run left.
    expect(Schema::hasColumn('gsb_slabs', 'msb_score_value_paise'))->toBeFalse();

    dropMsbScoreValueMigration()->up();

    $audit = migrationAuditRows();
    expect($audit)->toHaveCount(1);
    expect($audit[0]['before'])->toEqual(RETIRED_LADDER);
    expect($audit[0])->toHaveKey('before_reconstructed');
});

it('MSBM-03: a settings deletion is never committed without its audit row', function (): void {
    $migration = dropMsbScoreValueMigration();
    $migration->down();

    // audit_log is append-only — an insert naming updated_at is what broke the
    // staging deploy. Make the table reject any write and the settings must
    // survive, because the delete and the audit row share one transaction.
    Schema::table('audit_log', function ($table): void {
        $table->string('forces_failure')->nullable(false);
    });

    expect(static fn () => $migration->up())->toThrow(QueryException::class);
    expect(DB::table('settings')->whereIn('key', array_keys(RETIRED_LADDER))->count())->toBe(3);
});
