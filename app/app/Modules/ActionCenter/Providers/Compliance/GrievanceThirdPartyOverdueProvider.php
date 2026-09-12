<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Compliance;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Grievance\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A third-party-dependent, unsettled grievance whose complainant has not had
 * a progress update in 15 days (plan §4, Compliance) — the same condition
 * `GrievanceSlaSweepCommand::nudgeStatusUpdate()` checks (measured since
 * `last_status_update_at`, or `created_at` if never updated) before it nudges
 * the owning officer; this surfaces the same tickets on the screen rather
 * than only by mail.
 */
final class GrievanceThirdPartyOverdueProvider extends AbstractProvider
{
    private const OVERDUE_DAYS = 15;

    public function key(): string
    {
        return 'grievance.third_party_overdue';
    }

    public function group(): string
    {
        return ActionGroup::COMPLIANCE;
    }

    public function label(): string
    {
        return 'Third-party grievances overdue an update';
    }

    public function description(): string
    {
        return 'Third-party-dependent grievances with no progress update to the complainant in 15 days. Update from the grievance record.';
    }

    public function permission(): string
    {
        return 'grievance.handle';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function slaHours(): int
    {
        return self::OVERDUE_DAYS * 24;
    }

    public function subjectType(): string
    {
        return 'grievance_ticket';
    }

    public function targetRoute(): string
    {
        return 'admin.grievances.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderByRaw('COALESCE(tickets.last_status_update_at, tickets.created_at)')
            ->limit($limit)
            ->get(['tickets.id', 'tickets.ticket_no', 'tickets.created_at', 'tickets.last_status_update_at'])
            ->map(function (Ticket $ticket): ActionItem {
                $since = $ticket->last_status_update_at ?? $ticket->created_at;
                $dueAt = $this->dueAt($since);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $ticket->id,
                    title: (string) $ticket->ticket_no,
                    subtitle: 'No progress update to the complainant in '.$this->ageLabel($since),
                    occurredAt: $since,
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['id' => $ticket->id]),
                    meta: [],
                );
            })
            ->values();
    }

    /** @return Builder<Ticket> */
    private function baseQuery(): Builder
    {
        $query = Ticket::query()
            ->unsettled()
            ->where('tickets.third_party_dependent', true)
            ->whereRaw('COALESCE(tickets.last_status_update_at, tickets.created_at) <= ?', [$this->slaCutoff()]);

        $this->excludeSnoozed($query->getQuery(), 'tickets.id');

        return $query;
    }
}
