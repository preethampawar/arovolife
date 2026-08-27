<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Console\Commands;

use App\Modules\Grievance\Enums\TicketEventKind;
use App\Modules\Grievance\Enums\TicketStatus;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Notifications\GrievanceOfficerAlertNotification;
use App\Modules\Grievance\Services\GrievanceService;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Walks every unsettled grievance and enforces the published clocks.
 *
 * Three jobs, in order:
 *
 *  1. Stamp any SLA that has lapsed. Breaches are recorded the moment they
 *     happen so the monthly compliance report describes what was true then,
 *     not what today's settings would imply.
 *  2. Auto-escalate tickets that have sat too long at one step of the §4
 *     ladder. The published matrix says the complainant "may escalate" after
 *     7 days at step 1 and 15 at step 2 — doing it for them, rather than
 *     waiting to be asked, is the point of having a tracker.
 *  3. Nudge the owning officer when a third-party-dependent ticket is due its
 *     15-day progress update. The command cannot write that update itself:
 *     only a human knows what the third party actually said.
 *
 * Idempotent — safe to run repeatedly, and every write is guarded by a
 * "have we already done this" check.
 */
final class GrievanceSlaSweepCommand extends Command
{
    /** Marks the timeline entry written when the owning officer is nudged. */
    private const NUDGE_MARKER = 'status_update_nudge';

    protected $signature = 'grievance:sla-sweep {--dry-run : Report what would change without writing}';

    protected $description = 'Stamp grievance SLA breaches, auto-escalate stale tickets and flag overdue progress updates';

    public function handle(GrievanceService $grievances, GrievanceSettingsService $settings): int
    {
        $now = Carbon::now();
        $dryRun = (bool) $this->option('dry-run');

        $breaches = 0;
        $escalations = 0;
        $updateNudges = 0;

        Ticket::query()
            ->unsettled()
            ->orderBy('id')
            ->chunkById(200, function ($tickets) use (
                $grievances, $settings, $now, $dryRun, &$breaches, &$escalations, &$updateNudges
            ): void {
                foreach ($tickets as $ticket) {
                    $breaches += $this->stampBreaches($grievances, $ticket, $now, $dryRun);
                    $escalations += $this->autoEscalate($grievances, $settings, $ticket, $now, $dryRun);
                    $updateNudges += $this->nudgeStatusUpdate($grievances, $settings, $ticket, $now, $dryRun);
                }
            });

        $this->info(sprintf(
            '%s%d breach(es) stamped, %d ticket(s) escalated, %d progress-update nudge(s) sent.',
            $dryRun ? '[dry run] ' : '',
            $breaches,
            $escalations,
            $updateNudges
        ));

        return self::SUCCESS;
    }

    private function stampBreaches(GrievanceService $grievances, Ticket $ticket, Carbon $now, bool $dryRun): int
    {
        $stamped = 0;

        $due = [
            'acknowledgement' => [$ticket->sla_acknowledgement_at, $ticket->acknowledged_at, $ticket->acknowledgement_breached_at],
            'first_response' => [$ticket->sla_first_response_at, $ticket->first_response_at, $ticket->first_response_breached_at],
            'resolution' => [$ticket->sla_resolution_at, $ticket->resolved_at, $ticket->resolution_breached_at],
        ];

        foreach ($due as $kind => [$dueAt, $metAt, $alreadyStamped]) {
            if ($dueAt === null || $metAt !== null || $alreadyStamped !== null) {
                continue;
            }

            if ($dueAt->greaterThan($now)) {
                continue;
            }

            $stamped++;

            if ($dryRun) {
                $this->line("  would stamp {$kind} breach on {$ticket->ticket_no}");

                continue;
            }

            $grievances->recordBreach($ticket, $kind, $now);
        }

        return $stamped;
    }

    private function autoEscalate(
        GrievanceService $grievances,
        GrievanceSettingsService $settings,
        Ticket $ticket,
        Carbon $now,
        bool $dryRun,
    ): int {
        if (! $settings->autoEscalationEnabled()) {
            return 0;
        }

        $after = $settings->autoEscalationAfterDays($ticket->escalation_level);

        if ($after === null || $ticket->escalation_level->next() === null) {
            return 0;
        }

        // Time at the CURRENT level: since the last escalation, or since the
        // ticket was received if it has never moved.
        $since = $ticket->escalated_at ?? $ticket->created_at;

        if ($since->copy()->addDays($after)->greaterThan($now)) {
            return 0;
        }

        if ($dryRun) {
            $this->line("  would escalate {$ticket->ticket_no} to step ".($ticket->escalation_level->value + 1));

            return 1;
        }

        $grievances->escalate(
            $ticket,
            actorUserId: null,
            reason: sprintf(
                'Automatically escalated: %d days at the %s without resolution.',
                $after,
                $ticket->escalation_level->label()
            ),
        );

        return 1;
    }

    /**
     * A third-party-dependent ticket owes the complainant an update every 15
     * days. We alert the owning officer rather than writing the update — a
     * generated "still waiting" message would satisfy the letter of the
     * promise and none of its purpose.
     */
    private function nudgeStatusUpdate(
        GrievanceService $grievances,
        GrievanceSettingsService $settings,
        Ticket $ticket,
        Carbon $now,
        bool $dryRun,
    ): int {
        if (! $ticket->third_party_dependent || $ticket->status === TicketStatus::Resolved) {
            return 0;
        }

        $since = $ticket->last_status_update_at ?? $ticket->created_at;

        if ($since->copy()->addDays($settings->statusUpdateIntervalDays())->greaterThan($now)) {
            return 0;
        }

        // Only nudge once per overdue window. The nudge itself does not touch
        // `last_status_update_at` — that column records that the COMPLAINANT
        // was updated, and moving it here would quietly launder an unmet
        // promise into a met one. So the marker event is what we check.
        $alreadyNudged = $ticket->events()
            ->where('kind', TicketEventKind::Notification->value)
            ->where('to_value', self::NUDGE_MARKER)
            ->where('created_at', '>', $since)
            ->exists();

        if ($alreadyNudged) {
            return 0;
        }

        if ($dryRun) {
            $this->line("  would nudge progress update on {$ticket->ticket_no}");

            return 1;
        }

        $mailbox = $settings->mailboxForTicket($ticket->category, $ticket->escalation_level);

        if ($mailbox === '') {
            return 0;
        }

        Notification::route('mail', $mailbox)->notify(
            new GrievanceOfficerAlertNotification(
                ticketNo: $ticket->ticket_no,
                categoryLabel: $ticket->category->label(),
                ownerLabel: $ticket->escalation_level->label(),
                resolutionBy: $ticket->sla_resolution_at?->toFormattedDayDateString() ?? 'unset',
                adminUrl: route('admin.grievances.show', $ticket->id),
                reason: 'A progress update is due to the complainant — this grievance is waiting on a third party and the complainant has not heard from us in '
                    .$settings->statusUpdateIntervalDays().' days.',
            )
        );

        $grievances->recordEvent(
            $ticket,
            TicketEventKind::Notification,
            toValue: self::NUDGE_MARKER,
            note: 'Owning officer reminded that a progress update is due to the complainant.',
            at: $now,
        );

        return 1;
    }
}
