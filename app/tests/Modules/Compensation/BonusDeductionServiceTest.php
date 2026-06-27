<?php

declare(strict_types=1);

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Services\BonusDeductionService;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Insert a settings override and return a deduction service reading it fresh.
 *
 * @param  array<string, string|int>  $settings
 */
function deductionsWith(array $settings = []): BonusDeductionService
{
    foreach ($settings as $key => $value) {
        DB::table('settings')->insert([
            'key' => $key, 'value' => (string) $value, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    app()->forgetInstance(CompensationPlanSettingsService::class);

    return app(BonusDeductionService::class);
}

it('rounds the admin charge and charges TDS on (gross − admin) for non-Rank bonuses', function (): void {
    // gross 33,333: admin = round(33,333 × 3%) = round(999.99) = 1,000.
    $d = deductionsWith()->for(BonusType::Gsb, 33_333);

    expect($d->adminChargePaise)->toBe(1_000);
    expect($d->tdsPaise)->toBe((int) round((33_333 - 1_000) * 0.05)); // 1,617
    expect($d->netPaise)->toBe(33_333 - 1_000 - 1_617);               // 30,716
});

it('floors the admin charge and charges TDS on gross for Rank Bonus', function (): void {
    // gross 33,333: admin = floor(999.99) = 999; TDS on full gross.
    $d = deductionsWith()->for(BonusType::Rank, 33_333);

    expect($d->adminChargePaise)->toBe(999);
    expect($d->tdsPaise)->toBe((int) round(33_333 * 0.05));           // 1,667
    expect($d->netPaise)->toBe(33_333 - 999 - 1_667);                 // 30,667
});

it('skips the admin charge when the per-bonus toggle is off', function (): void {
    $d = deductionsWith(['comp.admin_charge.applies_to_adc' => 'false'])
        ->for(BonusType::Arete, 30_000);

    expect($d->adminChargePaise)->toBe(0);
    expect($d->tdsPaise)->toBe(1_500);          // TDS on full gross
    expect($d->netPaise)->toBe(28_500);
});

it('caps the admin charge at the configured maximum', function (): void {
    // 3% of 200,000,000 = 6,000,000 → capped at ₹30,000 (3,000,000 paise).
    $d = deductionsWith()->for(BonusType::Gsb, 200_000_000);

    expect($d->adminChargePaise)->toBe(3_000_000);
});

it('never returns a negative net even if rates exceed the gross', function (): void {
    // TDS at 200% drives gross − admin − tds below zero → clamp to 0.
    $d = deductionsWith(['comp.tds.rate_bp' => '20000'])->for(BonusType::Gsb, 1_000);

    expect($d->netPaise)->toBe(0);
});
