<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\ApproveKycSubmission;
use App\Modules\Admin\Services\Exceptions\KycHasFlaggedDocumentsError;
use App\Modules\Admin\Services\Exceptions\KycHasNoDocumentsError;
use App\Modules\Admin\Services\RejectKycSubmission;
use App\Modules\Admin\Services\TerminateDistributor;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Notifications\KycDocumentFlaggedNotification;
use App\Modules\Kyc\Services\KycDocumentVault;
use App\Modules\Shared\Http\Rules\ScannedForMalware;
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
use Throwable;

final class AdminKycController extends Controller
{
    public function __construct(
        private readonly ApproveKycSubmission $approve,
        private readonly RejectKycSubmission $reject,
        private readonly TerminateDistributor $terminate,
        private readonly KycDocumentVault $vault,
    ) {}

    public function index(Request $request): View
    {
        // Queue surfaces 'pending' (new + resubmitted), 'rejected' (declined,
        // awaiting the applicant's resubmission) and 'flagged' (one document
        // was sent back for re-upload and has not been replaced yet), so
        // admins can find and reopen cases that are parked on the applicant.
        // Tabs:
        //   ?tab=pending  — pending review (default)
        //   ?tab=rejected — declined, awaiting applicant resubmission
        //   ?tab=flagged  — a document is flagged, awaiting re-upload
        //
        // Couple registrations: only the primary appears in the queue.
        // The secondary is reviewed alongside the primary on the show
        // page and approved/rejected as a unit. The filter
        // `is_primary_couple OR spouse_distributor_id IS NULL` keeps
        // solo distributors and primary halves of couples; it
        // suppresses secondaries.
        $tab = $request->query('tab', 'pending');
        $tab = in_array($tab, ['pending', 'rejected', 'flagged'], true) ? $tab : 'pending';

        $base = Distributor::query()
            ->where(function ($q) {
                $q->whereNull('spouse_distributor_id')
                    ->orWhere('is_primary_couple', true);
            })
            ->with('user')
            ->withCount('kycDocuments');

        if ($tab === 'flagged') {
            // Waiting on the applicant: at least one document was flagged for
            // re-upload and has not been replaced — the re-upload clears
            // `flagged_at`, so a non-null value is by definition unresolved.
            $base->whereHas('kycDocuments', fn ($q) => $q->whereNotNull('flagged_at'));
        } else {
            $base->whereHas('user', fn ($q) => $q->where('status', $tab));
        }

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

        // …and whether a document on the row is flagged, so a submission
        // parked on the applicant is visible in the pending tab too and not
        // picked up by a second reviewer as an ordinary new case.
        $flaggedIds = $ids === [] ? collect() : KycDocument::query()
            ->whereIn('distributor_id', $ids)
            ->whereNotNull('flagged_at')
            ->pluck('distributor_id')
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
        $flaggedCount = Distributor::query()
            ->whereHas('kycDocuments', fn ($q) => $q->whereNotNull('flagged_at'))
            ->where(function ($q) {
                $q->whereNull('spouse_distributor_id')->orWhere('is_primary_couple', true);
            })->count();

        return view('admin.kyc.index', [
            'pending' => $rows,
            'resubmittedIds' => $resubmittedIds,
            'flaggedIds' => $flaggedIds,
            'currentTab' => $tab,
            'pendingCount' => $pendingCount,
            'rejectedCount' => $rejectedCount,
            'flaggedCount' => $flaggedCount,
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

        // Approving a submission whose document is flagged would accept the
        // very document the reviewer asked to have replaced, and would kill
        // the applicant's re-upload link (the re-upload page 404s once the
        // flag is gone). The button is hidden while that is true; the same
        // condition is enforced in ApproveKycSubmission for the couple unit,
        // which is what actually stops a second reviewer.
        $hasFlaggedDocument = KycDocument::query()
            ->whereIn('distributor_id', array_filter([
                $distributor->id,
                $distributor->spouse_distributor_id,
            ]))
            ->whereNotNull('flagged_at')
            ->exists();

        return view('admin.kyc.show', [
            'distributor' => $distributor,
            'hasPriorRejection' => $hasPriorRejection,
            'lastRejectionReason' => $lastRejectionReason,
            'hasFlaggedDocument' => $hasFlaggedDocument,
        ]);
    }

    public function streamDocument(int $id, int $docId): Response
    {
        $doc = KycDocument::query()
            ->where('distributor_id', $id)
            ->findOrFail($docId);

        // Confirm the object actually exists before logging "admin viewed
        // this" — an orphaned DB row shouldn't produce a misleading audit
        // entry. Surfaces a clean 404 for the <img> onerror handler too.
        if (! $this->vault->disk()->exists($doc->object_storage_key)) {
            abort(404, 'KYC document file not found.');
        }

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.kyc.document_viewed',
            'subject_type' => 'kyc_document',
            'subject_id' => $doc->id,
            // A view moves nothing, so the two digests match by design; what
            // they pin is which document state the reviewer was shown.
            'before_hash' => AuditDigests::of($doc),
            'after_hash' => AuditDigests::of($doc),
            'details' => ['type' => $doc->type],
        ]);

        // Serve the bytes from this audited route — never a presigned URL, which
        // would work for anyone holding it, with no session and no audit row
        // (F106). `Storage::response()` failed on Cloudways PHP-FPM (output
        // buffering + the S3 stream wrapper), so read the object outright:
        // KYC scans are a few MB at most. The vault decrypts; the bucket
        // holds ciphertext.
        $contents = $this->vault->read($doc);

        if ($contents === null) {
            abort(404, 'KYC document file not found.');
        }

        return response($contents, 200, [
            'Content-Type' => $this->vault->mimeType($contents),
            'Content-Disposition' => 'inline; filename="kyc-document-'.$doc->id.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        try {
            ($this->approve)($id, (int) Auth::id());
        } catch (KycHasNoDocumentsError) {
            return back()->withErrors(['kyc' => 'This distributor has no uploaded documents to approve.']);
        } catch (KycHasFlaggedDocumentsError) {
            return back()->withErrors([
                'kyc' => 'A document on this submission is flagged for re-upload. Wait for the applicant to replace it, or reject the whole submission.',
            ]);
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
            ->with('status', 'KYC rejected. The reason and a re-upload link are queued to the applicant by email.');
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
            ->with('status', 'Distributor account terminated. The closure notice is queued to the applicant by email.');
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
                new ValidUploadedDocumentBytes, new ScannedForMalware,
            ],
        ]);

        $distributor = Distributor::query()->findOrFail($id);
        $file = $request->file('document');
        $type = $validated['type'];
        $disk = $this->vault->disk();
        $sha256 = hash_file('sha256', $file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = "admin_{$distributor->id}/{$type}_".substr($sha256, 0, 12).".{$extension}";

        DB::transaction(function () use ($distributor, $disk, $file, $type, $path, $sha256, $request): void {
            $existing = KycDocument::query()
                ->where('distributor_id', $distributor->id)
                ->where('type', $type)
                ->latest()
                ->first();

            $replaced = $existing === null ? null : AuditDigests::of($existing);

            if ($existing !== null) {
                abort_if(
                    $existing->verified_at !== null,
                    422,
                    "A verified {$type} document already exists. Reject KYC first to allow replacement."
                );
                try {
                    $disk->delete($existing->object_storage_key);
                } catch (Throwable) {
                    Log::warning('admin.kyc.upload: could not delete old S3 key', [
                        'key' => $existing->object_storage_key,
                    ]);
                }
                $existing->delete();
            }

            $this->vault->store($file, $path);

            $document = KycDocument::create([
                'distributor_id' => $distributor->id,
                'type' => $type,
                'object_storage_key' => $path,
                'checksum_sha256' => hex2bin($sha256),
                'encrypted_at' => now(),
            ]);

            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'admin.kyc.document_uploaded',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'before_hash' => $replaced,
                'after_hash' => AuditDigests::of($document),
                'details' => [
                    'type' => $type,
                    'path' => $path,
                    'replaced_existing' => $existing !== null,
                ],
                'ip' => $request->ip(),
            ]);
        });

