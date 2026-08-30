<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\AreteCenterApplication;
use App\Modules\Compensation\Models\AreteCenterApplicationDocument;
use App\Modules\Compensation\Services\AreteCenterApplicationService;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use App\Modules\Shared\Support\IndianStates;
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
 * Admin review queue for Arete Development Centre applications (spec §B).
 */
final class AdminAreteCenterApplicationController extends Controller
{
    public function __construct(private readonly AreteCenterApplicationService $applications) {}

    public function index(Request $request): View
    {
        $this->guardFeature();

        $status = (string) $request->query('status', 'open');
        $state = (string) $request->query('state', '');
        $search = trim((string) $request->query('q', ''));

        $query = AreteCenterApplication::query()->with(['distributor.user', 'center']);

        if ($status === 'open') {
            $query->open();
        } elseif (array_key_exists($status, AreteCenterApplication::STATUSES)) {
            $query->where('status', $status);
        }

        if ($state !== '' && in_array($state, IndianStates::all(), true)) {
            $query->where('state', $state);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('centre_name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('pincode', 'like', "{$search}%")
                    ->orWhereHas('distributor', fn ($d) => $d->where('adn', 'like', "%{$search}%"));
            });
        }

        $applications = $query->orderByRaw("CASE WHEN status IN ('submitted','under_review') THEN 0 WHEN status = 'needs_changes' THEN 1 ELSE 2 END")
            ->orderByDesc('submitted_at')
            ->paginate(30)
            ->withQueryString();

        $counts = AreteCenterApplication::query()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        return view('admin.compensation.adc-bonus.applications.index', [
            'applications' => $applications,
            'counts' => $counts,
            'filters' => ['status' => $status, 'state' => $state, 'q' => $search],
            'states' => IndianStates::all(),
        ]);
    }

    public function show(AreteCenterApplication $application): View
    {
        $this->guardFeature();

        $application->load(['distributor.user', 'distributor.sponsor.user', 'documents', 'declarations', 'center', 'reviewedBy']);

        return view('admin.compensation.adc-bonus.applications.show', [
            'application' => $application,
            'declarationTexts' => AreteCenterDeclarations::all(),
        ]);
    }

    /**
     * @param  'review'|'approve'|'reject'|'request-changes'  $action
     */
    public function review(Request $request, AreteCenterApplication $application, string $action): RedirectResponse
    {
        $this->guardFeature();

        /** @var User $admin */
        $admin = Auth::user();

        $validated = $request->validate([
            'reason' => [Rule::requiredIf(in_array($action, ['reject', 'request-changes'], true)), 'nullable', 'string', 'max:1000'],
        ]);
        $reason = $validated['reason'] ?? null;

        try {
            $message = match ($action) {
                'review' => $this->applications->markUnderReview($application, $admin, $request->ip())
                    ->statusLabel().' — application is now under review.',
                'request-changes' => $this->applications->requestChanges($application, $admin, (string) $reason, $request->ip())
                    ->statusLabel().' — the applicant has been asked to update their application.',
                'reject' => $this->applications->reject($application, $admin, (string) $reason, $request->ip())
                    ->statusLabel().' — the applicant has been notified.',
                'approve' => 'Approved — centre "'.$this->applications->approve($application, $admin, $reason, $request->ip())->name.'" is now active at Phase 1.',
                default => abort(404),
            };
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return redirect()->route('admin.compensation.adc-bonus.applications.show', $application)
            ->with('success', $message);
    }

    /** Stream (local) or redirect to a short-lived signed URL (S3) for one document. */
    public function document(AreteCenterApplication $application, AreteCenterApplicationDocument $document): Response
    {
        $this->guardFeature();

        abort_unless($document->application_id === $application->id, 404);

        $disk = Storage::disk(AreteCenterApplicationService::DISK);
        if (! $disk->exists($document->object_storage_key)) {
            abort(404, 'Document file not found.');
        }

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'adc.application.document_viewed',
            'subject_type' => 'arete_center_application',
            'subject_id' => $application->id,
            'details' => ['document_id' => $document->id, 'type' => $document->type],
            'ip' => request()->ip(),
        ]);

        // Real S3 gets a short-lived signed URL (streaming via PHP-FPM proved
        // unreliable on Cloudways); local and faked disks stream bytes.
        if ($disk instanceof AwsS3V3Adapter) {
            return redirect()->away((string) $disk->temporaryUrl($document->object_storage_key, now()->addMinutes(15)));
        }

        return $disk->response($document->object_storage_key);
    }

    private function guardFeature(): void
    {
        abort_unless(Feature::for(null)->active(AreteCenterApplicationsFeature::class), 404);
    }
}
