<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Distributor-facing page reached via a signed URL from
 * {@see \App\Modules\Kyc\Notifications\KycDocumentFlaggedNotification}. Lets
 * the applicant re-upload only the flagged document — not the whole KYC.
 *
 * GET is gated by the 'signed' middleware (the URL itself is the auth for
 * the link). POST is auth + CSRF only; ownership and the flag still-active
 * check happen here, so a stale link cannot be replayed once the doc has
 * been re-uploaded.
 */
final class KycDocumentReuploadController extends Controller
{
    public function show(KycDocument $document): View
    {
        $this->authorizeAccess($document);

        return view('kyc.reupload', [
            'document' => $document,
            'documentTypeHuman' => ucwords(str_replace('_', ' ', $document->type)),
        ]);
    }

    public function store(Request $request, KycDocument $document): RedirectResponse
    {
        $this->authorizeAccess($document);

        $validated = $request->validate([
            'document' => [
                'required', 'file', 'max:5120',
                'mimetypes:image/jpeg,image/png,application/pdf',
                new ValidUploadedDocumentBytes(),
            ],
        ], [
            'document.required' => 'Please choose a file to upload.',
            'document.max' => 'The file must not be larger than 5 MB.',
            'document.mimetypes' => 'Allowed formats: JPG, PNG or PDF.',
        ]);

        $file = $request->file('document');
        $disk = Storage::disk('kyc');
        $sha256 = hash_file('sha256', $file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $newPath = "distributor_{$document->distributor_id}/{$document->type}_" . substr($sha256, 0, 12) . ".{$extension}";

        $oldPath = $document->object_storage_key;

        DB::transaction(function () use ($document, $disk, $file, $newPath, $sha256, $oldPath, $request): void {
            $disk->putFileAs(dirname($newPath), $file, basename($newPath));

            $document->update([
                'object_storage_key' => $newPath,
                'checksum_sha256' => hex2bin($sha256),
                'flagged_reason' => null,
                'flagged_at' => null,
                'flagged_by' => null,
            ]);

            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'distributor.kyc.document_reuploaded',
                'subject_type' => 'distributor',
                'subject_id' => $document->distributor_id,
                'details' => ['document_id' => $document->id, 'type' => $document->type],
                'ip' => $request->ip(),
            ]);

            if ($oldPath !== $newPath) {
                try {
                    $disk->delete($oldPath);
                } catch (\Throwable) {
                    Log::warning('kyc.reupload: could not delete old storage key', ['key' => $oldPath]);
                }
            }
        });

        return redirect()->route('dashboard')
            ->with('status', 'Your '.ucwords(str_replace('_', ' ', $document->type)).' was re-uploaded. An admin will review it again shortly.');
    }

    private function authorizeAccess(KycDocument $document): void
    {
        $user = Auth::user();
        // Must belong to the authenticated user's distributor row.
        if ($user === null || $user->distributor === null || $document->distributor_id !== $user->distributor->id) {
            throw new NotFoundHttpException;
        }
        // The flag must still be active — once cleared (re-uploaded or admin
        // verified), the link is dead.
        if (! $document->isFlagged()) {
            throw new NotFoundHttpException;
        }
    }
}
