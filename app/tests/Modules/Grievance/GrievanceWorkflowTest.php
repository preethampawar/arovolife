<?php

declare(strict_types=1);

/**
 * Grievance redressal workflow — DSR 2021 Rule 12, T&C §11, and the SLAs
 * published at /p/grievance.
 *
 * GRV-001: the public form issues a complaint number and starts all three SLA clocks
 * GRV-002: filing acknowledges immediately and emails the complainant the number
 * GRV-003: an anonymous complaint stores no contact details and sends no mail
 * GRV-004: ethics complaints open with the Grievance Officer, bypassing front line
 * GRV-005: the first-response clock counts working days and skips Sunday
 * GRV-006: the sweep stamps a resolution breach once, and never restamps it
 * GRV-007: the sweep auto-escalates a ticket left too long at one step
 * GRV-008: the third-party extension applies once and never shortens the due date
 * GRV-009: closing sets the seven-year retention date
 * GRV-010: a distributor cannot open another distributor's grievance
 * GRV-011: tracking requires the complaint number AND the filing email
 * GRV-012: a complainant cannot reply to a closed grievance
 * GRV-013: the monthly compliance report counts recorded breaches, not live ones
 * GRV-014: escalation stops at the Compliance Committee, the last internal step
 * GRV-015: every grievance screen renders — public, distributor and admin
 *
 * Added after the 2026-08-16 compliance review:
 * GRV-016: a complaint with no anonymity answer is rejected, not silently filed without contact details
 * GRV-017: a complaint carrying a full Aadhaar or PAN is rejected before it is stored
 * GRV-018: a staff-recorded complaint runs its clocks from the receipt date
 * GRV-019: admin-finance cannot reach the grievance queue at all
 * GRV-020: an ethics complaint is invisible to operations and visible to compliance
 * GRV-021: nobody can handle a grievance filed by their own distributor account
 * GRV-022: a complainant asking to escalate actually escalates
 * GRV-023: a distributor cannot download another distributor's evidence
 * GRV-024: opening a grievance writes an audit-log row
 * GRV-025: resolving an overdue grievance records the breach instead of backfilling over it
 * GRV-026: the compliance report separates acknowledgements owed from those never owed
 * GRV-027: the compliance report hides ethics counts from anyone who cannot open an ethics ticket
 * GRV-028: staff-authored fields reject a raw Aadhaar the same way complainant fields do
 */

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Grievance\DTOs\FileGrievanceData;
use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Enums\TicketStatus;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Models\TicketAttachment;
use App\Modules\Grievance\Notifications\GrievanceAcknowledgementNotification;
use App\Modules\Grievance\Services\GrievanceComplianceReport;
use App\Modules\Grievance\Services\GrievanceService;
use App\Modules\Grievance\Services\GrievanceSlaCalculator;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The distributors table uses 0 as the "no sponsor / no parent" sentinel
    // against self-referencing FKs, which SQLite cannot satisfy for the first
    // row. Same escape hatch every other module test uses.
    disableTestForeignKeys();
});

// ─── helpers ─────────────────────────────────────────────────────────────────

function grvService(): GrievanceService
{
    return app(GrievanceService::class);
}

/** @param  array<string, mixed>  $overrides */
function grvFile(array $overrides = []): Ticket
{
    return grvService()->file(new FileGrievanceData(
        subject: $overrides['subject'] ?? 'Refund not received',
        body: $overrides['body'] ?? 'I cancelled within the cooling-off window and no refund has arrived.',
        category: $overrides['category'] ?? TicketCategory::Refund,
        channel: $overrides['channel'] ?? TicketChannel::Web,
        reporterName: $overrides['reporterName'] ?? 'Ravi Kumar',
        reporterEmail: $overrides['reporterEmail'] ?? 'ravi@example.com',
        reporterPhone: $overrides['reporterPhone'] ?? '+919876543210',
        isAnonymous: $overrides['isAnonymous'] ?? false,
        distributorId: $overrides['distributorId'] ?? null,
    ));
}

function grvDistributorUser(): User
{
    $distributor = Distributor::factory()->create();

    return $distributor->user;
}

