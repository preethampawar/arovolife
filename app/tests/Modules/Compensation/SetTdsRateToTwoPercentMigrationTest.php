<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runTdsTwoPercentMigration(): void
{
    $migration = require base_path('app/Modules/Compensation/Database/Migrations/2026_11_11_090000_set_tds_rate_to_two_percent.php');
    $migration->up();
}

it('sets an existing TDS rate to 2% and keeps the previous value in the audit row', function (): void {
    DB::table('settings')->updateOrInsert(['key' => 'comp.tds.rate_bp'], ['value' => '500', 'updated_at' => now()]);

    runTdsTwoPercentMigration();

    expect(DB::table('settings')->where('key', 'comp.tds.rate_bp')->value('value'))->toBe('200');

    $details = json_decode((string) DB::table('audit_log')
        ->where('action', 'plan.migration.set_tds_rate_to_two_percent')
        ->latest('id')->value('details'), true);
    expect($details['settings']['comp.tds.rate_bp'])->toEqual(['moved' => true, 'before' => '500', 'value' => '200']);

    app()->forgetInstance(CompensationPlanSettingsService::class);
    expect(app(CompensationPlanSettingsService::class)->tds(100_000))->toBe(2_000);
});

it('writes nothing on a database without the setting row', function (): void {
    DB::table('settings')->where('key', 'comp.tds.rate_bp')->delete();

    runTdsTwoPercentMigration();

    expect(DB::table('settings')->where('key', 'comp.tds.rate_bp')->exists())->toBeFalse()
        ->and(DB::table('audit_log')->where('action', 'plan.migration.set_tds_rate_to_two_percent')->exists())->toBeFalse();
});

it('refuses to roll back', function (): void {
    $migration = require base_path('app/Modules/Compensation/Database/Migrations/2026_11_11_090000_set_tds_rate_to_two_percent.php');
    $migration->down();
})->throws(RuntimeException::class);
