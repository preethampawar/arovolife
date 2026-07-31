<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
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
    // Seed the settings by hand rather than via down(): its ALTER would
    // implicitly commit the surrounding test transaction on MySQL, leaving
    // nothing for the rollback under test to roll back.
    foreach (RETIRED_LADDER as $key => $value) {
        DB::table('settings')->insert(['key' => $key, 'value' => $value]);
    }

    // Blow up on the audit insert the way the bad column list did on staging.
    // A listener rather than a broken schema: DDL implicitly commits on MySQL,
    // which would both defeat the test and leak the change out of it.
    DB::listen(static function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'audit_log')) {
            throw new RuntimeException('audit insert failed');
        }
    });

    expect(static fn () => dropMsbScoreValueMigration()->up())->toThrow(RuntimeException::class);

    // The settings survive because the delete shares the audit row's transaction.
    expect(DB::table('settings')->whereIn('key', array_keys(RETIRED_LADDER))->count())->toBe(3);
    expect(DB::table('audit_log')->where('action', 'settings.migration_delete')->count())->toBe(0);
});
