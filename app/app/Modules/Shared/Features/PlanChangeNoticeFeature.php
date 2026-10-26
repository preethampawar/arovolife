<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the compensation-plan change notice (plan page §12, R-123): the
 * `plan:notify-unread` email, three days after the notice started, to anyone
 * whose accepted plan text differs from `/p/compensation`. There is no
 * pop-up after login and the command is not scheduled: plan changes are told
 * to distributors verbally (user decision 2026-10-26).
 *
 * Default: `false`. While off no routes do anything and
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
