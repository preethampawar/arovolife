<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Enums;

/**
 * The seven Arovolife bonus streams.
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
    // Fulfilment commission, not a downline earning. Exempt from the admin
    // charge by the same rule that exempts awards (T&C / compliance skill),
    // so `comp.admin_charge.applies_to_franchise` defaults to false.
    case Franchise = 'franchise';
    // No payout engine yet (Lifetime Awards ships in a later phase). The case
    // and its `applies_to_awards` toggle exist for forward-config only and have
    // no runtime effect until that engine reads BonusType::LifetimeAwards.
    case LifetimeAwards = 'awards';
}