        return back()->with('status', ucfirst(str_replace('_', ' ', $type)).' uploaded successfully.');
    }

    /**
     * Flag a single KYC document as unclear / needs re-upload. The applicant
     * receives an email + in-app notification with a signed link to a page
     * that lets them re-upload only this one document — without resubmitting
     * the rest of the KYC. Distinct from {@see reject()}, which rejects the
     * whole submission and flips the user status.
     */
    public function flagDocument(Request $request, int $id, int $docId): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1024'],
        ], [
            'reason.required' => 'Please enter a reason — it is sent to the applicant.',
            'reason.min' => 'Reason must be at least 8 characters.',
        ]);

        $distributor = Distributor::with('user')->findOrFail($id);
        $document = KycDocument::query()
            ->where('distributor_id', $distributor->id)
            ->whereKey($docId)
            ->firstOrFail();

        abort_if(
            $document->verified_at !== null,
            422,
            'This document is already verified. Reject the whole KYC first to allow replacement.'
        );

        DB::transaction(function () use ($document, $validated, $distributor, $request): void {
            $before = AuditDigests::of($document);

            $document->update([
                'flagged_reason' => $validated['reason'],
                'flagged_at' => now(),
                'flagged_by' => Auth::id(),
            ]);

            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'admin.kyc.document_flagged',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'before_hash' => $before,
                'after_hash' => AuditDigests::of($document),
                'details' => ['document_id' => $document->id, 'type' => $document->type],
                'ip' => $request->ip(),
            ]);
        });

        // Notified after the flag is committed, not inside the transaction: a
        // transport that refuses must not roll back the reviewer's decision,
        // and the admin has to be told the truth about what reached the
        // applicant. The mail leg itself is queued and can still fail later —
        // the in-app copy is what survives a bad SMTP night.
        $notified = true;
        $user = $distributor->user;
        if ($user !== null) {
            try {
                $user->notify(new KycDocumentFlaggedNotification(
                    documentId: $document->id,
                    documentType: $document->type,
                    reason: $validated['reason'],
                ));
            } catch (Throwable $e) {
                $notified = false;
                Log::error('kyc.document_flag_notification_failed', [
                    'document_id' => $document->id,
                    'distributor_id' => $distributor->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if ($user === null || ! $notified) {
            return back()->with('status', 'Document flagged, but the re-upload notice could not be sent. Contact the applicant directly — their re-upload link is live.');
        }

        return back()->with('status', 'Document flagged. A re-upload link is in the applicant\'s notifications and queued to them by email.');
    }
}
