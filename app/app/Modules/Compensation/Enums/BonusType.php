<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Enums;

/**
 * The six Arovolife cash bonus streams.
 *
 * Single typed list of every bonus the compensation engine pays. The string
 * value is the settings-key suffix used for per-bonus toggles, e.g.
 * `comp.admin_charge.applies_to_{value}`.
 */
enum BonusType: string
{
    case Gsb = 'gsb';
    case Mentorship = 'mb';
    case Rank = 'rank';
    case GrowthBooster = 'gbb';
    case Fortune = 'fortune';
    case Arete = 'adc';
}
