<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Killswitch for the whole Action Center (plan §9, slice A1).
 *
 * The module only reads — turning it off hides one screen and stops a handful
 * of aggregate queries; no business state depends on it, and no signal is lost
 * while it is off because nothing here is materialised (plan §10.1). It exists
 * so the screen can be dark-launched, and so one heavy provider cannot take the
 * admin console down on a bad day.
 *
 * Resolved via:
 *     Feature::for(null)->active(ActionCenterFeature::class)
 */
final class ActionCenterFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
