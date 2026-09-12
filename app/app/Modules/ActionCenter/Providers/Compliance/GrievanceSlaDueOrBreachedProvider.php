<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Compliance;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Grievance\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An unsettled grievance past its acknowledgement, first-response or
 * resolution clock (plan §4, Compliance) — the exact three conditions
 * `GrievanceSlaSweepCommand::stampBreaches()` checks (due-at in the past,
 * met-at still null), without the sweep's "already stamped" exclusion: an
 * item already recorded as breached is still due a human, so it stays
 * visible here. Statutory: the DSR clocks are not a manager's to silence
 * (plan §5).
 */
final class GrievanceSlaDueOrBreachedProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'grievance.sla_due_or_breached';
    }

    public function group(): string
    {
        return ActionGroup::COMPLIANCE;
    }

    public function label(): string
    {
        return 'Grievances past an SLA clock';
    }

    public function description(): string
    {
        return 'Unsettled grievances past the acknowledgement, first-response or resolution clock. Respond from the grievance record.';
    }

    public function permission(): string
    {
        return 'grievance.handle';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function statutory(): bool
    {
        return true;
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
            ->orderBy('tickets.created_at')
            ->limit($limit)
            ->get([
                'tickets.id', 'tickets.ticket_no', 'tickets.created_at',
                'tickets.sla_acknowledgement_at', 'tickets.acknowledged_at',
                'tickets.sla_first_response_at', 'tickets.first_response_at',
                'tickets.sla_resolution_at', 'tickets.resolved_at',
            ])
            ->map(function (Ticket $ticket): ActionItem {
                $dueAt = $this->earliestUnmetDueAt($ticket);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $ticket->id,
                    title: (string) $ticket->ticket_no,
                    subtitle: 'An SLA clock is due or breached',
                    occurredAt: $ticket->created_at,
                    dueAt: $dueAt,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['id' => $ticket->id]),
                    meta: [],
                );
            })
            ->values();
    }

    private function earliestUnmetDueAt(Ticket $ticket): ?Carbon
    {
        $candidates = [];

        if ($ticket->acknowledged_at === null && $ticket->sla_acknowledgement_at !== null) {
            $candidates[] = $ticket->sla_acknowledgement_at;
        }

        if ($ticket->first_response_at === null && $ticket->sla_first_response_at !== null) {
            $candidates[] = $ticket->sla_first_response_at;
        }

        if ($ticket->resolved_at === null && $ticket->sla_resolution_at !== null) {
            $candidates[] = $ticket->sla_resolution_at;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (Carbon $a, Carbon $b): int => $a <=> $b);

        return $candidates[0];
    }

    /** @return Builder<Ticket> */
    private function baseQuery(): Builder
    {
        $now = now();

        $query = Ticket::query()
            ->unsettled()
            ->where(function (Builder $q) use ($now): void {
                $q->where(function (Builder $q2) use ($now): void {
                    $q2->whereNull('tickets.acknowledged_at')
                        ->whereNotNull('tickets.sla_acknowledgement_at')
                        ->where('tickets.sla_acknowledgement_at', '<=', $now);
                })->orWhere(function (Builder $q2) use ($now): void {
                    $q2->whereNull('tickets.first_response_at')
                        ->whereNotNull('tickets.sla_first_response_at')
                        ->where('tickets.sla_first_response_at', '<=', $now);
                })->orWhere(function (Builder $q2) use ($now): void {
                    $q2->whereNull('tickets.resolved_at')
                        ->whereNotNull('tickets.sla_resolution_at')
                        ->where('tickets.sla_resolution_at', '<=', $now);
                });
            });

        $this->excludeSnoozed($query->getQuery(), 'tickets.id');

        return $query;
    }
}
