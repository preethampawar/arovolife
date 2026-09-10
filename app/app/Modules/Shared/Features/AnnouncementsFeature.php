<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates company announcements — the admin authoring console, the distributor
 * announcements list, and the unread count on the notification bell.
 *
 * An announcement is company copy addressed to a distributor audience, which
 * makes it the highest-risk mis-selling surface on the platform: a single
 * sentence implying future earnings reaches everyone at once and cannot be
 * unsent (hard rule 3; Code IV.XII.a; DSR Rule 5(1)(d)). The copy audit runs
 * over the stored body at save time for that reason, and the flag stays OFF
 * until the authoring workflow has an owner who is accountable for what goes
 * out under the company's name.
 *
 * Default: `false`. While off the feature leaves no trace: no menu item, no
 * routes (404), no bell section, no settings rows.
 *
 * Resolved via:
 *     Feature::active(AnnouncementsFeature::class)
 */
final class AnnouncementsFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
