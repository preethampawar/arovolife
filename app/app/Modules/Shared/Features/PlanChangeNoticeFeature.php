<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the compensation-plan change notice (plan page §12, R-123): the
 * pop-up a distributor sees after login when `/p/compensation` has changed
 * since the version they accepted, and the `plan:notify-unread` email to
 * anyone who has not confirmed it within three days.
 *
 * Default: `false`. While off there is no pop-up, no routes do anything and
 * the command writes nothing. Turning it on starts the notice clock for the
 * published plan text (`plan_text_versions.notice_started_at`), so publish the
 * page and switch this on in the same release step.
 *
 * Resolved via:
 *     Feature::for(null)->active(PlanChangeNoticeFeature::class)
 */
final class PlanChangeNoticeFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
