<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\ApproveKycSubmission;
use App\Modules\Admin\Services\Exceptions\KycHasNoDocumentsError;
use App\Modules\Admin\Services\RejectKycSubmission;
use App\Modules\Admin\Services\TerminateDistributor;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class AdminKycController extends Controller
{
    public function __construct(
        private readonly ApproveKycSubmission $approve,
        private readonly RejectKycSubmission $reject,
        private readonly TerminateDistributor $terminate,
    ) {}

    public function index(Request $request): View
    {
        // Queue surfaces both 'pending' (new + resubmitted) and 'rejected'
        // (awaiting the applicant's re-upload) so admins can find and reopen
        // cases that were previously declined but never resubmitted. Tabs:
        //   ?tab=pending  — pending review (default)
        //   ?tab=rejected — declined, awaiting applicant resubmission
        //
        // Couple registrations: only the primary appears in the queue.
        // The secondary is reviewed alongside the primary on the show
        // page and approved/rejected as a unit. The filter
        // `is_primary_couple OR spouse_distributor_id IS NULL` keeps
        // solo distributors and primary halves of couples; it
        // suppresses secondaries.
        $tab = $request->query('tab', 'pending');
        $statusFilter = $tab === 'rejected' ? 'rejected' : 'pending';

        $base = Distributor::query()
            ->whereHas('user', fn ($q) => $q->where('status', $statusFilter))
            ->where(function ($q) {
                $q->whereNull('spouse_distributor_id')
                    ->orWhere('is_primary_couple', true);
            })
            ->with('user')
            ->withCount('kycDocuments');

        $rows = $base->orderBy('created_at')->paginate(50)->withQueryString();

        // Mark each row with whether it has a prior rejection — drives the
        // "Resubmitted" pill on /admin/kyc rows whose status is 'pending'.
        $ids = $rows->pluck('id')->all();
        $resubmittedIds = $ids === [] ? collect() : AuditLog::query()
            ->where('action', 'admin.kyc.rejected')
            ->where('subject_type', 'distributor')
            ->whereIn('subject_id', $ids)
            ->pluck('subject_id')
            ->unique();

        // Counts for the tab buttons.
        $pendingCount = Distributor::query()
            ->whereHas('user', fn ($q) => $q->where('status', 'pending'))
            ->where(function ($q) {
                $q->whereNull('spouse_distributor_id')->orWhere('is_primary_couple', true);
            })->count();
        $rejectedCount = Distributor::query()
            ->whereHas('user', fn ($q) => $q->where('status', 'rejected'))
            ->where(function ($q) {
                $q->whereNull('spouse_distributor_id')->orWhere('is_primary_couple', true);
            })->count();

        return view('admin.kyc.index', [
            'pending' => $rows,
            'resubmittedIds' => $resubmittedIds,
            'currentTab' => $tab,
            'pendingCount' => $pendingCount,
            'rejectedCount' => $rejectedCount,
        ]);
    }

    public function show(int $id): View
    {
        $distributor = Distributor::query()
            ->with(['user', 'kycDocuments'])
            ->findOrFail($id);

        // Surface the most recent rejection reason (if any) so the admin
        // reviewing a resubmitted application can see what they previously
        // flagged. Also tells the view whether to render a "this is a
        // re-submission" banner.
        $lastRejection = AuditLog::query()
            ->where('action', 'admin.kyc.rejected')
            ->where('subject_type', 'distributor')
            ->where('subject_id', $distributor->id)
            ->orderByDesc('id')
            ->first();

        $hasPriorRejection = $lastRejection !== null;
        $lastRejectionReason = is_array($lastRejection?->details ?? null)
            ? ($lastRejection->details['reason'] ?? null)
            : null;

        return view('admin.kyc.show', [
            'distributor' => $distributor,
            'hasPriorRejection' => $hasPriorRejection,
            'lastRejectionReason' => $lastRejectionReason,
        ]);
    }

    public function streamDocument(int $id, int $docId): Response
    {
        $doc = KycDocument::query()
            ->where('distributor_id', $id)
            ->findOrFail($docId);

        $disk = Storage::disk('kyc');

        // Confirm the object actually exists before logging "admin viewed
        // this" — an orphaned DB row shouldn't produce a misleading audit
        // entry. Surfaces a clean 404 for the <img> onerror handler too.
        if (! $disk->exists($doc->object_storage_key)) {
            abort(404, 'KYC document file not found.');
        }

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.kyc.document_viewed',
            'subject_type' => 'kyc_document',
            'subject_id' => $doc->id,
            'details' => ['type' => $doc->type],
        ]);

        // For S3, redirect to a short-lived signed URL. Streaming via
        // Storage::response() worked locally but failed on Cloudways
        // PHP-FPM (combination of output buffering + S3 SDK stream
        // wrapper). The browser follows the 302 transparently — no
        // change to the <img src> consumer.
        if (config('filesystems.disks.kyc.driver') === 's3') {
            $url = (string) $disk->temporaryUrl(
                $doc->object_storage_key,
                now()->addMinutes(15),
            );

            return redirect()->away($url);
        }

        // Local disk (dev) — keep the byte-stream pattern.
        return $disk->response($doc->object_storage_key);
    }

    public function approve(int $id): RedirectResponse
    {
        try {
            ($this->approve)($id, (int) Auth::id());
        } catch (KycHasNoDocumentsError) {
            return back()->withErrors(['kyc' => 'This distributor has no uploaded documents to approve.']);
        }

        return redirect()->route('admin.kyc.index')->with('status', 'KYC approved.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1024'],
        ]);

        ($this->reject)($id, (int) Auth::id(), $validated['reason']);

        return redirect()->route('admin.kyc.index')
            ->with('status', 'KYC rejected. The applicant has been emailed the reason and a link to re-upload.');
    }

    /**
     * Permanently close the account. Distinct from reject — there is no
     * resubmit path from terminated. Use this when the applicant should
     * not be allowed to retry: confirmed fraud, repeat rejections, etc.
     */
    public function terminate(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1024'],
        ]);

        ($this->terminate)($id, (int) Auth::id(), $validated['reason']);

        return redirect()->route('admin.kyc.index')
            ->with('status', 'Distributor account terminated. The applicant has been emailed the closure notice.');
    }

    public function uploadDocument(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in([
                'pan', 'aadhaar', 'cheque', 'address_proof_front', 'address_proof_back', 'photo',
            ])],
            'document' => [
                'required', 'file', 'max:5120',
                'mimetypes:image/jpeg,image/png,application/pdf',
                new ValidUploadedDocumentBytes(),
            ],
        ]);

        $distributor = Distributor::query()->findOrFail($id);
        $file = $request->file('document');
        $type = $validated['type'];
        $disk = Storage::disk('kyc');
        $sha256 = hash_file('sha256', $file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = "admin_{$distributor->id}/{$type}_" . substr($sha256, 0, 12) . ".{$extension}";

        DB::transaction(function () use ($distributor, $disk, $file, $type, $path, $sha256, $request): void {
            $existing = KycDocument::query()
                ->where('distributor_id', $distributor->id)
                ->where('type', $type)
                ->latest()
                ->first();

            if ($existing !== null) {
                abort_if(
                    $existing->verified_at !== null,
                    422,
                    "A verified {$type} document already exists. Reject KYC first to allow replacement."
                );
                try {
                    $disk->delete($existing->object_storage_key);
                } catch (\Throwable) {
                    Log::warning('admin.kyc.upload: could not delete old S3 key', [
                        'key' => $existing->object_storage_key,
                    ]);
                }
                $existing->delete();
            }

            $disk->putFileAs(dirname($path), $file, basename($path));

            KycDocument::create([
                'distributor_id' => $distributor->id,
                'type' => $type,
                'object_storage_key' => $path,
                'checksum_sha256' => hex2bin($sha256),
            ]);

            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'admin.kyc.document_uploaded',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => [
                    'type' => $type,
                    'path' => $path,
                    'replaced_existing' => $existing !== null,
                ],
                'ip' => $request->ip(),
            ]);
        });

        return back()->with('status', ucfirst(str_replace('_', ' ', $type)) . ' uploaded successfully.');
    }
}
