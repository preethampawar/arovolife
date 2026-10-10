<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;

/**
 * Whether the signed-in distributor sees the ADC Bonus at all — tab, income
 * card, wallet tip and the page itself.
 *
 * A centre is granted only after an application and interview, so the bonus
 * is shown solely to a distributor who has been assigned a centre (any status,
 * so a stopped centre's owner keeps their history) or who has ever been
 * credited one (a centre transferred away still leaves its past income
 * visible). Everyone else sees no trace of it (client, 2026-10-10).
 */
final class AdcBonusVisibility
{
    public static function forCurrentUser(): bool
    {
        if (! Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class)) {
            return false;
        }

        $distributorId = Auth::user()?->distributor?->id;

        if ($distributorId === null) {
            return false;
        }

        return self::forDistributor((int) $distributorId);
    }

    /** Memoised per request and per distributor — the tab bar and income cards both ask. */
    private static function forDistributor(int $distributorId): bool
    {
        return once(static function () use ($distributorId): bool {
            try {
                return AreteCenter::where('assigned_distributor_id', $distributorId)->exists()
                    || AdcBonusResult::where('distributor_id', $distributorId)->exists();
            } catch (QueryException) {
                return false;
            }
        });
    }
}
