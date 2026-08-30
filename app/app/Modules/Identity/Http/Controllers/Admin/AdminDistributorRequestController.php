<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\DistributorRequest;
use App\Modules\Identity\Models\DistributorRequestDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorRequestService;
use App\Modules\Shared\Features\DistributorRequestsFeature;
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

        $status = (string) $request->query('status', 'open');
        $type = (string) $request->query('type', '');
        $search = trim((string) $request->query('q', ''));

        $query = DistributorRequest::query()->with(['distributor.user']);

        if ($status === 'open') {
            $query->open();
        } elseif (array_key_exists($status, DistributorRequest::STATUSES)) {
            $query->where('status', $status);
        }

        if ($type !== '' && array_key_exists($type, DistributorRequest::TYPES)) {
            $query->where('type', $type);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('request_no', 'like', "%{$search}%")
                    ->orWhereHas('distributor', fn ($d) => $d->where('adn', 'like', "%{$search}%"))
                    ->orWhereHas('distributor.user', fn ($u) => $u->where('full_name', 'like', "%{$search}%"));
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
            'filters' => ['status' => $status, 'type' => $type, 'q' => $search],
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
                'reject' => $this->requests->reject($distributorRequest, $admin, (string) $reason, $request->ip())->statusLabel().' — the distributor has been emailed your reason.',
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
            ? 'Approved — the distributor\'s record has been updated and they have been emailed.'
            : 'Approved — the distributor has been emailed. Carry out the change with the account tools on the distributor\'s page.';
    }

    private function guardFeature(): void
    {
        abort_unless(Feature::for(null)->active(DistributorRequestsFeature::class), 404);
    }
}
