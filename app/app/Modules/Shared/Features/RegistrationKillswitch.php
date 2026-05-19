<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Master killswitch for the public distributor registration funnel.
 *
 * When this feature returns `false`, all entry points to /register
 * (the join landing, the wizard start, the account form, and every
 * step POST) short-circuit with a "registration is temporarily
 * closed" message. The killswitch is meant for incident response —
 * compliance pauses, payment-gateway outages, or product holds —
 * NOT for permanent gating; permanent gates use route middleware.
 *
 * Default: `true` (registration open). The flag is resolved without a
 * scope so the value is global across the site. Admins flip it from
 * /admin/feature-flags.
 *
 * Resolved at runtime via:
 *     Feature::active(RegistrationKillswitch::class)
 *
 * The class name is the Pennant feature key; do not refactor without
 * also updating the existing flag row in the `features` table.
 */
final class RegistrationKillswitch
{
    /** Pennant resolver — global scope, defaults to enabled. */
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
