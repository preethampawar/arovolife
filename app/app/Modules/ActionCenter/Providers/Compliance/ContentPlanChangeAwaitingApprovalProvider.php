<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Compliance;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Content\Models\ContentPageRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A Compensation Plan change proposed in the editor and waiting for a second
 * person to approve it (R-125).
 *
 * Shown to everyone who edits content, the author included, so a change left
 * waiting is visible rather than forgotten: maker-checker where the checker
 * is never told is maker-checker in name only.
 */
final class ContentPlanChangeAwaitingApprovalProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'content.plan_change_awaiting_approval';
    }

    public function group(): string
    {
        return ActionGroup::COMPLIANCE;
    }

    public function label(): string
    {
        return 'Plan changes awaiting approval';
    }

    public function description(): string
    {
        return 'A change to the Compensation Plan page, waiting for a second person to approve it before it goes live.';
    }

    public function permission(): string
    {
        return 'content.publish';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function subjectType(): string
    {
        return 'content_page_revision';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->with('page')
            ->orderBy('content_page_revisions.created_at')
            ->limit($limit)
            ->get()
            ->map(function (ContentPageRevision $revision): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $revision->id,
                    title: $revision->page->title,
                    subtitle: 'Proposed '.$this->ageLabel($revision->created_at).' ago, awaiting approval',
                    occurredAt: $revision->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route('admin.content.plan-changes.show', $revision),
                    meta: ['requested_by' => $revision->requested_by],
                );
            })
            ->values();
    }

    /** @return Builder<ContentPageRevision> */
    private function baseQuery(): Builder
    {
        $query = ContentPageRevision::query()
            ->where('content_page_revisions.state', ContentPageRevision::STATE_PENDING);

        $this->excludeSnoozed($query->getQuery(), 'content_page_revisions.id');

        return $query;
    }
}
