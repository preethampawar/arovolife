<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runRevenueSplitMigration(): void
{
    $migration = require base_path('app/Modules/Compensation/Database/Migrations/2026_11_10_100000_apply_client_2026_11_04_revenue_split.php');
    $migration->up();
}

function setPlanSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now()]);
}

function revenueSplitAuditDetails(): array
{
    return json_decode((string) DB::table('audit_log')
        ->where('action', 'plan.migration.apply_client_2026_11_04_revenue_split')
        ->latest('id')->value('details'), true);
}

it('moves every setting still at its previous default to the 2026-11-04 figure and audits it', function (): void {
    setPlanSetting('comp.gsb.pool_rate_bp', '4500');
    setPlanSetting('comp.gbb.pool_rate_bp', '400');
    setPlanSetting('comp.msb.pool_rate_bp', '300');
    setPlanSetting('comp.monthly_income_cap_paise', '500000000');

    runRevenueSplitMigration();

    $settings = DB::table('settings')->pluck('value', 'key');
    expect($settings['comp.gsb.pool_rate_bp'])->toBe('4200')
        ->and($settings['comp.gbb.pool_rate_bp'])->toBe('600')
        ->and($settings['comp.msb.pool_rate_bp'])->toBe('400')
        ->and($settings['comp.monthly_income_cap_paise'])->toBe('1000000000');

    $details = revenueSplitAuditDetails()['settings'];
    expect($details['comp.gsb.pool_rate_bp'])->toMatchArray(['moved' => true, 'before' => '4500', 'value' => '4200'])
        ->and($details['comp.monthly_income_cap_paise'])->toMatchArray(['moved' => true, 'before' => '500000000', 'value' => '1000000000']);

    $row = DB::table('audit_log')->where('action', 'plan.migration.apply_client_2026_11_04_revenue_split')->latest('id')->first();
    expect($row->before_hash)->not->toBe($row->after_hash)
        ->and(strlen((string) $row->after_hash))->toBe(32);
});

it('overwrites an admin override too and records the value it replaced', function (): void {
    setPlanSetting('comp.gsb.pool_rate_bp', '4400');
    setPlanSetting('comp.gbb.pool_rate_bp', '400');

    runRevenueSplitMigration();

    expect(DB::table('settings')->where('key', 'comp.gsb.pool_rate_bp')->value('value'))->toBe('4200')
        ->and(DB::table('settings')->where('key', 'comp.gbb.pool_rate_bp')->value('value'))->toBe('600');

    $details = revenueSplitAuditDetails()['settings'];
    expect($details['comp.gsb.pool_rate_bp'])->toMatchArray(['moved' => true, 'before' => '4400', 'value' => '4200'])
        ->and($details)->not->toHaveKey('comp.msb.pool_rate_bp');
});

it('writes nothing on a database with no plan settings yet', function (): void {
    runRevenueSplitMigration();

    expect(DB::table('audit_log')->where('action', 'plan.migration.apply_client_2026_11_04_revenue_split')->exists())->toBeFalse();
});
