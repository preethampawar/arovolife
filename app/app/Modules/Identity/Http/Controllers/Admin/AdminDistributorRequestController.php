<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Models\DistributorRequest;
use App\Modules\Identity\Models\DistributorRequestDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorRequestService;
use App\Modules\Shared\Features\DistributorRequestsFeature;
use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin review queue for distributor requests. Reading needs
 * `distributor.request.handle`; each decision needs the permission the
 * request type names (`kyc.review` for name / DOB, `compliance.discipline`
 * for transfer / cancellation).
 */
final class AdminDistributorRequestController extends Controller
{
    public function __construct(private readonly DistributorRequestService $requests) {}

    public function index(Request $request): View
    {
        $this->guardFeature();

        $filters = ListFilters::make($request, [
            FilterField::select('status', 'Status', ['all' => 'All statuses'] + DistributorRequest::STATUSES, placeholder: 'Open (needs action)'),
            FilterField::select('type', 'Type', array_map(
                fn (array $type): string => $type['label'],
                DistributorRequest::TYPES,
            ), column: 'type', placeholder: 'All types'),
            FilterField::text('q', 'Search', 'Request no, ADN or name'),
            FilterField::dateRange('submitted_at', 'Submitted', 'submitted_at'),
        ]);

        $query = $filters->apply(DistributorRequest::query()->with(['distributor.user']));

        // Blank means "open" — the queue opens on what needs action, and the
        // two pseudo-statuses ("open", "all") are not column values, so they
        // stay out of the declarative mapping.
        $status = $filters->value('status') ?? 'open';

        if ($status === 'open') {
            $query->open();
        } elseif (array_key_exists($status, DistributorRequest::STATUSES)) {
            $query->where('status', $status);
        }

        if (($search = $filters->value('q')) !== null) {
            $term = '%'.ListFilters::escapeLike($search).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('request_no', 'like', $term)
                    ->orWhereHas('distributor', fn ($d) => $d->where('adn', 'like', $term))
                    ->orWhereHas('distributor.user', fn ($u) => $u->where('full_name', 'like', $term));
            });
        }

        $items = $query->orderByRaw("CASE WHEN status IN ('submitted','under_review') THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->paginate(30)
            ->withQueryString();

        $counts = DistributorRequest::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        return view('admin.distributor-requests.index', [
            'requests' => $items,
            'counts' => $counts,
            'filters' => $filters,
        ]);
    }

    public function show(DistributorRequest $distributorRequest): View
    {
        $this->guardFeature();

        $distributorRequest->load(['distributor.user', 'documents', 'reviewedBy']);

        return view('admin.distributor-requests.show', [
            'item' => $distributorRequest,
            'canDecide' => Auth::user()?->can($distributorRequest->decidePermission()) ?? false,
        ]);
    }

    /** @param 'review'|'approve'|'reject' $action */
    public function decide(Request $request, DistributorRequest $distributorRequest, string $action): RedirectResponse
    {
        $this->guardFeature();

        /** @var User $admin */
        $admin = Auth::user();

        // The type decides who may decide: name / DOB is a KYC matter,
        // transfer / cancellation is account discipline (R-17).
        if ($action !== 'review' && ! $admin->can($distributorRequest->decidePermission())) {
            abort(403);
        }

        $validated = $request->validate([
            'reason' => [Rule::requiredIf($action === 'reject'), 'nullable', 'string', 'max:1000'],
        ]);
        $reason = $validated['reason'] ?? null;

        try {
            $message = match ($action) {
                'review' => $this->requests->markUnderReview($distributorRequest, $admin, $request->ip())->statusLabel().' — no email is sent for this step.',
                'reject' => $this->requests->reject($distributorRequest, $admin, (string) $reason, $request->ip())->statusLabel().' — your reason is queued to the distributor by email.',
                'approve' => $this->approveMessage($this->requests->approve($distributorRequest, $admin, $reason, $request->ip())),
                default => abort(404),
            };
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('admin.distributor-requests.show', $distributorRequest)->with('success', $message);
    }

    /** Stream (local) or redirect to a short-lived signed URL (S3) for one document. */
    public function document(DistributorRequest $distributorRequest, DistributorRequestDocument $document): Response
    {
        $this->guardFeature();

        abort_unless($document->request_id === $distributorRequest->id, 404);

        $disk = Storage::disk(DistributorRequestService::DISK);
        if (! $disk->exists($document->object_storage_key)) {
            abort(404, 'Document file not found.');
        }

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'distributor_request.document_viewed',
            'subject_type' => 'distributor_request',
            'subject_id' => $distributorRequest->id,
            // A view moves nothing; the matching digests pin which document
            // state the reviewer was shown.
            'before_hash' => AuditDigests::of($document),
            'after_hash' => AuditDigests::of($document),
            'details' => ['document_id' => $document->id, 'type' => $document->type],
            'ip' => request()->ip(),
        ]);

        if ($disk instanceof AwsS3V3Adapter) {
            return redirect()->away((string) $disk->temporaryUrl($document->object_storage_key, now()->addMinutes(15)));
        }

        return $disk->response($document->object_storage_key);
    }

    private function approveMessage(DistributorRequest $request): string
    {
        return $request->appliesOnApproval()
            ? 'Approved — the distributor\'s record has been updated and the decision is queued to them by email.'
            : 'Approved — the decision is queued to the distributor by email. Carry out the change with the account tools on the distributor\'s page.';
    }

    private function guardFeature(): void
    {
        abort_unless(Feature::for(null)->active(DistributorRequestsFeature::class), 404);
    }
}
