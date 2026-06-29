<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The global Modules/Compensation beforeEach (tests/Pest.php) already runs
// seedCompensationPlanTables() — which includes LifetimeAwardRewardsSeeder +
// the rank_tiers budgets — so no local setup is needed here.

it('exposes the seeded lifetime-award catalog and budgets via the SSOT', function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->lifetimeAwardBudgetPaise(1))->toBe(1_500_000);   // ₹15,000
    expect($plan->lifetimeAwardBudgetPaise(9))->toBe(2_250_000_000); // ₹2.25Cr
    expect($plan->lifetimeAwardRewards(1))->toHaveCount(1);
    expect($plan->lifetimeAwardRewards(9))->toHaveCount(9);
    expect($plan->lifetimeAwardRewards(9)[1]['item'])->toContain('villa');
});

it("reconciles every rank's reward items to its budget (catches catalog data errors)", function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    foreach (range(1, 9) as $rank) {
        $itemsSum = array_sum(array_column($plan->lifetimeAwardRewards($rank), 'worth_paise'));
        $budget = $plan->lifetimeAwardBudgetPaise($rank);

        expect($budget)->toBeGreaterThan(0);
        expect($itemsSum)->toBe($budget); // KP: itemised worths reconcile to the budget
    }
});

it('returns an empty catalog for a rank with no rewards rather than erroring', function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->lifetimeAwardRewards(99))->toBe([]);
    expect($plan->lifetimeAwardBudgetPaise(99))->toBe(0);
});
