<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Master switch for distributor-to-distributor direct messages — the inbox,
 * the chat thread, the "Send Message" action on Genos and direct-referral
 * cards, and the send pipeline behind all three.
 *
 * Default: `true`, unlike every other feature in this namespace. Messaging
 * shipped in Phase 1 and is live; a flag that defaults OFF would delete a
 * working feature from distributors on the deploy that introduced the flag.
 * This is a killswitch in the {@see RegistrationKillswitch} sense — pull it
 * when a moderation or harassment incident needs the channel closed while
 * it is investigated — not a launch gate.
 *
 * While OFF the surface leaves no trace: no sidenav entry, no bell thread
 * count, routes 404, and the messaging settings disappear from the settings
 * console. Existing messages are retained, not deleted; turning the flag back
 * on restores every thread.
 *
 * Resolved at runtime via:
 *     Feature::active(MessagingFeature::class)
 *
 * The class name is the Pennant feature key; do not refactor without also
 * updating the existing flag row in the `features` table.
 */
final class MessagingFeature
{
    /** Pennant resolver — global scope, defaults to enabled. */
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
