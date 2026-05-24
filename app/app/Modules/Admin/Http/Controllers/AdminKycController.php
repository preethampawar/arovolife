<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\ApproveKycSubmission;
use App\Modules\Admin\Services\Exceptions\KycHasNoDocumentsError;
use App\Modules\Admin\Services\RejectKycSubmission;
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
    ) {}

    public function index(): View
    {
        // All distributors whose user.status is 'pending', regardless of
        // whether they've uploaded KYC documents yet. The previous
        // `whereHas('kycDocuments')` filter hid users who'd completed
        // step 2 (account) but not yet reached step 9 (documents),
        // producing a discrepancy with the dashboard's "Pending
        // Registration" tile — the dashboard counted them, this list
        // didn't. The Blade now renders an "Awaiting documents" badge
        // for rows where kyc_documents_count = 0 so the admin can see
        // the full pipeline.
        //
        // Couple registrations: only the primary appears in the queue.
        // The secondary is reviewed alongside the primary on the show
        // page and approved/rejected as a unit. The filter
        // `is_primary_couple OR spouse_distributor_id IS NULL` keeps
        // solo distributors and primary halves of couples; it
        // suppresses secondaries.
        $pending = Distributor::query()
            ->whereHas('user', fn ($q) => $q->where('status', 'pending'))
            ->where(function ($q) {
                $q->whereNull('spouse_distributor_id')
                    ->orWhere('is_primary_couple', true);
            })
            ->with('user')
            ->withCount('kycDocuments')
            ->orderBy('created_at')
            ->paginate(50);

        return view('admin.kyc.index', [
            'pending' => $pending,
        ]);
    }

    public function show(int $id): View
    {
        $distributor = Distributor::query()
            ->with(['user', 'kycDocuments'])
            ->findOrFail($id);

        return view('admin.kyc.show', [
            'distributor' => $distributor,
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

        return redirect()->route('admin.kyc.index')->with('status', 'KYC rejected.');
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
