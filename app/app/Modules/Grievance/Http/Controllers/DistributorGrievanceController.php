<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Http\Controllers;

use App\Modules\Grievance\DTOs\FileGrievanceData;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Models\TicketAttachment;
use App\Modules\Grievance\Rules\NoRawGovernmentId;
use App\Modules\Grievance\Services\GrievanceService;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use App\Modules\Grievance\Support\GrievanceAttachmentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Dashboard → Help → Raise a Grievance", the in-app route promised at
 * `/p/grievance` §1 and §3.1.
 *
 * Every read is scoped to the signed-in distributor's own tickets. A
 * distributor must never be able to page through another distributor's
 * complaints — several grievance categories (ethics, genealogy disputes) are
 * complaints *about* other distributors.
 */
final class DistributorGrievanceController extends Controller
{
    public function __construct(
        private readonly GrievanceService $grievances,
        private readonly GrievanceSettingsService $settings,
        private readonly GrievanceAttachmentStore $attachments,
    ) {}

    public function index(Request $request): View
    {
        $distributor = $request->user()?->distributor;

        if ($distributor === null) {
            throw new NotFoundHttpException;
        }

        return view('grievance.my.index', [
            'tickets' => Ticket::where('distributor_id', $distributor->id)
                ->orderByDesc('created_at')
                ->paginate(15),
        ]);
    }

    public function create(Request $request): View
    {
        $distributor = $request->user()?->distributor;

        if ($distributor === null) {
            throw new NotFoundHttpException;
        }

        return view('grievance.my.create', [
            'categories' => TicketCategory::selectable(),
            'maxAttachments' => $this->settings->attachmentMaxCount(),
            'maxKilobytes' => $this->settings->attachmentMaxKilobytes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $distributor = $user?->distributor;

        if ($distributor === null) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000', new NoRawGovernmentId],
            'category' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (TicketCategory $c): string => $c->value,
                TicketCategory::selectable()
            ))],
            'attachments' => ['nullable', 'array', 'max:'.$this->settings->attachmentMaxCount()],
            'attachments.*' => [
                'file',
                'max:'.$this->settings->attachmentMaxKilobytes(),
                'mimes:pdf,jpg,jpeg,png,webp,heic',
            ],
        ], [
            'attachments.*.max' => 'Each file must be under '.($this->settings->attachmentMaxKilobytes() / 1024).' MB.',
        ], [
            'body' => 'complaint',
        ]);

        $ticket = $this->grievances->file(new FileGrievanceData(
            subject: $validated['subject'],
            body: $validated['body'],
            category: TicketCategory::from($validated['category']),
            channel: TicketChannel::Web,
            // Name and phone live on `users`, not `distributors`.
            reporterName: $user->full_name,
            reporterEmail: $user->email,
            reporterPhone: $user->phone_e164,
            isAnonymous: false,
            distributorId: $distributor->id,
            recordedByUserId: $user->id,
        ));

        $this->attachments->storeMany($ticket, $request->file('attachments', []), $user->id);

        return redirect()
            ->route('my.grievances.show', $ticket->id)
            ->with('status', 'Your grievance has been registered as '.$ticket->ticket_no.'.');
    }

    public function show(Request $request, int $id): View
    {
        $ticket = $this->ownedTicket($request, $id);

        return view('grievance.my.show', [
            'ticket' => $ticket,
            'timeline' => $ticket->events
                ->filter(fn ($event) => $event->kind->isVisibleToComplainant())
                ->sortBy('created_at'),
        ]);
    }

    public function reply(Request $request, int $id): RedirectResponse
    {
        $ticket = $this->ownedTicket($request, $id);

        if (! $ticket->status->acceptsComplainantReply()) {
            return back()->withErrors([
                'note' => 'This grievance is closed. Please raise a fresh one, quoting '.$ticket->ticket_no.'.',
            ]);
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000', new NoRawGovernmentId],
            'request_escalation' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:'.$this->settings->attachmentMaxCount()],
            'attachments.*' => [
                'file',
                'max:'.$this->settings->attachmentMaxKilobytes(),
                'mimes:pdf,jpg,jpeg,png,webp,heic',
            ],
        ]);

        $escalate = (bool) ($validated['request_escalation'] ?? false);

        $this->grievances->addComplainantReply($ticket, $validated['note'], $escalate);
        $this->attachments->storeMany($ticket, $request->file('attachments', []), $request->user()?->id);

        return back()->with('status', $escalate
            ? 'Your reply has been added and the grievance has been escalated.'
            : 'Your reply has been added.');
    }

    /**
     * Streams a complainant's own evidence back to them. Never a public URL:
     * these files are on the private bucket precisely because they hold PII.
     */
    public function streamAttachment(Request $request, int $id, int $attachmentId): RedirectResponse|StreamedResponse
    {
        $ticket = $this->ownedTicket($request, $id);

        $attachment = TicketAttachment::where('ticket_id', $ticket->id)
            ->where('id', $attachmentId)
            ->first();

        if ($attachment === null) {
            throw new NotFoundHttpException;
        }

        return $this->attachments->downloadResponse($attachment);
    }

    private function ownedTicket(Request $request, int $id): Ticket
    {
        $distributor = $request->user()?->distributor;

        if ($distributor === null) {
            throw new NotFoundHttpException;
        }

        $ticket = Ticket::with(['events.actor', 'attachments'])
            ->where('id', $id)
            ->where('distributor_id', $distributor->id)
            ->first();

        if ($ticket === null) {
            throw new NotFoundHttpException;
        }

        return $ticket;
    }
}
