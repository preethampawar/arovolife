<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Feature flag — Have I Been Pwned (HIBP) password breach check.
 *
 * When ON (the default), every new or changed password is compared
 * against the HIBP "Pwned Passwords" k-anonymity API
 * (api.pwnedpasswords.com) before it's accepted. Passwords that
 * have appeared in any known data breach are rejected regardless
 * of strength. This is a defence-in-depth control on top of the
 * separate zxcvbn entropy gate (`StrongPassword`).
 *
 * When OFF, the HIBP check is skipped entirely. zxcvbn still runs.
 * Useful for:
 *   - Environments without internet egress (some staging boxes)
 *   - Demo/UAT seeding where the QA team needs to reuse known-weak
 *     passwords like `Demo@12345`
 *   - Cost / quota concerns if the HIBP API ever moves behind a
 *     paid tier
 *
 * Default: `true` (HIBP enabled). Admins flip it at
 * /admin/feature-flags; the toggle is audit-logged.
 *
 * Resolved at runtime via:
 *     Feature::active(HibpPasswordCheck::class)
 *
 * SECURITY NOTE: switching this OFF in production weakens password
 * hygiene for the whole platform. zxcvbn still blocks low-entropy
 * passwords but cannot detect breach reuse. Keep ON in production.
 */
final class HibpPasswordCheck
{
    /** Pennant resolver — global scope, defaults to enabled. */
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
