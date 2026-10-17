<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runFortuneTierGatesMigration(): void
{
    $migration = require base_path('app/Modules/Compensation/Database/Migrations/2026_10_17_110000_update_fortune_rank_tier_gates.php');
    $migration->up();
}

function setFortuneTier(string $tier, int $bvPaise, int $slabs): void
{
    DB::table('fortune_bonus_tiers')->updateOrInsert(
        ['tier' => $tier],
        ['bv_required_paise' => $bvPaise, 'slabs_required' => $slabs, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
    );
}

it('moves ranked Fortune tiers still on the June gates to the published plan and audits it', function (): void {
    DB::table('fortune_bonus_tiers')->delete();
    foreach (['rank_1' => 7, 'rank_2' => 10, 'rank_3' => 13, 'rank_4' => 16, 'rank_5' => 19] as $tier => $slabs) {
        setFortuneTier($tier, 100_000, $slabs);
    }

    runFortuneTierGatesMigration();

    $gates = DB::table('fortune_bonus_tiers')->orderBy('tier')->get(['tier', 'bv_required_paise', 'slabs_required'])
        ->mapWithKeys(fn ($row) => [$row->tier => [(int) $row->bv_required_paise, (int) $row->slabs_required]])
        ->all();

    expect($gates)->toBe([
        'rank_1' => [100_000, 8],
        'rank_2' => [110_000, 11],
        'rank_3' => [120_000, 14],
        'rank_4' => [130_000, 17],
        'rank_5' => [140_000, 20],
    ]);

    $details = json_decode((string) DB::table('audit_log')->where('action', 'plan.migration.update_fortune_rank_tier_gates')->latest('id')->value('details'), true);
    expect($details['tiers']['rank_2'])->toMatchArray(['moved' => true, 'before' => ['bv_required_paise' => 100_000, 'slabs_required' => 10]]);
});

it('keeps a ranked tier an admin has edited', function (): void {
    DB::table('fortune_bonus_tiers')->delete();
    setFortuneTier('rank_3', 125_000, 15);

    runFortuneTierGatesMigration();

    $row = DB::table('fortune_bonus_tiers')->where('tier', 'rank_3')->first();
    expect((int) $row->bv_required_paise)->toBe(125_000)->and((int) $row->slabs_required)->toBe(15);

    $details = json_decode((string) DB::table('audit_log')->where('action', 'plan.migration.update_fortune_rank_tier_gates')->latest('id')->value('details'), true);
    expect($details['tiers']['rank_3']['moved'])->toBeFalse();
});
