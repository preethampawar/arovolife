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
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function forBvPaise(int $bvPaise): TitleResult
    {
        // Ascending [['threshold' => int, 'title' => string, 'slab' => int]],
        // sourced from the admin-editable gsb_slabs table.
        $ladder = $this->plan->titleLadder();
        $thresholds = array_map(fn (array $e): int => $e['threshold'], $ladder);

        $matched = null;
        foreach (array_reverse($ladder) as $entry) {
            if ($bvPaise >= $entry['threshold']) {
                $matched = $entry;
                break;
            }
        }

        if ($matched === null) {
            return new TitleResult(
                title: null,
                maxGsbSlab: 0,
                nextTitleBvPaise: $thresholds[0] ?? null,
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
