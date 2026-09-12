<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\People;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Genealogy\Models\LineChangeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A placement line-change request awaiting an admin decision (plan §4,
 * People). Backlog, no clock. Identified by the requesting distributor's id
 * only — no name is resolved here, the fixing screen shows it.
 */
final class LineChangePendingProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'line_change.pending';
    }

    public function group(): string
    {
        return ActionGroup::PEOPLE;
    }

    public function label(): string
    {
        return 'Pending line-change requests';
    }

    public function description(): string
    {
        return 'Placement line-change requests awaiting a decision. Decide them from the line-change screen.';
    }

    public function permission(): string
    {
        return 'placement.decide';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function subjectType(): string
    {
        return 'line_change_request';
    }

    public function targetRoute(): string
    {
        return 'admin.line-changes.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('line_change_requests.requested_at')
            ->limit($limit)
            ->get(['line_change_requests.id', 'line_change_requests.distributor_id', 'line_change_requests.requested_at'])
            ->map(function (LineChangeRequest $request): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $request->id,
                    title: "Line change #{$request->id}",
                    subtitle: 'Requested '.$this->ageLabel($request->requested_at).' ago, awaiting decision',
                    occurredAt: $request->requested_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['id' => $request->id]),
                    meta: ['distributor_id' => (int) $request->distributor_id],
                );
            })
            ->values();
    }

    /** @return Builder<LineChangeRequest> */
    private function baseQuery(): Builder
    {
        $query = LineChangeRequest::query()->where('line_change_requests.status', 'pending');

        $this->excludeSnoozed($query->getQuery(), 'line_change_requests.id');

        return $query;
    }
}
