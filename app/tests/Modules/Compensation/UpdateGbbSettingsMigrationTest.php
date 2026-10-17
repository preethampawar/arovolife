<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function updateGbbSettingsMigration(): mixed
{
    return require base_path(
        'app/Modules/Compensation/Database/Migrations/2026_10_09_100300_update_gbb_settings_and_add_point_value_cap.php'
    );
}

/**
 * Re-run only the settings half: RefreshDatabase already ran the migration
 * once (adding the columns), so drop them first and let up() add them again.
 */
function runUpdateGbbSettings(): void
{
    Schema::table('gbb_monthly_pools', function ($table): void {
        $table->dropColumn(['raw_point_value_paise', 'point_value_cap_paise']);
    });

    updateGbbSettingsMigration()->up();
}

function gbbSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], [
        'value' => $value, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * The audit row of the run the test itself made — the latest one.
 *
 * @return array<string, mixed>
 */
function updateGbbSettingsAuditDetails(): array
{
    $row = AuditLog::query()
        ->where('action', 'plan.migration.update_gbb_settings_and_add_point_value_cap')
        ->orderByDesc('id')
        ->firstOrFail();

    return (array) $row->details;
}

it('moves a pool rate still at the old 5% default to 4% and records moved', function (): void {
    gbbSetting('comp.gbb.pool_rate_bp', '500');

    runUpdateGbbSettings();

    expect(DB::table('settings')->where('key', 'comp.gbb.pool_rate_bp')->value('value'))->toBe('400');
    expect(updateGbbSettingsAuditDetails()['pool_rate'])->toEqual([
        'key' => 'comp.gbb.pool_rate_bp', 'moved' => true, 'before' => '500', 'value' => '400',
    ]);
});

it('keeps an admin-overridden pool rate and records that it was not moved', function (): void {
    gbbSetting('comp.gbb.pool_rate_bp', '450');

    runUpdateGbbSettings();

    expect(DB::table('settings')->where('key', 'comp.gbb.pool_rate_bp')->value('value'))->toBe('450');
    expect(updateGbbSettingsAuditDetails()['pool_rate'])->toEqual([
        'key' => 'comp.gbb.pool_rate_bp', 'moved' => false, 'before' => '450', 'value' => '450',
    ]);
});

it('deletes the retired AGP cap row and records its value first', function (): void {
    gbbSetting('comp.gbb.agp_cap', '150');

    runUpdateGbbSettings();

    expect(DB::table('settings')->where('key', 'comp.gbb.agp_cap')->exists())->toBeFalse();
    expect(updateGbbSettingsAuditDetails()['agp_cap'])->toEqual([
        'key' => 'comp.gbb.agp_cap', 'existed' => true, 'value' => '150',
    ]);
});

it('writes exactly one audit row per run and adds the two pool columns', function (): void {
    $before = AuditLog::where('action', 'plan.migration.update_gbb_settings_and_add_point_value_cap')->count();

    runUpdateGbbSettings();

    expect(AuditLog::where('action', 'plan.migration.update_gbb_settings_and_add_point_value_cap')->count())->toBe($before + 1);
    expect(updateGbbSettingsAuditDetails()['agp_cap']['existed'])->toBeFalse();
    expect(Schema::hasColumns('gbb_monthly_pools', ['raw_point_value_paise', 'point_value_cap_paise']))->toBeTrue();
});

it('refuses to guess on rollback', function (): void {
    expect(fn () => updateGbbSettingsMigration()->down())
        ->toThrow(RuntimeException::class, 'restore from the plan.migration.* audit row');
});
