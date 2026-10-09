<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use Database\Seeders\LifetimeAwardTranchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The global Modules/Compensation beforeEach (tests/Pest.php) already runs
// seedCompensationPlanTables() — which includes LifetimeAwardTranchesSeeder,
// LifetimeAwardRewardsSeeder + the rank_tiers budgets — so no local setup is
// needed here.

it('exposes the 2026-10-09 award tranches and budgets via the SSOT', function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->lifetimeAwardBudgetPaise(1))->toBe(1_540_000)
        ->and($plan->lifetimeAwardBudgetPaise(9))->toBe(6_874_740_000)
        ->and(array_column($plan->lifetimeAwardTranches(9), 'amount_paise'))->toBe([918_630_000, 956_070_000, 5_000_040_000])
        ->and($plan->lifetimeAwardTranches(1))->toHaveCount(1)
        ->and($plan->lifetimeAwardTranches(4))->toHaveCount(2);
});

it("pins the client's whole 2026-10-09 tranche table, in paise", function (): void {
    expect(LifetimeAwardTranchesSeeder::TRANCHES)->toBe([
        1 => [1_540_000],
        2 => [3_600_000],
        3 => [4_860_000, 5_940_000],
        4 => [14_580_000, 17_820_000],
        5 => [43_740_000, 53_460_000],
        6 => [84_780_000, 93_240_000, 104_580_000],
        7 => [245_250_000, 269_730_000, 302_490_000],
        8 => [711_180_000, 782_280_000, 877_140_000],
        9 => [918_630_000, 956_070_000, 5_000_040_000],
    ]);

    $plan = app(CompensationPlanSettingsService::class);
    foreach (LifetimeAwardTranchesSeeder::TRANCHES as $rank => $amounts) {
        expect(array_column($plan->lifetimeAwardTranches($rank), 'amount_paise'))->toBe($amounts)
            ->and(array_column($plan->lifetimeAwardTranches($rank), 'tranche'))->toBe(range(1, count($amounts)));
    }
});

it("reconciles every rank's tranches and reward items to its budget", function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    foreach (range(1, 9) as $rank) {
        $budget = $plan->lifetimeAwardBudgetPaise($rank);

        expect($budget)->toBeGreaterThan(0);
        expect(array_sum(array_column($plan->lifetimeAwardTranches($rank), 'amount_paise')))->toBe($budget);
        expect(array_sum(array_column($plan->lifetimeAwardRewards($rank), 'worth_paise')))->toBe($budget);
    }
});

it('seeds one placeholder merchandise item per tranche until the client supplies the list', function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->lifetimeAwardRewards(9))->toHaveCount(3)
        ->and($plan->lifetimeAwardRewards(9)[2]['item'])->toBe('Merchandise, tranche C — items to be specified by the company')
        ->and($plan->lifetimeAwardRewards(9)[2]['worth_paise'])->toBe(5_000_040_000);
});

it('returns an empty catalog for a rank with no rewards rather than erroring', function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->lifetimeAwardRewards(99))->toBe([]);
    expect($plan->lifetimeAwardTranches(99))->toBe([]);
    expect($plan->lifetimeAwardBudgetPaise(99))->toBe(0);
});
