<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Grievance\DTOs\FileGrievanceData;
use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Enums\TicketEventKind;
use App\Modules\Grievance\Enums\TicketStatus;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Models\TicketAttachment;
use App\Modules\Grievance\Rules\NoRawGovernmentId;
use App\Modules\Grievance\Services\GrievanceService;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use App\Modules\Grievance\Support\GrievanceAttachmentStore;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The staff-side grievance queue — the "one tracker" every intake channel in
 * policy §3 is promised to land in.
 *
 * Reading a grievance is audit-logged, the same posture the contact inbox and
 * the KYC queue take: a complaint body is PII, and often names another
 * distributor.
 */
final class AdminGrievanceController extends Controller
{
    public function __construct(
        private readonly GrievanceService $grievances,
        private readonly GrievanceSettingsService $settings,
        private readonly GrievanceAttachmentStore $attachments,
    ) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'unsettled');
        $category = (string) $request->query('category', '');
        $level = (string) $request->query('level', '');
        $search = trim((string) $request->query('q', ''));

        $query = Ticket::query()->with('assignedTo');

        $this->applyVisibility($query);
        $this->applyStatusFilter($query, $status);

        if ($category !== '' && TicketCategory::tryFrom($category) !== null) {
            $query->where('category', $category);
        }

        if ($level !== '' && EscalationLevel::tryFrom((int) $level) !== null) {
            $query->where('escalation_level', (int) $level);
        }

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('ticket_no', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('reporter_email', 'like', "%{$search}%")
                    ->orWhere('reporter_phone', 'like', "%{$search}%");
            });
        }

        return view('admin.grievances.index', [
            'tickets' => $query->orderByRaw('sla_resolution_at is null, sla_resolution_at asc')
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
            'category' => $category,
            'level' => $level,
            'search' => $search,
            'categories' => TicketCategory::cases(),
            'levels' => EscalationLevel::cases(),
            'counts' => $this->queueCounts(),
        ]);
    }

    public function show(int $id): View
    {
        $ticket = Ticket::with(['events.actor', 'attachments', 'distributor', 'order', 'assignedTo'])
            ->find($id);

        if ($ticket === null) {
            throw new NotFoundHttpException;
        }

        $this->guard($ticket);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'grievance.viewed',
            'subject_type' => 'ticket',
            'subject_id' => $ticket->id,
            'details' => ['ticket_no' => $ticket->ticket_no, 'category' => $ticket->category->value],
        ]);

        return view('admin.grievances.show', [
            'ticket' => $ticket,
            'timeline' => $ticket->events->sortBy('created_at'),
            'nextLevel' => $ticket->escalation_level->next(),
            'statusUpdateDueAt' => $ticket->third_party_dependent && $ticket->last_status_update_at !== null
                ? $ticket->last_status_update_at->copy()->addDays($this->settings->statusUpdateIntervalDays())
                : null,
        ]);
    }

    /**
     * Staff-recorded intake for the phone, post and walk-in channels
     * (policy §3.3–3.5). Without this, "every channel routes into the same
     * ticketing system" is a promise the software cannot keep.
     */
    public function create(): View
    {
        return view('admin.grievances.create', [
            'categories' => TicketCategory::selectable(),
            'channels' => TicketChannel::staffRecordable(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000', new NoRawGovernmentId],
            // Policy §2 measures every clock "of receipt". A postal complaint
            // keyed in on day 10 must still be scored from the day it arrived,
            // or the compliance report under-reports breaches to the regulator.
            'received_at' => ['required', 'date', 'before_or_equal:now', 'after:'.now()->subYear()->toDateString()],
            'category' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (TicketCategory $c): string => $c->value,
                TicketCategory::selectable()
            ))],
            'channel' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (TicketChannel $c): string => $c->value,
                TicketChannel::staffRecordable()
            ))],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'adn' => ['nullable', 'string', 'max:16'],
            'is_anonymous' => ['nullable', 'boolean'],
            // Policy §3.4 promises postal complaints are "scanned, time-stamped
            // at receipt". Without an upload here the scan exists nowhere in
            // the record.
            'attachments' => ['nullable', 'array', 'max:'.$this->settings->attachmentMaxCount()],
            'attachments.*' => [
                'file',
                'max:'.$this->settings->attachmentMaxKilobytes(),
                'mimes:pdf,jpg,jpeg,png,webp,heic',
            ],
        ]);

        $distributorId = null;

        if (! empty($validated['adn'])) {
            $distributorId = Distributor::where('adn', $validated['adn'])->value('id');

            if ($distributorId === null) {
                return back()->withInput()->withErrors(['adn' => 'No distributor with that ADN.']);
            }
        }

        $ticket = $this->grievances->file(new FileGrievanceData(
            subject: $validated['subject'],
            body: $validated['body'],
            category: TicketCategory::from($validated['category']),
            channel: TicketChannel::from($validated['channel']),
            reporterName: $validated['name'] ?? null,
            reporterEmail: $validated['email'] ?? null,
            reporterPhone: $validated['phone'] ?? null,
            isAnonymous: (bool) ($validated['is_anonymous'] ?? false),
            distributorId: $distributorId,
            recordedByUserId: (int) Auth::id(),
            severity: $validated['severity'],
            receivedAt: Carbon::parse($validated['received_at']),
        ));

        $this->attachments->storeMany($ticket, $request->file('attachments', []), (int) Auth::id());

        $this->audit($ticket, 'grievance.recorded', [
            'channel' => $ticket->channel->value,
            'received_at' => $ticket->created_at->toDateString(),
        ]);

        return redirect()->route('admin.grievances.show', $ticket->id)
            ->with('status', 'Complaint recorded as '.$ticket->ticket_no.'.');
    }

    public function respond(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->findOrFail($id);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000', new NoRawGovernmentId],
        ]);

        $this->grievances->addStaffResponse($ticket, $validated['note'], (int) Auth::id());
        $this->audit($ticket, 'grievance.responded');

        return back()->with('status', 'Response recorded.');
    }

    /**
     * An investigation note the complainant never sees.
     *
     * Kept in the ticket timeline rather than someone's notebook so the
     * statutory register actually shows what was investigated, while a note
     * that names a third party stays out of the complainant's view.
     */
    public function internalNote(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->findOrFail($id);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000', new NoRawGovernmentId],
        ]);

        $this->grievances->recordEvent(
            $ticket,
            TicketEventKind::InternalNote,
            actorUserId: (int) Auth::id(),
            note: $validated['note'],
        );

        $this->audit($ticket, 'grievance.internal_note_added');

        return back()->with('status', 'Internal note added. The complainant cannot see it.');
    }

    public function assign(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->findOrFail($id);

        $validated = $request->validate([
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = $validated['assigned_to_user_id'] ?? null;

        $this->grievances->assign($ticket, $assignee === null ? null : (int) $assignee, (int) Auth::id());
        $this->audit($ticket, 'grievance.assigned', ['assigned_to_user_id' => $assignee]);

        return back()->with('status', $assignee === null ? 'Assignment cleared.' : 'Ticket assigned.');
    }

    public function escalate(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->findOrFail($id);

        if ($ticket->escalation_level->next() === null) {
            return back()->withErrors([
                'escalation' => 'This grievance is already with the Compliance Committee, the last internal step. Steps 5 to 8 of the published matrix are external authorities the complainant approaches directly.',
            ]);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000', new NoRawGovernmentId],
        ]);

        $ticket = $this->grievances->escalate($ticket, (int) Auth::id(), $validated['reason'] ?? null);
        $this->audit($ticket, 'grievance.escalated', ['to_level' => $ticket->escalation_level->value]);

        return back()->with('status', 'Escalated to the '.$ticket->escalation_level->label().'.');
    }

    public function markThirdParty(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->findOrFail($id);

        if ($ticket->third_party_dependent) {
            return back()->withErrors([
                'third_party' => 'This grievance is already flagged as third-party dependent. The extension may only be applied once.',
            ]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000', new NoRawGovernmentId],
        ]);

        $ticket = $this->grievances->markThirdPartyDependent($ticket, $validated['reason'], (int) Auth::id());
        $this->audit($ticket, 'grievance.third_party_extension', [
            'resolution_due' => $ticket->sla_resolution_at?->toDateString(),
        ]);

        return back()->with('status', 'Resolution window extended. A progress update is now owed every '.$this->settings->statusUpdateIntervalDays().' days.');
    }

    public function publishStatusUpdate(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->findOrFail($id);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000', new NoRawGovernmentId],
        ]);

        $this->grievances->publishStatusUpdate($ticket, $validated['note'], (int) Auth::id());
        $this->audit($ticket, 'grievance.status_update_sent');

        return back()->with('status', 'Progress update sent to the complainant.');
    }

    public function resolve(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->findOrFail($id);

        $validated = $request->validate([
            'resolution_note' => ['required', 'string', 'max:5000', new NoRawGovernmentId],
        ]);

        $this->grievances->resolve($ticket, $validated['resolution_note'], (int) Auth::id());
        $this->audit($ticket, 'grievance.resolved');

        return back()->with('status', 'Grievance resolved. The complainant has been told how to escalate if they disagree.');
    }

    public function close(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->findOrFail($id);

        if ($ticket->status !== TicketStatus::Resolved) {
            return back()->withErrors([
                'close' => 'Record the resolution before closing — a closed grievance with no stated outcome is not a record we can defend.',
            ]);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000', new NoRawGovernmentId],
        ]);

        $ticket = $this->grievances->close($ticket, (int) Auth::id(), $validated['note'] ?? null);
        $this->audit($ticket, 'grievance.closed', ['retention_until' => $ticket->retention_until?->toDateString()]);

        return back()->with('status', 'Grievance closed. Records retained until '.$ticket->retention_until?->format('d M Y').'.');
    }

    public function streamAttachment(int $id, int $attachmentId): RedirectResponse|StreamedResponse
    {
        $ticket = $this->findOrFail($id);

        $attachment = TicketAttachment::where('ticket_id', $ticket->id)
            ->where('id', $attachmentId)
            ->first();

        if ($attachment === null) {
            throw new NotFoundHttpException;
        }

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'grievance.attachment_viewed',
            'subject_type' => 'ticket_attachment',
            'subject_id' => $attachment->id,
            'details' => ['ticket_no' => $ticket->ticket_no, 'file' => $attachment->original_name],
        ]);

        return $this->attachments->downloadResponse($attachment);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @param  Builder<Ticket>  $query */
    private function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'all' => null,
            'breached' => $query->breached(),
            'unacknowledged' => $query->whereNull('acknowledged_at'),
            default => TicketStatus::tryFrom($status) !== null
                ? $query->where('status', $status)
                : $query->unsettled(),
        };
    }

    /**
     * Hide from the list what the viewer could not open anyway.
     *
     * Without this, an operations officer sees "Ethics & fraud — 3" in the
     * queue and every row 404s. The count alone is a disclosure: it tells them
     * ethics complaints exist and roughly when.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applyVisibility(Builder $query): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        if (! $user->can('compliance.discipline')) {
            $query->whereNotIn('category', [
                TicketCategory::Ethics->value,
                TicketCategory::Privacy->value,
            ]);
        }

        // Never list a grievance the viewer filed themselves.
        $ownDistributorId = $user->distributor?->id;

        if ($ownDistributorId !== null) {
            $query->where(function (Builder $q) use ($ownDistributorId): void {
                $q->whereNull('distributor_id')->orWhere('distributor_id', '!=', $ownDistributorId);
            });
        }
    }

    /** @return array<string, int> */
    private function queueCounts(): array
    {
        $base = fn (): Builder => tap(Ticket::query(), fn (Builder $q) => $this->applyVisibility($q));

        return [
            'unsettled' => $base()->unsettled()->count(),
            'breached' => $base()->breached()->count(),
            'unacknowledged' => $base()->whereNull('acknowledged_at')->count(),
        ];
    }

    private function findOrFail(int $id): Ticket
    {
        $ticket = Ticket::find($id);

        if ($ticket === null) {
            throw new NotFoundHttpException;
        }

        $this->guard($ticket);

        return $ticket;
    }

    /**
     * Ticket-level authorisation.
     *
     * Two checks that route middleware cannot make, because both need the
     * ticket in hand: the sensitive-category restriction, and the
     * conflict-of-interest block.
     *
     * The own-complaint block is enforced here rather than left to the policy
     * because `Gate::before` waves super staff past every policy — and an
     * `admin` resolving their own complaint is exactly the thing a grievance
     * process exists to stop.
     */
    private function guard(Ticket $ticket): void
    {
        $user = Auth::user();

        if ($user === null) {
            throw new NotFoundHttpException;
        }

        if ($ticket->distributor_id !== null && $user->distributor?->id === $ticket->distributor_id) {
            abort(403, 'You cannot handle a grievance filed by your own distributor account. Ask another officer to take it.');
        }

        if (Gate::denies('view', $ticket)) {
            // 404 rather than 403 for the category restriction: telling someone
            // an ethics complaint exists but is not for their eyes is itself a
            // disclosure.
            throw new NotFoundHttpException;
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(Ticket $ticket, string $action, array $details = []): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => 'ticket',
            'subject_id' => $ticket->id,
            'details' => array_merge(['ticket_no' => $ticket->ticket_no], $details),
        ]);
    }
}
