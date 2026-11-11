<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The global Modules/Compensation beforeEach (tests/Pest.php) already runs
// seedCompensationPlanTables(), which seeds the rank_tiers award amounts — so
// no local setup is needed here. Since 2026-11-10 a rank's award is one cash
// amount (rank_tiers.lifetime_award_budget_paise); the tranche split and the
// merchandise catalogue are retired.

it('exposes each rank\'s cash award amount via the SSOT', function (): void {
    $plan = app(CompensationPlanSettingsService::class);

    expect($plan->lifetimeAwardBudgetPaise(1))->toBe(1_540_000)
        ->and($plan->lifetimeAwardBudgetPaise(9))->toBe(6_874_740_000);

    foreach (range(1, 9) as $rank) {
        expect($plan->lifetimeAwardBudgetPaise($rank))->toBeGreaterThan(0);
    }
});

it('returns ₹0 for a rank with no award rather than erroring', function (): void {
    expect(app(CompensationPlanSettingsService::class)->lifetimeAwardBudgetPaise(99))->toBe(0);
});
