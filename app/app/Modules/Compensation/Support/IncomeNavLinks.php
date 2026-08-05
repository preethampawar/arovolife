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
 * Single source of truth for the distributor-facing income navigation: the
 * "My Income" tab strip (resources/views/income/_tabs.blade.php) and the
 * "My Business" menu tile grid both render this list, so a bonus hidden behind
 * a feature flag can never appear on one surface and not the other.
 *
 * `label` is the short tab caption; `tile_label` is the longer caption used on
 * the My Business tiles, where there is room to be explicit.
 */
final class IncomeNavLinks
{
    /**
     * Every income page the signed-in distributor may currently open, in menu
     * order, with flag-gated bonus pages removed.
     *
     * @return list<array{route: string, label: string, tile_label: string}>
     */
    public static function visible(): array
    {
        $links = [
            ['route' => 'income.dashboard', 'label' => 'Dashboard', 'tile_label' => 'Dashboard & Income', 'visible' => true],
            ['route' => 'income.genos-bv', 'label' => 'Genos BV', 'tile_label' => 'Genos BV', 'visible' => true],
            ['route' => 'income.genos-ledger', 'label' => 'Genos Ledger', 'tile_label' => 'Genos Ledger', 'visible' => true],
            ['route' => 'income.gsb-history', 'label' => 'GSB History', 'tile_label' => 'GSB History', 'visible' => true],
            ['route' => 'income.mentorship', 'label' => 'Mentorship', 'tile_label' => 'Mentorship', 'visible' => Feature::for(null)->active(MentorshipBonusFeature::class)],
            ['route' => 'income.growth-booster', 'label' => 'Growth Booster', 'tile_label' => 'Growth Booster', 'visible' => Feature::for(null)->active(GrowthBoosterBonusFeature::class)],
            ['route' => 'income.rank-bonus', 'label' => 'Rank Bonus', 'tile_label' => 'Rank Bonus', 'visible' => Feature::for(null)->active(RankBonusFeature::class)],
            ['route' => 'income.fortune-bonus', 'label' => 'Fortune Bonus', 'tile_label' => 'Fortune Bonus', 'visible' => Feature::for(null)->active(FortuneBonusFeature::class)],
            ['route' => 'income.adc-bonus', 'label' => 'ADC Bonus', 'tile_label' => 'ADC Bonus', 'visible' => Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class)],
            ['route' => 'income.wallet', 'label' => 'Wallet & Payouts', 'tile_label' => 'Wallet & Payouts', 'visible' => true],
        ];

        $visible = [];

        foreach ($links as $link) {
            if ($link['visible']) {
                $visible[] = [
                    'route' => $link['route'],
                    'label' => $link['label'],
                    'tile_label' => $link['tile_label'],
                ];
            }
        }

        return $visible;
    }
}
