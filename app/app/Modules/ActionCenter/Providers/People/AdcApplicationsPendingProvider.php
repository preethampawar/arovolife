<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\People;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Models\AreteCenterApplication;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * An Arete Development Centre application still awaiting a decision (plan
 * §4, People) — `AreteCenterApplication::OPEN_STATUSES` (submitted,
 * under_review, needs_changes), the same set the admin review queue filters
 * to. Hidden while `AreteCenterApplicationsFeature` is off, exactly like the
 * routes and menu item it shares the flag with.
 */
final class AdcApplicationsPendingProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'adc.applications_pending';
    }

    public function group(): string
    {
        return ActionGroup::PEOPLE;
    }

    public function label(): string
    {
        return 'Arete Development Centre applications pending';
    }

    public function description(): string
    {
        return 'Arete Development Centre applications awaiting review. Decide them from the applications queue.';
    }

    public function permission(): string
    {
        return 'adc.application.review';
    }

    public function severity(): string
    {
        return Severity::INFO;
    }

    public function enabled(): bool
    {
        return Feature::for(null)->active(AreteCenterApplicationsFeature::class);
    }

    public function subjectType(): string
    {
        return 'arete_center_application';
    }

    public function targetRoute(): string
    {
        return 'admin.arete-centres.applications.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('arete_center_applications.created_at')
            ->limit($limit)
            ->get(['arete_center_applications.id', 'arete_center_applications.centre_name', 'arete_center_applications.status', 'arete_center_applications.created_at'])
            ->map(function (AreteCenterApplication $application): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $application->id,
                    title: (string) $application->centre_name,
                    subtitle: (AreteCenterApplication::STATUSES[$application->status] ?? $application->status).' — '.$this->ageLabel($application->created_at).' ago',
                    occurredAt: $application->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['application' => $application->id]),
                    meta: ['status' => (string) $application->status],
                );
            })
            ->values();
    }

    /** @return Builder<AreteCenterApplication> */
    private function baseQuery(): Builder
    {
        $query = AreteCenterApplication::query()
            ->whereIn('arete_center_applications.status', AreteCenterApplication::OPEN_STATUSES);

        $this->excludeSnoozed($query->getQuery(), 'arete_center_applications.id');

        return $query;
    }
}
