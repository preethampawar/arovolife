<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Compliance;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Messaging\Models\MessageReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A reported message still awaiting moderation (plan §4, Compliance) —
 * `MessageReport::STATUS_OPEN`, the same status the report review screen
 * lists. Backlog, no clock. Titled by report category only — the message
 * body and the reporter's identity stay behind the moderation screen (the
 * same private-conversation exclusion the grievance queue applies).
 */
final class MessagingReportedPendingProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'messaging.reported_pending';
    }

    public function group(): string
    {
        return ActionGroup::COMPLIANCE;
    }

    public function label(): string
    {
        return 'Reported messages pending moderation';
    }

    public function description(): string
    {
        return 'Reported messages awaiting a moderation decision. Review them from the message reports queue.';
    }

    public function permission(): string
    {
        return 'messaging.moderate';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function subjectType(): string
    {
        return 'message_report';
    }

    public function targetRoute(): string
    {
        return 'admin.messaging.reports.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('message_reports.created_at')
            ->limit($limit)
            ->get(['message_reports.id', 'message_reports.category', 'message_reports.created_at'])
            ->map(function (MessageReport $report): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $report->id,
                    title: 'Report #'.$report->id,
                    subtitle: $report->categoryLabel().' — reported '.$this->ageLabel($report->created_at).' ago',
                    occurredAt: $report->created_at,
                    dueAt: null,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['report' => $report->id]),
                    meta: ['category' => (string) $report->category],
                );
            })
            ->values();
    }

    /** @return Builder<MessageReport> */
    private function baseQuery(): Builder
    {
        $query = MessageReport::query()->where('message_reports.status', MessageReport::STATUS_OPEN);

        $this->excludeSnoozed($query->getQuery(), 'message_reports.id');

        return $query;
    }
}
