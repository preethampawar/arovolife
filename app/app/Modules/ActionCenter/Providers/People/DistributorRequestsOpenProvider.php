<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\People;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Identity\Models\DistributorRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A distributor's own-record request (name correction, membership transfer,
 * ID cancellation, …) still awaiting a decision (plan §4, People). Backlog,
 * no clock — `DistributorRequest::OPEN_STATUSES` is the same submitted /
 * under_review pair the admin queue and `decide()` both use.
 */
final class DistributorRequestsOpenProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'distributor_requests.open';
    }

    public function group(): string
    {
        return ActionGroup::PEOPLE;
    }

    public function label(): string
    {
        return 'Open distributor requests';
    }

    public function description(): string
    {
        return 'Distributor requests (name, DOB, membership or ID) awaiting a decision. Decide them from the request queue.';
    }

    public function permission(): string
    {
        return 'distributor.request.handle';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function subjectType(): string
    {
        return 'distributor_request';
    }

    public function targetRoute(): string
    {
        return 'admin.distributor-requests.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('distributor_requests.created_at')
            ->limit($limit)
            ->get(['distributor_requests.id', 'distributor_requests.request_no', 'distributor_requests.type', 'distributor_requests.status', 'distributor_requests.created_at'])
            ->map(function (DistributorRequest $request): ActionItem {
                $label = DistributorRequest::TYPES[$request->type]['label'] ?? $request->type;

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $request->id,
                    title: (string) $request->request_no,
                    subtitle: $label.' — '.($request->status === DistributorRequest::STATUS_UNDER_REVIEW ? 'under review' : 'submitted').' '.$this->ageLabel($request->created_at).' ago',
                    occurredAt: $request->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['distributorRequest' => $request->id]),
                    meta: ['type' => (string) $request->type, 'status' => (string) $request->status],
                );
            })
            ->values();
    }

    /** @return Builder<DistributorRequest> */
    private function baseQuery(): Builder
    {
        $query = DistributorRequest::query()
            ->whereIn('distributor_requests.status', DistributorRequest::OPEN_STATUSES);

        $this->excludeSnoozed($query->getQuery(), 'distributor_requests.id');

        return $query;
    }
}
