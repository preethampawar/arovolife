<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Customer-facing self-service for KYC documents post-registration.
 *
 * The wizard at step 9 (Documents) now requires only PAN + Aadhaar;
 * cancelled cheque and address-proof front/back are optional. This
 * controller is where a customer adds the optional docs after the
 * fact — or replaces a doc that was rejected by admin review. The
 * surface mirrors the ID-photo self-service pattern
 * ({@see IdPhotoController}): owner-only writes, magic-byte
 * validation, audit-logged, and admin-attested replacements use a
 * separate route on the admin side.
 *
 * What this controller CANNOT do:
 *  - Replace a document that admin already approved
 *    (verified_at IS NOT NULL). Once a doc is signed off, the
 *    customer must contact support to invalidate it first; this
 *    prevents a customer from quietly swapping their approved
 *    PAN for a different one to evade dedup.
 *  - Add a doc that isn't in the canonical type list. Type is an
 *    enum at the DB level; we re-check here for a friendly error.
 */
final class KycDocumentSelfServiceController extends Controller
{
    /**
     * Doc types the customer can manage themselves. The wizard
     * accepts these at signup; the dashboard accepts them later.
     * Excludes 'photo' (handled by IdPhotoController) and any
     * future admin-only document types.
     *
     * @var array<int, string>
     */
    private const SELF_SERVICE_TYPES = [
        'pan',
        'aadhaar',
        'cheque',
        'address_proof_front',
        'address_proof_back',
    ];

    public function index(): View
    {
        $userId = (int) Auth::id();
        $distributor = Distributor::query()->where('user_id', $userId)->first();
        abort_if($distributor === null, 404, 'No distributor record for this user.');

        // Existing docs by type (verified state included so the view
        // can lock the "Replace" button for approved docs).
        $docs = KycDocument::query()
            ->where('distributor_id', $distributor->id)
            ->select('id', 'type', 'verified_at', 'created_at')
            ->orderBy('type')
            ->get()
            ->keyBy('type');

        return view('dashboard.kyc-documents', [
            'distributor' => $distributor,
            'docsByType' => $docs,
            'selfServiceTypes' => self::SELF_SERVICE_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = (int) Auth::id();
        $distributor = Distributor::query()->where('user_id', $userId)->first();
        abort_if($distributor === null, 404);

        // Block uploads on frozen/terminated accounts — those are
        // compliance suspensions; customer can't quietly self-resolve
        // by re-submitting docs.
        $userStatus = $distributor->user?->status;
        abort_if(
            in_array($userStatus, ['frozen', 'terminated'], true),
            403,
            'Document uploads are disabled while your account is '.$userStatus.'.'
        );

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::SELF_SERVICE_TYPES)],
            'document' => [
                'required', 'file', 'max:5120',
                'mimetypes:image/jpeg,image/png,application/pdf',
                new ValidUploadedDocumentBytes(),
            ],
        ], [
            'type.required' => 'Please pick which document you\'re uploading.',
            'type.in' => 'That document type isn\'t supported here.',
            'document.required' => 'Please attach a file before submitting.',
            'document.max' => 'The file is too large (max 5 MB).',
            'document.mimetypes' => 'The file must be a JPG, PNG, or PDF.',
        ]);

        $type = $validated['type'];

        // Hard-fail if the customer is trying to replace a doc admin
        // already approved. The dedicated admin flow handles approved-
        // doc replacement (audit trail, re-verification gate).
        $existingApproved = KycDocument::query()
            ->where('distributor_id', $distributor->id)
            ->where('type', $type)
            ->whereNotNull('verified_at')
            ->exists();
        if ($existingApproved) {
            return back()->withErrors([
                'document' => 'This document is already approved. Contact support to replace it.',
            ]);
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('document');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $sha256 = (string) hash_file('sha256', $file->getRealPath());
        $path = "user_{$userId}/{$type}_".substr($sha256, 0, 12).'.'.$extension;

        Storage::disk('kyc')->putFileAs(dirname($path), $file, basename($path));

        // Delete the previous unverified row for this type (if any) so
        // the admin reviewer sees one document per type, not a stack
        // of stale uploads. The S3 object for the old row is left in
        // place — keeping the audit trail for the prior submission;
        // the periodic retention sweep cleans it up.
        $previousId = KycDocument::query()
            ->where('distributor_id', $distributor->id)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->value('id');
        if ($previousId !== null) {
            KycDocument::query()->where('id', $previousId)->delete();
        }

        $doc = KycDocument::create([
            'distributor_id' => $distributor->id,
            'type' => $type,
            'object_storage_key' => $path,
            'checksum_sha256' => hex2bin($sha256),
            'verified_at' => null,
            'verifier_id' => null,
        ]);

        AuditLog::create([
            'actor_id' => $userId,
            'action' => 'profile.kyc_document.uploaded',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'details' => [
                'type' => $type,
                'document_id' => $doc->id,
                'replaced_previous' => $previousId !== null,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with(
            'status',
            ucfirst(str_replace('_', ' ', $type)).' uploaded. An admin will review it shortly.'
        );
    }
}