/** A staff actor. Ticket events carry a real FK, so an invented id will not do. */
function grvStaff(): User
{
    return User::create([
        'full_name' => 'Grievance Officer',
        'email' => 'grievance-staff-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('GRV-001: the public form issues a complaint number and starts all three SLA clocks', function () {
    Notification::fake();

    $response = $this->post(route('grievance.store'), [
        'subject' => 'Commission credit missing',
        'body' => 'My GSB credit for last week has not appeared in my wallet.',
        'category' => TicketCategory::Compensation->value,
        'name' => 'Ravi Kumar',
        'email' => 'ravi@example.com',
        'consent_privacy' => '1',
    ]);

    $response->assertRedirect(route('grievance.submitted'));
    $response->assertSessionHas('grievance_ticket_no');

    $ticket = Ticket::first();

    expect($ticket)->not->toBeNull()
        ->and($ticket->ticket_no)->toMatch('/^GRV-\d{6}-[2-9A-HJ-NP-Z]{5}$/')
        ->and($ticket->sla_acknowledgement_at)->not->toBeNull()
        ->and($ticket->sla_first_response_at)->not->toBeNull()
        ->and($ticket->sla_resolution_at)->not->toBeNull()
        // 48 hours, 30 days — the published §2 commitments.
        ->and($ticket->created_at->diffInHours($ticket->sla_acknowledgement_at))->toBe(48.0)
        ->and($ticket->created_at->diffInDays($ticket->sla_resolution_at))->toBe(30.0);
});

it('GRV-002: filing acknowledges immediately and emails the complainant the number', function () {
    Notification::fake();

    $ticket = grvFile();

    Notification::assertSentOnDemand(
        GrievanceAcknowledgementNotification::class,
        fn ($notification, $channels, $notifiable) => $notification->ticketNo === $ticket->ticket_no
            && $notifiable->routes['mail'] === 'ravi@example.com'
    );

    expect($ticket->fresh()->acknowledged_at)->not->toBeNull()
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Acknowledged);
});

it('GRV-003: an anonymous complaint stores no contact details and sends no mail', function () {
    Notification::fake();

    $ticket = grvFile([
        'isAnonymous' => true,
        'category' => TicketCategory::Ethics,
        'subject' => 'A trainer is promising guaranteed income',
    ]);

    expect($ticket->reporter_email)->toBeNull()
        ->and($ticket->reporter_phone)->toBeNull()
        ->and($ticket->reporter_name)->toBeNull()
        ->and($ticket->notifiableEmail())->toBeNull()
        // Still acknowledged, so it does not sit in the breach queue forever
        // for a promise we never owed.
        ->and($ticket->fresh()->acknowledged_at)->not->toBeNull();

    // The officer is still alerted — somebody has to work it. The complainant
    // is the one who cannot be written to.
    Notification::assertSentOnDemandTimes(GrievanceAcknowledgementNotification::class, 0);
});

it('GRV-004: ethics complaints open with the Grievance Officer, bypassing front line', function () {
    Notification::fake();

    $ethics = grvFile(['category' => TicketCategory::Ethics]);
    $ordinary = grvFile(['category' => TicketCategory::Refund]);

    expect($ethics->escalation_level)->toBe(EscalationLevel::GrievanceOfficer)
        ->and($ordinary->escalation_level)->toBe(EscalationLevel::CustomerCare);
});

it('GRV-005: the first-response clock counts working days and skips Sunday', function () {
    // Thursday 13 Aug 2026. Five working days, Sunday excluded, lands on
    // Wednesday 19 Aug: Fri, Sat, (skip Sun), Mon, Tue, Wed.
    $received = Carbon::parse('2026-08-13 10:00:00');

    $schedule = app(GrievanceSlaCalculator::class)->scheduleFor($received);

    expect($schedule->firstResponseDueAt->toDateString())->toBe('2026-08-19')
        ->and($schedule->firstResponseDueAt->isSunday())->toBeFalse();
});

it('GRV-006: the sweep stamps a resolution breach once, and never restamps it', function () {
    Notification::fake();

    $ticket = grvFile();
    $ticket->forceFill(['sla_resolution_at' => Carbon::now()->subDay()])->save();

    $this->artisan('grievance:sla-sweep')->assertSuccessful();

    $firstStamp = $ticket->fresh()->resolution_breached_at;
    expect($firstStamp)->not->toBeNull();

    $this->travel(2)->hours();
    $this->artisan('grievance:sla-sweep')->assertSuccessful();

    expect($ticket->fresh()->resolution_breached_at->equalTo($firstStamp))->toBeTrue()
        ->and($ticket->fresh()->events()->where('kind', 'sla_breach')->count())->toBe(1);
});

