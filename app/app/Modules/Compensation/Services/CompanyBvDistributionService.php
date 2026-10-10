<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * How a period's company BV divides across the seven bonuses at the rates
 * configured in the plan (client, 2026-10-10 — the admin "BV distribution"
 * chart).
 *
 * Company BV is the same signed bv_ledger_entries sum every pool reads
 * ({@see GsbDailyPoolService::companyBvPaiseBetween()}). Each bonus's share is
 * that BV × its configured rate — an allocation at plan rates, NOT what the
 * engines actually paid out. A bonus whose feature flag is off is left out.
 */
final class CompanyBvDistributionService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly GsbDailyPoolService $pool,
    ) {}

    public function forPeriod(Carbon $from, Carbon $to): CompanyBvDistribution
    {
        $companyBvPaise = $this->pool->companyBvPaiseBetween($from, $to);

        $bonuses = [
            [GenosSalesBonusFeature::class, 'Genos Sales Bonus', $this->plan->gsbPoolRateBp()],
            [MentorshipBonusFeature::class, 'Mentorship Bonus', $this->plan->msbPoolRateBp()],
            [GrowthBoosterBonusFeature::class, 'Growth Booster Bonus', $this->plan->gbbPoolRateBp()],
            [FortuneBonusFeature::class, 'Fortune Bonus', $this->plan->fortunePoolRateBp()],
            [RankBonusFeature::class, 'Rank Bonus', $this->plan->rankEnvelopeBp()],
            [LifetimeAwardsFeature::class, 'Awards & Rewards', $this->plan->awardsRateBp()],
            [AreteDevelopmentCenterBonusFeature::class, 'Arete Development Centre Bonus', $this->plan->adcRateBp()],
        ];

        $rows = [];
        foreach ($bonuses as $position => [$feature, $label, $rateBp]) {
            if (! Feature::for(null)->active($feature)) {
                continue;
            }
            $rows[] = new CompanyBvDistributionRow(
                number: $position + 1,
                label: $label,
                rateBp: $rateBp,
                bvPaise: intdiv($companyBvPaise * $rateBp, 10_000),
            );
        }

        return new CompanyBvDistribution($from, $to, $companyBvPaise, $rows);
    }
}
