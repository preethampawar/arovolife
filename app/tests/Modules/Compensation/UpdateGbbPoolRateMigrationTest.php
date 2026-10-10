<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runGbbPoolRateMigration(): void
{
    $migration = require base_path('app/Modules/Compensation/Database/Migrations/2026_10_10_130000_update_gbb_pool_rate_to_four_percent.php');
    $migration->up();
}

function setGbbPoolRate(string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => 'comp.gbb.pool_rate_bp'], ['value' => $value, 'updated_at' => now()]);
}

it('moves a GBB pool rate still at the old 5% default to 4% and audits it', function (): void {
    setGbbPoolRate('500');

    runGbbPoolRateMigration();

    expect(DB::table('settings')->where('key', 'comp.gbb.pool_rate_bp')->value('value'))->toBe('400');
    $details = json_decode((string) DB::table('audit_log')->where('action', 'plan.migration.update_gbb_pool_rate_to_four_percent')->latest('id')->value('details'), true);
    expect($details['pool_rate'])->toMatchArray(['moved' => true, 'before' => '500', 'value' => '400']);
});

it('keeps an admin override of the GBB pool rate', function (): void {
    setGbbPoolRate('450');

    runGbbPoolRateMigration();

    expect(DB::table('settings')->where('key', 'comp.gbb.pool_rate_bp')->value('value'))->toBe('450');
    $details = json_decode((string) DB::table('audit_log')->where('action', 'plan.migration.update_gbb_pool_rate_to_four_percent')->latest('id')->value('details'), true);
    expect($details['pool_rate']['moved'])->toBeFalse();
});