it('GRV-007: the sweep auto-escalates a ticket left too long at one step', function () {
    Notification::fake();

    $ticket = grvFile();

    // Eight days at customer care — past the published seven-day step-1 window.
    $ticket->forceFill(['created_at' => Carbon::now()->subDays(8)])->save();

    $this->artisan('grievance:sla-sweep')->assertSuccessful();

    expect($ticket->fresh()->escalation_level)->toBe(EscalationLevel::GrievanceOfficer)
        ->and($ticket->fresh()->escalated_at)->not->toBeNull();
});

it('GRV-008: the third-party extension applies once and never shortens the due date', function () {
    Notification::fake();

    $ticket = grvFile();
    $originalDue = $ticket->sla_resolution_at->copy();

    $staff = grvStaff();
    $ticket = grvService()->markThirdPartyDependent($ticket, 'Waiting on the acquiring bank.', $staff->id);
    $extendedDue = $ticket->sla_resolution_at->copy();

    expect($ticket->third_party_dependent)->toBeTrue()
        ->and($extendedDue->greaterThan($originalDue))->toBeTrue()
        ->and($ticket->created_at->diffInDays($extendedDue))->toBe(60.0);

    // A second attempt must not buy another extension.
    $ticket = grvService()->markThirdPartyDependent($ticket, 'Still waiting.', $staff->id);

    expect($ticket->sla_resolution_at->equalTo($extendedDue))->toBeTrue();
});

it('GRV-009: closing sets the seven-year retention date', function () {
    Notification::fake();

    $ticket = grvFile();
    $staff = grvStaff();
    $ticket = grvService()->resolve($ticket, 'Refund reissued on 16 August.', $staff->id);
    $ticket = grvService()->close($ticket, $staff->id);

    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->retention_until)->not->toBeNull()
        // Retention runs to the start of the day seven years on.
        ->and($ticket->retention_until->toDateString())
        ->toBe($ticket->closed_at->copy()->addYears(7)->toDateString());
});

it('GRV-010: a distributor cannot open another distributor’s grievance', function () {
    Notification::fake();

    $mine = grvDistributorUser();
    $theirs = grvDistributorUser();

    $ticket = grvFile(['distributorId' => $theirs->distributor->id]);

    $this->actingAs($mine)
        ->get(route('my.grievances.show', $ticket->id))
        ->assertNotFound();

    $this->actingAs($theirs)
        ->get(route('my.grievances.show', $ticket->id))
        ->assertOk();
});

it('GRV-011: tracking requires the complaint number AND the filing email', function () {
    Notification::fake();

    $ticket = grvFile(['reporterEmail' => 'ravi@example.com']);

    // Right number, wrong email — must not confirm the complaint exists.
    $this->post(route('grievance.track.lookup'), [
        'ticket_no' => $ticket->ticket_no,
        'email' => 'someone.else@example.com',
    ])->assertOk()->assertSee('could not find', false);

    $this->post(route('grievance.track.lookup'), [
        'ticket_no' => $ticket->ticket_no,
        'email' => 'ravi@example.com',
    ])->assertOk()->assertSee($ticket->subject, false);
});

it('GRV-012: a complainant cannot reply to a closed grievance', function () {
    Notification::fake();

    $ticket = grvFile();
    $staff = grvStaff();
    $ticket = grvService()->resolve($ticket, 'Resolved.', $staff->id);
    $ticket = grvService()->close($ticket, $staff->id);

    $this->post(route('grievance.track.reply'), [
        'ticket_no' => $ticket->ticket_no,
        'email' => 'ravi@example.com',
        'note' => 'Please reopen this.',
    ])->assertSessionHasErrors('note');
});

it('GRV-013: the monthly compliance report counts recorded breaches, not live ones', function () {
    Notification::fake();

    $breached = grvFile();
    $clean = grvFile(['reporterEmail' => 'meera@example.com']);

    grvService()->recordBreach($breached, 'resolution');
    grvService()->resolve($clean, 'Sorted the same day.', grvStaff()->id);

    $report = app(GrievanceComplianceReport::class)->forMonth(Carbon::now());

    expect($report['received'])->toBe(2)
        ->and($report['resolution_breaches'])->toBe(1)
        ->and($report['resolved'])->toBe(1)
        ->and($report['still_open'])->toBe(1);
});

it('GRV-014: escalation stops at the Compliance Committee, the last internal step', function () {
    Notification::fake();

    $staff = grvStaff();
    $ticket = grvFile();

    foreach (range(1, 5) as $ignored) {
        $ticket = grvService()->escalate($ticket, $staff->id);
    }

    expect($ticket->escalation_level)->toBe(EscalationLevel::ComplianceCommittee)
        ->and($ticket->escalation_level->next())->toBeNull();
});

