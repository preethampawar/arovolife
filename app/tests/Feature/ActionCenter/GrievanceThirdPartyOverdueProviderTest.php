<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Compliance\GrievanceSensitiveThirdPartyOverdueProvider;
use App\Modules\ActionCenter\Providers\Compliance\GrievanceThirdPartyOverdueProvider;
use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Enums\TicketStatus;
use App\Modules\Grievance\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(GrievanceThirdPartyOverdueProvider::class);
});

/** @param array<string, mixed> $overrides */
function thirdPartyTicket(array $overrides = []): Ticket
{
    $n = random_int(100000, 999999);

    return Ticket::create(array_merge([
        'ticket_no' => "GR-TP-{$n}",
        'subject' => 'Test grievance',
        'body' => 'Body',
        'category' => TicketCategory::Order,
        'severity' => 'medium',
        'status' => TicketStatus::InProgress,
        'channel' => TicketChannel::Web,
        'reporter_name' => 'Reporter',
        'is_anonymous' => false,
        'escalation_level' => EscalationLevel::CustomerCare,
        'third_party_dependent' => true,
        'sla_first_response_at' => now()->addDays(5),
        'sla_resolution_at' => now()->addDays(60),
    ], $overrides));
}

it('counts a third-party ticket just past 15 days since its last update and ignores one just inside', function (): void {
    $overdue = thirdPartyTicket(['last_status_update_at' => now()->subDays(15)->subHour()]);
    thirdPartyTicket(['last_status_update_at' => now()->subDays(15)->addHour()]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('measures from created_at when no status update has ever been sent', function (): void {
    $ticket = thirdPartyTicket(['last_status_update_at' => null]);
    $ticket->forceFill(['created_at' => now()->subDays(16)])->saveQuietly();

    expect($this->provider->count())->toBe(1);
});

it('ignores a ticket that is not third-party dependent', function (): void {
    thirdPartyTicket(['third_party_dependent' => false, 'last_status_update_at' => now()->subDays(30)]);

    expect($this->provider->count())->toBe(0);
});

it('ignores a resolved third-party ticket', function (): void {
    thirdPartyTicket(['status' => TicketStatus::Resolved, 'last_status_update_at' => now()->subDays(30)]);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed ticket', function (): void {
    $ticket = thirdPartyTicket(['last_status_update_at' => now()->subDays(20)]);

    ActionCenterSnooze::create([
        'action_key' => 'grievance.third_party_overdue',
        'subject_type' => 'grievance_ticket',
        'subject_id' => $ticket->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Third party confirmed a response is imminent.',
    ]);

    expect($this->provider->count())->toBe(0);
});

/**
 * R-100, the third-party sibling. Same split, same reason: a privacy complaint
 * waiting 20 days on a third party is still a complaint nobody outside
 * compliance may be told exists.
 */
it('moves a privacy grievance to the compliance-only row', function (): void {
    $ticket = thirdPartyTicket([
        'category' => TicketCategory::Privacy,
        'last_status_update_at' => now()->subDays(20),
    ]);

    $sensitive = app(GrievanceSensitiveThirdPartyOverdueProvider::class);

    expect($this->provider->count())->toBe(0)
        ->and($sensitive->count())->toBe(1)
        ->and($sensitive->items()->first()->subjectId)->toBe($ticket->id);
});

it('keeps the same 15-day clock on both rows', function (): void {
    $sensitive = app(GrievanceSensitiveThirdPartyOverdueProvider::class);

    thirdPartyTicket(['category' => TicketCategory::Poaching, 'last_status_update_at' => now()->subDays(15)->addHour()]);

    // Inside the window on the general row, so it must be inside it here too.
    expect($sensitive->count())->toBe(0)
        ->and($sensitive->slaHours())->toBe($this->provider->slaHours());
});
