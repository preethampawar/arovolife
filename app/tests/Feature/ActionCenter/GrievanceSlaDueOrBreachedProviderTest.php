<?php

declare(strict_types=1);

/**
 * Must agree with `GrievanceSlaSweepCommand::stampBreaches()`'s clocks: an
 * unsettled ticket whose acknowledgement, first-response or resolution
 * due-at has passed with the matching met-at still null.
 */

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Compliance\GrievanceSlaDueOrBreachedProvider;
use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Enums\TicketStatus;
use App\Modules\Grievance\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(GrievanceSlaDueOrBreachedProvider::class);
});

/** @param array<string, mixed> $overrides */
function grievanceTicket(array $overrides = []): Ticket
{
    $n = random_int(100000, 999999);

    return Ticket::create(array_merge([
        'ticket_no' => "GR-AC-{$n}",
        'subject' => 'Test grievance',
        'body' => 'Body',
        'category' => TicketCategory::Order,
        'severity' => 'medium',
        'status' => TicketStatus::Open,
        'channel' => TicketChannel::Web,
        'reporter_name' => 'Reporter',
        'is_anonymous' => false,
        'escalation_level' => EscalationLevel::CustomerCare,
        'third_party_dependent' => false,
        'sla_first_response_at' => now()->addDays(5),
        'sla_resolution_at' => now()->addDays(30),
    ], $overrides));
}

it('is statutory and cannot be snoozed', function (): void {
    expect($this->provider->statutory())->toBeTrue();
});

it('counts an unsettled ticket past its acknowledgement SLA', function (): void {
    $ticket = grievanceTicket(['sla_acknowledgement_at' => now()->subHour(), 'acknowledged_at' => null]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($ticket->id);
});

it('counts an unsettled ticket past its resolution SLA even once acknowledged', function (): void {
    grievanceTicket([
        'sla_acknowledgement_at' => now()->subDays(2),
        'acknowledged_at' => now()->subDays(2),
        'sla_first_response_at' => now()->subDays(2),
        'first_response_at' => now()->subDays(2),
        'sla_resolution_at' => now()->subHour(),
        'resolved_at' => null,
    ]);

    expect($this->provider->count())->toBe(1);
});

it('ignores a ticket whose SLAs are all met or not yet due', function (): void {
    grievanceTicket([
        'sla_acknowledgement_at' => now()->addDay(),
        'sla_first_response_at' => now()->addDay(),
        'sla_resolution_at' => now()->addDay(),
    ]);

    expect($this->provider->count())->toBe(0);
});

it('ignores a resolved ticket even if a clock reads as overdue', function (): void {
    grievanceTicket([
        'status' => TicketStatus::Resolved,
        'sla_resolution_at' => now()->subDay(),
        'resolved_at' => now(),
    ]);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed ticket on principle, though the UI offers no snooze control', function (): void {
    $ticket = grievanceTicket(['sla_acknowledgement_at' => now()->subHour(), 'acknowledged_at' => null]);

    ActionCenterSnooze::create([
        'action_key' => 'grievance.sla_due_or_breached',
        'subject_type' => 'grievance_ticket',
        'subject_id' => $ticket->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Escalation already in progress.',
    ]);

    expect($this->provider->count())->toBe(0);
});