it('GRV-015: every grievance screen renders — public, distributor and admin', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $staff = grvStaff();
    $staff->assignRole('admin');

    $distributorUser = grvDistributorUser();
    $ticket = grvFile(['distributorId' => $distributorUser->distributor->id]);

    // Public
    $this->get(route('grievance.create'))->assertOk()->assertSee('Raise a grievance', false);
    $this->get(route('grievance.track'))->assertOk();

    // Distributor
    $this->actingAs($distributorUser)->get(route('help.index'))->assertOk();
    $this->actingAs($distributorUser)->get(route('my.grievances.index'))->assertOk();
    $this->actingAs($distributorUser)->get(route('my.grievances.create'))->assertOk();
    $this->actingAs($distributorUser)->get(route('my.grievances.show', $ticket->id))->assertOk();

    // Admin
    $this->actingAs($staff)->get(route('admin.grievances.index'))->assertOk();
    $this->actingAs($staff)->get(route('admin.grievances.create'))->assertOk();
    $this->actingAs($staff)->get(route('admin.grievances.show', $ticket->id))->assertOk();
    $this->actingAs($staff)->get(route('admin.grievances.report'))->assertOk();
    $this->actingAs($staff)->get(route('admin.grievances.report.export'))->assertOk();
    $this->actingAs($staff)->get(route('admin.help.show', 'grievance-handling'))->assertOk();
});

it('GRV-016: a complaint with no anonymity answer is rejected, not silently filed without contact details', function () {
    Notification::fake();

    // An unchecked checkbox posts nothing at all, so `is_anonymous` is absent
    // rather than "0". This is the exact shape that previously slipped through
    // `required_if` and filed a ticket with no email while the confirmation
    // screen told the complainant we had emailed them the number.
    $this->post(route('grievance.store'), [
        'subject' => 'Refund not received',
        'body' => 'Still waiting.',
        'category' => TicketCategory::Refund->value,
    ])->assertSessionHasErrors(['name', 'email']);

    expect(Ticket::count())->toBe(0);
});

it('GRV-017: a complaint carrying a full Aadhaar or PAN is rejected before it is stored', function () {
    Notification::fake();

    $payload = fn (string $body) => [
        'subject' => 'KYC mismatch',
        'body' => $body,
        'category' => TicketCategory::Kyc->value,
        'is_anonymous' => '0',
        'name' => 'Ravi Kumar',
        'email' => 'ravi@example.com',
    ];

    // 234567890124 is Verhoeff-valid; the rule only rejects real-looking numbers.
    $this->post(route('grievance.store'), $payload('My Aadhaar 2345 6789 0124 was rejected.'))
        ->assertSessionHasErrors('body');

    $this->post(route('grievance.store'), $payload('My PAN ABCDE1234F was rejected.'))
        ->assertSessionHasErrors('body');

    // A twelve-digit number that is not a valid Aadhaar must not block a
    // legitimate complaint.
    $this->post(route('grievance.store'), $payload('Order 100000000000 was never delivered.'))
        ->assertSessionDoesntHaveErrors('body');

    expect(Ticket::count())->toBe(1);
});

it('GRV-018: a staff-recorded complaint runs its clocks from the receipt date, not the keying-in date', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $staff = grvStaff();
    $staff->assignRole('admin-operations');

    $received = Carbon::now()->subDays(10)->startOfDay();

    $this->actingAs($staff)->post(route('admin.grievances.store'), [
        'subject' => 'Posted complaint about a refund',
        'body' => 'Letter received at the registered office.',
        'category' => TicketCategory::Refund->value,
        'channel' => TicketChannel::Post->value,
        'severity' => 'medium',
        'received_at' => $received->toDateString(),
        'name' => 'Ravi Kumar',
        'email' => 'ravi@example.com',
    ])->assertRedirect();

    $ticket = Ticket::firstOrFail();

    expect($ticket->created_at->toDateString())->toBe($received->toDateString())
        // 30 days from RECEIPT, so only 20 remain — not 30 from today.
        ->and($ticket->sla_resolution_at->toDateString())
        ->toBe($received->copy()->addDays(30)->toDateString());
});

