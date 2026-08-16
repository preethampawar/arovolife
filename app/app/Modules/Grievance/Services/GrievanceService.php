<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Services;

use App\Modules\Grievance\DTOs\FileGrievanceData;
use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketEventKind;
use App\Modules\Grievance\Enums\TicketStatus;
use App\Modules\Grievance\Events\GrievanceAcknowledged;
use App\Modules\Grievance\Events\GrievanceEscalated;
use App\Modules\Grievance\Events\GrievanceFiled;
use App\Modules\Grievance\Events\GrievanceReplyReceived;
use App\Modules\Grievance\Events\GrievanceResolved;
use App\Modules\Grievance\Events\GrievanceStatusUpdatePublished;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Models\TicketEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single writer for grievance state.
 *
 * Controllers, the SLA sweep command and the contact-inbox bridge all go
 * through here. Nothing else may set `status`, the SLA columns or
 * `escalation_level`, because every one of those changes owes the complainant
 * a timeline entry — and, for most of them, a notification. Scattering those
 * writes is how a regulator ends up reading a ticket whose history does not
 * explain its state.
 *
 * Domain events are dispatched after the transaction commits, never inside it.
 */
final class GrievanceService
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly GrievanceSlaCalculator $sla,
        private readonly GrievanceSettingsService $settings,
    ) {}

    /**
     * Open a grievance and start all three SLA clocks.
     *
     * Ethics and privacy complaints skip front-line care and open directly at
     * the officer who owns them — routing a bribe allegation to the agent who
     * may be its subject is not a workflow we want to have.
     */
    public function file(FileGrievanceData $data): Ticket
    {
        // Every clock in policy §2 is measured "of receipt". For post, phone and
        // walk-in that is the day the complaint arrived, which is not the day a
        // staff member typed it in. Recording it against the keying-in date
        // would quietly under-report breaches to the regulator.
        // One clock reading, not two. Calling Carbon::now() twice made the two
        // values differ by a millisecond about half the time, which sent
        // ordinary web submissions down the backdated branch and wrote
        // "received on 16 Aug; recorded on 16 Aug" onto the statutory register.
        $recordedAt = Carbon::now();
        $receivedAt = $data->receivedAt ?? $recordedAt;
        $schedule = $this->sla->scheduleFor($receivedAt);

        $openingLevel = $data->category->bypassesFrontLine()
            ? EscalationLevel::GrievanceOfficer
            : EscalationLevel::CustomerCare;

        $ticket = DB::transaction(function () use ($data, $receivedAt, $recordedAt, $schedule, $openingLevel): Ticket {
            $ticket = Ticket::create([
                'ticket_no' => $this->numbers->generate($receivedAt),
                'subject' => $data->subject,
                'body' => $data->body,
                'category' => $data->category,
                'severity' => $data->severity,
                'status' => TicketStatus::Open,
                'channel' => $data->channel,
                'customer_id' => $data->customerId,
                'distributor_id' => $data->distributorId,
                'reporter_name' => $data->isAnonymous ? null : $data->reporterName,
                'reporter_email' => $data->isAnonymous ? null : $data->reporterEmail,
                'reporter_phone' => $data->isAnonymous ? null : $data->reporterPhone,
                'is_anonymous' => $data->isAnonymous,
                'order_id' => $data->orderId,
                'escalation_level' => $openingLevel,
                'sla_acknowledgement_at' => $schedule->acknowledgementDueAt,
                'sla_first_response_at' => $schedule->firstResponseDueAt,
                'sla_resolution_at' => $schedule->resolutionDueAt,
            ]);

            // `created_at` is the RECEIPT date, because the DSR register and
            // every SLA in policy §2 are measured from receipt. Eloquent has
            // just stamped it with "now", so a backdated intake is corrected
            // here rather than left to disagree with its own SLA columns.
            if (! $receivedAt->equalTo($recordedAt)) {
                $ticket->forceFill(['created_at' => $receivedAt])->save();
            }

            $this->recordEvent(
                $ticket,
                TicketEventKind::StatusChange,
                actorUserId: $data->recordedByUserId,
                toValue: TicketStatus::Open->value,
                note: $receivedAt->equalTo($recordedAt)
                    ? sprintf('Received via %s.', $data->channel->label())
                    : sprintf(
                        'Received via %s on %s; recorded on %s.',
                        $data->channel->label(),
                        $receivedAt->format('d M Y'),
                        $recordedAt->format('d M Y')
                    ),
                at: $receivedAt,
            );

            if ($openingLevel !== EscalationLevel::CustomerCare) {
                $this->recordEvent(
                    $ticket,
                    TicketEventKind::Escalation,
                    actorUserId: $data->recordedByUserId,
                    fromValue: (string) EscalationLevel::CustomerCare->value,
                    toValue: (string) $openingLevel->value,
                    note: sprintf(
                        '%s complaints open directly with the %s.',
                        $data->category->label(),
                        $openingLevel->label()
                    ),
                    at: $receivedAt,
                );
            }

            return $ticket;
        });

        event(new GrievanceFiled($ticket->id));

        return $ticket;
    }

    /**
     * Record the acknowledgement promised within 48 hours (policy §2).
     *
     * Idempotent: the acknowledgement mail is sent by a listener on
     * GrievanceFiled, and a staff member opening the ticket should not be able
     * to restart the clock or double-notify.
     */
    public function acknowledge(Ticket $ticket, ?int $actorUserId = null, ?Carbon $at = null): Ticket
    {
        if ($ticket->acknowledged_at !== null) {
            return $ticket;
        }

        $at ??= Carbon::now();

        DB::transaction(function () use ($ticket, $actorUserId, $at): void {
            $from = $ticket->status;

            $ticket->forceFill([
                'acknowledged_at' => $at,
                'status' => $ticket->status === TicketStatus::Open ? TicketStatus::Acknowledged : $ticket->status,
            ])->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::Acknowledgement,
                actorUserId: $actorUserId,
                fromValue: $from->value,
                toValue: $ticket->status->value,
                note: 'Acknowledgement issued with the complaint number.',
                at: $at,
            );
        });

        event(new GrievanceAcknowledged($ticket->id));

        return $ticket->refresh();
    }

    /**
     * A staff reply. The first one stops the first-response clock.
     */
    public function addStaffResponse(Ticket $ticket, string $note, int $actorUserId): Ticket
    {
        $now = Carbon::now();

        DB::transaction(function () use ($ticket, $note, $actorUserId, $now): void {
            $isFirst = $ticket->first_response_at === null;

            $attributes = ['first_response_at' => $ticket->first_response_at ?? $now];

            if ($ticket->status === TicketStatus::Open || $ticket->status === TicketStatus::Acknowledged) {
                $attributes['status'] = TicketStatus::InProgress;
            }

            $from = $ticket->status;
            $ticket->forceFill($attributes)->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::Comment,
                actorUserId: $actorUserId,
                fromValue: $isFirst ? $from->value : null,
                toValue: $isFirst ? $ticket->status->value : null,
                note: $note,
                at: $now,
            );
        });

        return $ticket->refresh();
    }

    /**
     * A reply from the complainant.
     *
     * Never changes status by itself — adding detail must not let a complainant
     * reopen a closed file or push a ticket out of the queue it sits in. But it
     * always notifies whoever owns the ticket, and when the complainant asks to
     * escalate it does escalate them. Policy §4 tells them replying is how they
     * escalate; before this, that reply landed in a database row and nothing
     * else happened.
     */
    public function addComplainantReply(Ticket $ticket, string $note, bool $requestsEscalation = false): Ticket
    {
        $this->recordEvent($ticket, TicketEventKind::Comment, note: $note);

        $escalated = false;

        if ($requestsEscalation && $ticket->escalation_level->next() !== null) {
            $ticket = $this->escalate(
                $ticket,
                actorUserId: null,
                reason: 'Escalated at the complainant\'s request.',
            );
            $escalated = true;
        }

        // The escalation already alerted the new owner. Firing the reply event
        // too would send them the same ticket twice in one action.
        if (! $escalated) {
            event(new GrievanceReplyReceived($ticket->id, false));
        }

        return $ticket->refresh();
    }

    public function assign(Ticket $ticket, ?int $assigneeUserId, int $actorUserId): Ticket
    {
        $from = $ticket->assigned_to_user_id;

        DB::transaction(function () use ($ticket, $assigneeUserId, $actorUserId, $from): void {
            $ticket->forceFill(['assigned_to_user_id' => $assigneeUserId])->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::Assignment,
                actorUserId: $actorUserId,
                fromValue: $from === null ? null : (string) $from,
                toValue: $assigneeUserId === null ? null : (string) $assigneeUserId,
            );
        });

        return $ticket->refresh();
    }

    /**
     * Move a ticket one step up the internal ladder (policy §4).
     *
     * Returns the ticket unchanged when it is already at the Compliance
     * Committee — steps 5 to 8 of the published matrix are external
     * authorities that arovolife neither owns nor tracks.
     */
    public function escalate(Ticket $ticket, ?int $actorUserId = null, ?string $reason = null): Ticket
    {
        $next = $ticket->escalation_level->next();

        if ($next === null) {
            return $ticket;
        }

        $from = $ticket->escalation_level;
        $now = Carbon::now();

        DB::transaction(function () use ($ticket, $next, $from, $actorUserId, $reason, $now): void {
            $ticket->forceFill([
                'escalation_level' => $next,
                'escalated_at' => $now,
            ])->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::Escalation,
                actorUserId: $actorUserId,
                fromValue: (string) $from->value,
                toValue: (string) $next->value,
                note: $reason ?? sprintf('Escalated to the %s.', $next->label()),
                at: $now,
            );
        });

        event(new GrievanceEscalated($ticket->id, $from->value, $next->value));

        return $ticket->refresh();
    }

    /**
     * Flag a ticket as waiting on a third party (bank, payment gateway,
     * AUA/KUA partner, statutory authority) and extend the resolution window
     * from 30 to 60 days, as policy §2 permits.
     *
     * The extension is one-way: it can be applied once and never shortens an
     * existing due date. Letting staff re-flag a ticket to buy another 30 days
     * would turn a published ceiling into an open-ended one.
     */
    public function markThirdPartyDependent(Ticket $ticket, string $reason, int $actorUserId): Ticket
    {
        if ($ticket->third_party_dependent) {
            return $ticket;
        }

        $now = Carbon::now();
        $extended = $ticket->created_at->copy()->addDays($this->settings->resolutionDays(true));

        DB::transaction(function () use ($ticket, $reason, $actorUserId, $now, $extended): void {
            $previousDue = $ticket->sla_resolution_at;

            $ticket->forceFill([
                'third_party_dependent' => true,
                'sla_resolution_at' => $extended->greaterThan($previousDue) ? $extended : $previousDue,
                'last_status_update_at' => $ticket->last_status_update_at ?? $now,
            ])->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::SlaExtension,
                actorUserId: $actorUserId,
                fromValue: $previousDue?->toDateString(),
                toValue: $ticket->sla_resolution_at?->toDateString(),
                note: $reason,
                at: $now,
            );
        });

        return $ticket->refresh();
    }

    /**
     * The 15-day progress update owed while a third-party extension runs.
     */
    public function publishStatusUpdate(Ticket $ticket, string $note, ?int $actorUserId = null): Ticket
    {
        $now = Carbon::now();

        DB::transaction(function () use ($ticket, $note, $actorUserId, $now): void {
            $ticket->forceFill(['last_status_update_at' => $now])->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::StatusUpdate,
                actorUserId: $actorUserId,
                note: $note,
                at: $now,
            );
        });

        event(new GrievanceStatusUpdatePublished($ticket->id, $note));

        return $ticket->refresh();
    }

    public function resolve(Ticket $ticket, string $resolutionNote, int $actorUserId): Ticket
    {
        $now = Carbon::now();

        // Stamp any lapsed clock BEFORE backfilling it. Resolving fills in a
        // missing acknowledgement or first-response timestamp so the record is
        // complete — but if the promise was already missed, doing that without
        // recording the breach first would launder an unmet commitment into a
        // met one whenever the hourly sweep had not yet caught up.
        foreach ([
            'acknowledgement' => [$ticket->sla_acknowledgement_at, $ticket->acknowledged_at],
            'first_response' => [$ticket->sla_first_response_at, $ticket->first_response_at],
            'resolution' => [$ticket->sla_resolution_at, $ticket->resolved_at],
        ] as $kind => [$dueAt, $metAt]) {
            if ($metAt === null && $dueAt !== null && $dueAt->lessThanOrEqualTo($now)) {
                $ticket = $this->recordBreach($ticket, $kind, $now);
            }
        }

        DB::transaction(function () use ($ticket, $resolutionNote, $actorUserId, $now): void {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => TicketStatus::Resolved,
                'resolved_at' => $now,
                'resolution_note' => $resolutionNote,
                'first_response_at' => $ticket->first_response_at ?? $now,
                'acknowledged_at' => $ticket->acknowledged_at ?? $now,
                // Start the retention clock here, not only at closure. A ticket
                // that is resolved and then never formally closed would
                // otherwise keep the complainant's bank and KYC screenshots
                // forever, invisible to the purge — a live storage-limitation
                // breach, unlike the seven-year one. `close()` recomputes it.
                'retention_until' => $this->sla->retentionUntil($now),
            ])->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::StatusChange,
                actorUserId: $actorUserId,
                fromValue: $from->value,
                toValue: TicketStatus::Resolved->value,
                note: $resolutionNote,
                at: $now,
            );
        });

        event(new GrievanceResolved($ticket->id));

        return $ticket->refresh();
    }

    /**
     * Final closure. Starts the seven-year retention clock (policy §6.2).
     */
    public function close(Ticket $ticket, int $actorUserId, ?string $note = null): Ticket
    {
        $now = Carbon::now();

        DB::transaction(function () use ($ticket, $actorUserId, $note, $now): void {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => TicketStatus::Closed,
                'closed_at' => $now,
                'closed_by_user_id' => $actorUserId,
                'resolved_at' => $ticket->resolved_at ?? $now,
                'retention_until' => $this->sla->retentionUntil($now),
            ])->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::StatusChange,
                actorUserId: $actorUserId,
                fromValue: $from->value,
                toValue: TicketStatus::Closed->value,
                note: $note,
                at: $now,
            );
        });

        return $ticket->refresh();
    }

    /**
     * Stamp a breach the first time the sweep sees it.
     *
     * Breaches are recorded rather than recomputed so the monthly compliance
     * report describes what was true at the time, not what a later settings
     * change would have made true.
     *
     * @param  'acknowledgement'|'first_response'|'resolution'  $kind
     */
    public function recordBreach(Ticket $ticket, string $kind, ?Carbon $at = null): Ticket
    {
        $column = $kind.'_breached_at';

        if ($ticket->{$column} !== null) {
            return $ticket;
        }

        $at ??= Carbon::now();

        DB::transaction(function () use ($ticket, $column, $kind, $at): void {
            $ticket->forceFill([$column => $at])->save();

            $this->recordEvent(
                $ticket,
                TicketEventKind::SlaBreach,
                toValue: $kind,
                note: sprintf('The %s SLA lapsed without being met.', str_replace('_', ' ', $kind)),
                at: $at,
            );
        });

        return $ticket->refresh();
    }

    /**
     * Append to the immutable timeline. Public so the attachment controller
     * can log an upload without going through a state transition.
     */
    public function recordEvent(
        Ticket $ticket,
        TicketEventKind $kind,
        ?int $actorUserId = null,
        ?string $fromValue = null,
        ?string $toValue = null,
        ?string $note = null,
        ?Carbon $at = null,
    ): TicketEvent {
        return TicketEvent::create([
            'ticket_id' => $ticket->id,
            'kind' => $kind,
            'actor_user_id' => $actorUserId,
            'from_value' => $fromValue,
            'to_value' => $toValue,
            'note' => $note,
            'created_at' => $at ?? Carbon::now(),
        ]);
    }
}
