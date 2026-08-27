<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Http\Controllers;

use App\Modules\Grievance\DTOs\FileGrievanceData;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Rules\NoRawGovernmentId;
use App\Modules\Grievance\Services\GrievanceService;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use App\Modules\Grievance\Support\GrievanceAttachmentStore;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Shared\Http\Rules\ScannedForMalware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * The structured public grievance form promised at `/p/grievance` §3.1 — the
 * policy page has been telling complainants this page would exist, and until
 * now it did not.
 *
 * Open to anyone: complainants include customers, prospective distributors and
 * third parties who have no account. Distributors get a richer, pre-filled
 * version at `/my/grievances/create`.
 */
final class PublicGrievanceController extends Controller
{
    public function __construct(
        private readonly GrievanceService $grievances,
        private readonly GrievanceSettingsService $settings,
        private readonly GrievanceAttachmentStore $attachments,
    ) {}

    public function create(): View
    {
        return view('grievance.create', [
            'categories' => TicketCategory::selectable(),
            'maxAttachments' => $this->settings->attachmentMaxCount(),
            'maxKilobytes' => $this->settings->attachmentMaxKilobytes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Per-IP, 6/hour — looser than the contact form's 3. This is a
        // statutory complaint channel, and an office or a CGNAT range shares
        // one IP; turning away a genuine complainant to stop spam is the wrong
        // trade on this particular form.
        $key = 'grievance:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 6)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withInput()->withErrors([
                'body' => "Too many submissions from this connection. Please try again in {$seconds} seconds, or write to grievance@arovolife.com.",
            ]);
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000', new NoRawGovernmentId],
            'category' => ['required', 'string', 'in:'.$this->selectableCategoryValues()],
            'is_anonymous' => ['nullable', 'boolean'],
            // Contact details are required UNLESS the complainant asks to stay
            // anonymous — policy §6.5 accepts anonymous complaints but warns
            // that no feedback can be given.
            //
            // `required_unless` rather than `required_if`: an unchecked HTML
            // checkbox posts nothing at all, so `required_if:is_anonymous,0`
            // never fires and every complainant could file with no contact
            // details while the confirmation screen told them we had emailed
            // the complaint number. The form also posts a hidden `0` now, but
            // the rule must not depend on that.
            'name' => ['required_unless:is_anonymous,1', 'nullable', 'string', 'max:120'],
            'email' => ['required_unless:is_anonymous,1', 'nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^(\+?91)?[6-9][0-9]{9}$/'],
            'attachments' => ['nullable', 'array', 'max:'.$this->settings->attachmentMaxCount()],
            'attachments.*' => [
                'file',
                'max:'.$this->settings->attachmentMaxKilobytes(),
                'mimes:pdf,jpg,jpeg,png,webp,heic',
                // The declared type is the client's claim; this reads the bytes.
                new ValidUploadedDocumentBytes(ValidUploadedDocumentBytes::EVIDENCE),
                new ScannedForMalware,
            ],
            // An acknowledgement, not a gate. DSR 2021 Rule 12 gives an
            // unconditional right to complain, and grievance handling is a
            // legal obligation under DPDP §7 rather than a consent-based
            // purpose — so refusing to accept a complaint without a ticked box
            // would be both the wrong legal basis and an unlawful obstacle.
            'privacy_notice_read' => ['nullable', 'boolean'],
        ], [
            'name.required_unless' => 'Please tell us your name, or tick the anonymous box.',
            'email.required_unless' => 'We need an email address to send you the complaint number, or tick the anonymous box.',
            'attachments.*.max' => 'Each file must be under '.($this->settings->attachmentMaxKilobytes() / 1024).' MB.',
            'attachments.*.mimes' => 'Attach PDFs or images only.',
        ], [
            'body' => 'complaint',
        ]);

        $anonymous = (bool) ($validated['is_anonymous'] ?? false);

        $ticket = $this->grievances->file(new FileGrievanceData(
            subject: $validated['subject'],
            body: $validated['body'],
            category: TicketCategory::from($validated['category']),
            channel: TicketChannel::Web,
            reporterName: $validated['name'] ?? null,
            reporterEmail: $validated['email'] ?? null,
            reporterPhone: $this->normalisePhone($validated['phone'] ?? null),
            isAnonymous: $anonymous,
            distributorId: null,
            customerId: null,
        ));

        $this->attachments->storeMany($ticket, $request->file('attachments', []), null);

        RateLimiter::hit($key, decaySeconds: 3600);

        return redirect()
            ->route('grievance.submitted')
            ->with('grievance_ticket_no', $ticket->ticket_no)
            ->with('grievance_resolution_by', $ticket->sla_resolution_at->toFormattedDayDateString())
            ->with('grievance_first_response_by', $ticket->sla_first_response_at->toFormattedDayDateString())
            ->with('grievance_is_anonymous', $ticket->is_anonymous);
    }

    /**
     * The on-screen ticket number promised by policy §3.1. Shown once, right
     * after submission — for an anonymous complainant this is the only place
     * the number will ever appear, since there is no address to send it to.
     *
     * The number travels in the session rather than the URL. Putting it in a
     * query string would make a guessed or shared link enough to confirm that
     * a given complaint exists.
     */
    public function submitted(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('grievance_ticket_no')) {
            return redirect()->route('grievance.create');
        }

        return view('grievance.submitted', [
            'ticketNo' => $request->session()->get('grievance_ticket_no'),
            'resolutionBy' => $request->session()->get('grievance_resolution_by'),
            'firstResponseBy' => $request->session()->get('grievance_first_response_by'),
            'isAnonymous' => (bool) $request->session()->get('grievance_is_anonymous'),
        ]);
    }

    public function trackForm(): View
    {
        return view('grievance.track');
    }

    /**
     * Status lookup for a complainant with no account.
     *
     * Requires the complaint number AND the email it was filed with. The
     * number alone is guessable enough (and quotable enough — it travels in
     * email subject lines) that it cannot be the sole key to someone's
     * complaint history.
     */
    public function track(Request $request): View
    {
        $validated = $request->validate([
            'ticket_no' => ['required', 'string', 'max:24'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $ticket = Ticket::with(['events' => fn ($q) => $q->orderBy('created_at')])
            ->where('ticket_no', $validated['ticket_no'])
            ->where('reporter_email', $validated['email'])
            ->where('is_anonymous', false)
            ->first();

        if ($ticket === null) {
            return view('grievance.track', [
                'notFound' => true,
                'ticketNo' => $validated['ticket_no'],
            ]);
        }

        return view('grievance.track', [
            'ticket' => $ticket,
            'timeline' => $ticket->events->filter(
                fn ($event) => $event->kind->isVisibleToComplainant()
            ),
        ]);
    }

    /**
     * A complainant adds detail to an open grievance without needing a login.
     */
    public function reply(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ticket_no' => ['required', 'string', 'max:24'],
            'email' => ['required', 'email', 'max:255'],
            'note' => ['required', 'string', 'max:2000', new NoRawGovernmentId],
            'request_escalation' => ['nullable', 'boolean'],
        ]);

        $ticket = Ticket::where('ticket_no', $validated['ticket_no'])
            ->where('reporter_email', $validated['email'])
            ->where('is_anonymous', false)
            ->first();

        abort_if($ticket === null, 404);

        if (! $ticket->status->acceptsComplainantReply()) {
            return back()->withErrors([
                'note' => 'This grievance is closed. Please file a fresh complaint, quoting '.$ticket->ticket_no.'.',
            ]);
        }

        $escalate = (bool) ($validated['request_escalation'] ?? false);

        $this->grievances->addComplainantReply($ticket, $validated['note'], $escalate);

        return back()->with('status', $escalate
            ? 'Your reply has been added to complaint '.$ticket->ticket_no.' and it has been escalated.'
            : 'Your reply has been added to complaint '.$ticket->ticket_no.'.');
    }

    private function selectableCategoryValues(): string
    {
        return implode(',', array_map(
            static fn (TicketCategory $category): string => $category->value,
            TicketCategory::selectable()
        ));
    }

    private function normalisePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return '+91'.preg_replace('/^\+?91/', '', $phone);
    }
}