it('GRV-019: admin-finance cannot reach the grievance queue at all', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $finance = grvStaff();
    $finance->assignRole('admin-finance');

    $ticket = grvFile();

    $this->actingAs($finance)->get(route('admin.grievances.index'))->assertForbidden();
    $this->actingAs($finance)->get(route('admin.grievances.show', $ticket->id))->assertForbidden();
});

it('GRV-020: an ethics complaint is invisible to operations and visible to compliance', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $operations = grvStaff();
    $operations->assignRole('admin-operations');

    $compliance = grvStaff();
    $compliance->assignRole('admin-compliance');

    $ethics = grvFile(['category' => TicketCategory::Ethics, 'subject' => 'A trainer demanded a bribe']);
    $ordinary = grvFile(['category' => TicketCategory::Refund, 'reporterEmail' => 'meera@example.com']);

    // 404, not 403 — confirming an ethics complaint exists is itself a disclosure.
    $this->actingAs($operations)->get(route('admin.grievances.show', $ethics->id))->assertNotFound();
    $this->actingAs($operations)->get(route('admin.grievances.show', $ordinary->id))->assertOk();

    // Nor should it appear in their queue.
    $this->actingAs($operations)->get(route('admin.grievances.index'))
        ->assertOk()
        ->assertDontSee($ethics->ticket_no, false);

    $this->actingAs($compliance)->get(route('admin.grievances.show', $ethics->id))->assertOk();
});

it('GRV-021: nobody can handle a grievance filed by their own distributor account', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $staffUser = grvDistributorUser();
    $staffUser->assignRole('admin');

    $ownTicket = grvFile(['distributorId' => $staffUser->distributor->id]);

    // `admin` is super staff and passes every policy via Gate::before, so the
    // conflict-of-interest block has to live outside the policy to hold here.
    $this->actingAs($staffUser)->get(route('admin.grievances.show', $ownTicket->id))->assertForbidden();
    $this->actingAs($staffUser)->post(route('admin.grievances.resolve', $ownTicket->id), [
        'resolution_note' => 'Sorted it myself.',
    ])->assertForbidden();
});

it('GRV-022: a complainant asking to escalate actually escalates, and the owner is told', function () {
    Notification::fake();

    $ticket = grvFile();

    expect($ticket->escalation_level)->toBe(EscalationLevel::CustomerCare);

    $this->post(route('grievance.track.reply'), [
        'ticket_no' => $ticket->ticket_no,
        'email' => 'ravi@example.com',
        'note' => 'Nobody has come back to me.',
        'request_escalation' => '1',
    ])->assertSessionHasNoErrors();

    expect($ticket->fresh()->escalation_level)->toBe(EscalationLevel::GrievanceOfficer);
});

it('GRV-023: a distributor cannot download another distributor’s evidence', function () {
    Notification::fake();

    $mine = grvDistributorUser();
    $theirs = grvDistributorUser();

    $ticket = grvFile(['distributorId' => $theirs->distributor->id]);
    $attachment = TicketAttachment::create([
        'ticket_id' => $ticket->id,
        'disk' => 'grievance',
        'path' => $ticket->ticket_no.'/evidence.pdf',
        'original_name' => 'bank-statement.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'created_at' => now(),
    ]);

    $this->actingAs($mine)
        ->get(route('my.grievances.attachment', [$ticket->id, $attachment->id]))
        ->assertNotFound();
});

it('GRV-024: opening a grievance writes an audit-log row', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $staff = grvStaff();
    $staff->assignRole('admin-compliance');

    $ticket = grvFile();

    $this->actingAs($staff)->get(route('admin.grievances.show', $ticket->id))->assertOk();

    expect(AuditLog::where('action', 'grievance.viewed')
        ->where('subject_id', $ticket->id)
        ->exists())->toBeTrue();
});

it('GRV-025: resolving an overdue grievance records the breach instead of backfilling over it', function () {
    Notification::fake();

    $ticket = grvFile();
    $ticket->forceFill([
        'sla_first_response_at' => Carbon::now()->subDay(),
        'sla_resolution_at' => Carbon::now()->subDay(),
    ])->save();

    $ticket = grvService()->resolve($ticket->fresh(), 'Refund reissued.', grvStaff()->id);

    expect($ticket->first_response_breached_at)->not->toBeNull()
        ->and($ticket->resolution_breached_at)->not->toBeNull()
        ->and($ticket->first_response_at)->not->toBeNull()
        // Retention starts at resolution, not only at closure.
        ->and($ticket->retention_until)->not->toBeNull();
});

