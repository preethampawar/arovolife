<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Services\DTOs\TitleResult;

/**
 * Resolves a distributor's personal purchase title and their maximum GSB slab
 * from their lifetime personal BV (in paise, i.e. BV × 100).
 *
 * Titles from the 2026-06-19 revenue sharing plan.
 * GSB slab constraint: the achieved slab is the LOWER of the matched group BV
 * slab and the distributor's title slab. This service provides the title slab cap.
 */
final class PersonalBvTitleService
{
    /** [min_bv_paise => [title, gsb_slab]] in ascending order. */
    private const LADDER = [
        300_000 => ['title' => 'Retailer',             'slab' => 1],
        500_000 => ['title' => 'Dealer',               'slab' => 2],
        1_500_000 => ['title' => 'Wholesaler',           'slab' => 3],
        5_000_000 => ['title' => 'Distributor',          'slab' => 4],
        10_000_000 => ['title' => 'Regional Distributor', 'slab' => 5],
        20_000_000 => ['title' => 'National Distributor', 'slab' => 6],
        30_000_000 => ['title' => 'Global Distributor',   'slab' => 7],
    ];

    public function forBvPaise(int $bvPaise): TitleResult
    {
        $matched = null;
        $thresholds = array_keys(self::LADDER);

        foreach (array_reverse($thresholds) as $threshold) {
            if ($bvPaise >= $threshold) {
                $entry = self::LADDER[$threshold];
                $matched = [
                    'threshold' => $threshold,
                    'title' => $entry['title'],
                    'slab' => $entry['slab'],
                ];
                break;
            }
        }

        if ($matched === null) {
            return new TitleResult(
                title: null,
                maxGsbSlab: 0,
                nextTitleBvPaise: $thresholds[0],
            );
        }

        $nextThreshold = null;
        foreach ($thresholds as $t) {
            if ($t > $matched['threshold']) {
                $nextThreshold = $t;
                break;
            }
        }

        return new TitleResult(
            title: $matched['title'],
            maxGsbSlab: $matched['slab'],
            nextTitleBvPaise: $nextThreshold,
        );
    }
}
