<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Laravel\Pennant\Feature;

/**
 * Single source of truth for the distributor-facing business navigation: the
 * tab bar rendered on My Business and every income page
 * (resources/views/income/_tabs.blade.php), so a bonus hidden behind a
 * feature flag can never appear on one surface and not another.
 */
final class IncomeNavLinks
{
    /**
     * Every business/income page the signed-in distributor may currently
     * open, in menu order, with flag-gated bonus pages removed.
     *
     * @return list<array{route: string, label: string}>
     */
    public static function visible(): array
    {
        $links = [
            ['route' => 'my-business', 'label' => 'My Business', 'visible' => true],
            ['route' => 'income.dashboard', 'label' => 'Income', 'visible' => true],
            ['route' => 'income.genos-bv', 'label' => 'Genos BV', 'visible' => true],
            ['route' => 'income.genos-ledger', 'label' => 'Genos Ledger', 'visible' => true],
            ['route' => 'income.gsb-history', 'label' => 'GSB History', 'visible' => true],
            ['route' => 'income.mentorship', 'label' => 'Mentorship', 'visible' => Feature::for(null)->active(MentorshipBonusFeature::class)],
            ['route' => 'income.growth-booster', 'label' => 'Growth Booster', 'visible' => Feature::for(null)->active(GrowthBoosterBonusFeature::class)],
            ['route' => 'income.rank-bonus', 'label' => 'Rank Bonus', 'visible' => Feature::for(null)->active(RankBonusFeature::class)],
            ['route' => 'income.fortune-bonus', 'label' => 'Fortune Bonus', 'visible' => Feature::for(null)->active(FortuneBonusFeature::class)],
            ['route' => 'income.adc-bonus', 'label' => 'ADC Bonus', 'visible' => Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class)],
            ['route' => 'income.wallet', 'label' => 'Wallet & Payouts', 'visible' => true],
        ];

        $visible = [];

        foreach ($links as $link) {
            if ($link['visible']) {
                $visible[] = [
                    'route' => $link['route'],
                    'label' => $link['label'],
                ];
            }
        }

        return $visible;
    }
}