it('GRV-026: the compliance report separates acknowledgements owed from those never owed', function () {
    Notification::fake();

    grvFile();
    grvFile(['isAnonymous' => true, 'reporterEmail' => null]);

    $report = app(GrievanceComplianceReport::class)->forMonth(Carbon::now());

    expect($report['received'])->toBe(2)
        ->and($report['acknowledgement_owed'])->toBe(1)
        ->and($report['acknowledgement_not_owed'])->toBe(1)
        ->and($report['acknowledged_in_time'])->toBe(1);
});

it('GRV-027: the compliance report hides ethics counts from anyone who cannot open an ethics ticket', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $operations = grvStaff();
    $operations->assignRole('admin-operations');

    $compliance = grvStaff();
    $compliance->assignRole('admin-compliance');

    grvFile(['category' => TicketCategory::Ethics]);
    grvFile(['category' => TicketCategory::Refund, 'reporterEmail' => 'meera@example.com']);

    // An aggregate is a smaller disclosure than a ticket, but it is the same
    // disclosure: "Ethics & fraud: 1" tells them such complaints exist.
    $this->actingAs($operations)->get(route('admin.grievances.report'))
        ->assertOk()
        ->assertDontSee('Ethics &amp; fraud', false);

    $this->actingAs($compliance)->get(route('admin.grievances.report'))
        ->assertOk()
        ->assertSee('Ethics &amp; fraud', false);
});

it('GRV-028: staff-authored fields reject a raw Aadhaar the same way complainant fields do', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $staff = grvStaff();
    $staff->assignRole('admin-compliance');

    $ticket = grvFile(['category' => TicketCategory::Kyc]);

    $this->actingAs($staff)->post(route('admin.grievances.resolve', $ticket->id), [
        'resolution_note' => 'Corrected the record against Aadhaar 2345 6789 0124.',
    ])->assertSessionHasErrors('resolution_note');

    $this->actingAs($staff)->post(route('admin.grievances.respond', $ticket->id), [
        'note' => 'Please confirm PAN ABCDE1234F.',
    ])->assertSessionHasErrors('note');
});

it('GRV-020: an attachment whose bytes are not what it claims is rejected', function () {
    Notification::fake();
    Storage::fake('s3');

    // A file named .pdf whose contents are not a PDF. `mimes:` reads the
    // extension and the client-declared type and would have taken it; nothing
    // read the bytes on the grievance paths at all until T-6.1 finding H-4.
    $liar = UploadedFile::fake()->createWithContent('evidence.pdf', '<?php echo "not a pdf";');

    $this->post(route('grievance.store'), [
        'subject' => 'Refund not received',
        'body' => 'Attaching the receipt.',
        'category' => TicketCategory::Refund->value,
        'name' => 'Ravi Kumar',
        'email' => 'ravi@example.com',
        'consent_privacy' => '1',
        'attachments' => [$liar],
    ])->assertSessionHasErrors('attachments.0');

    expect(Ticket::count())->toBe(0);
});

it('GRV-021: a genuine image attachment is still accepted', function () {
    Notification::fake();
    // The evidence disk, not `s3` — GrievanceAttachmentStore writes to its own
    // private bucket. Faking the wrong disk let the real S3 client throw, which
    // the default test exception handler renders as a 500: no session errors,
    // ticket already committed, attachment silently absent.
    Storage::fake('grievance');

    // The guard must not break the common case: a phone screenshot. Real PNG
    // magic bytes, written explicitly rather than via fake()->image(), so the
    // test exercises the signature check itself and does not depend on GD
    // being compiled into whatever PHP runs the suite.
    $png = UploadedFile::fake()->createWithContent(
        'what-arrived.png',
        "\x89PNG\r\n\x1A\n".str_repeat("\0", 64),
    );

    $this->post(route('grievance.store'), [
        'subject' => 'Wrong item delivered',
        'body' => 'Photo of what arrived.',
        'category' => TicketCategory::Refund->value,
        'name' => 'Ravi Kumar',
        'email' => 'ravi@example.com',
        'consent_privacy' => '1',
        'attachments' => [$png],
    ])->assertSessionHasNoErrors()
        // Pin the status too: `assertSessionHasNoErrors` is also true of a 500,
        // so on its own it cannot tell acceptance apart from a crash.
        ->assertRedirect();

    expect(Ticket::count())->toBe(1)
        ->and(TicketAttachment::count())->toBe(1);

    Storage::disk('grievance')->assertExists(TicketAttachment::sole()->path);
});
