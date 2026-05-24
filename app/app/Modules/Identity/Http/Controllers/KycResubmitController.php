<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Identity\Services\ResubmitKycSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer-facing page where a rejected distributor uploads replacement
 * documents. RedirectRejectedToResubmit middleware funnels them here from
 * any other authenticated route, so this is the only screen they see until
 * they fix the submission.
 */
final class KycResubmitController extends Controller
{
    public function __construct(
        private readonly ResubmitKycSubmission $resubmit,
    ) {}

    public function show(): View|RedirectResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return redirect()->route('login');
        }

        // Only rejected users should see this page. Active / pending users
        // get bounced to their dashboard. Terminated users see nothing —
        // they can't reach this page because they can't log in.
        if ($user->status !== 'rejected') {
            return redirect()->route('dashboard');
        }

        $distributor = $user->distributor;
        if ($distributor === null) {
            // Shouldn't happen — every active user has a distributor row —
            // but guard so we don't 500.
            return redirect()->route('login');
        }

        // Surface the most recent rejection reason from the audit log so the
        // distributor knows what to fix.
        $rejection = AuditLog::query()
            ->where('action', 'admin.kyc.rejected')
            ->where('subject_type', 'distributor')
            ->where('subject_id', $distributor->id)
            ->orderByDesc('id')
            ->first();
        $rejectionReason = is_array($rejection?->details ?? null)
            ? ($rejection->details['reason'] ?? '')
            : '';

        return view('identity.kyc-resubmit', [
            'distributor' => $distributor,
            'rejectionReason' => $rejectionReason,
            'rejectedAt' => $rejection?->created_at,
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return redirect()->route('login');
        }

        $distributor = $user->distributor;
        if ($distributor === null || $user->status !== 'rejected') {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'pan_doc' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf', new ValidUploadedDocumentBytes()],
            'aadhaar_doc' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf', new ValidUploadedDocumentBytes()],
            'cheque_doc' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf', new ValidUploadedDocumentBytes()],
            'address_proof_front' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf', new ValidUploadedDocumentBytes()],
            'address_proof_back' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf', new ValidUploadedDocumentBytes()],
        ]);

        $files = [];
        foreach (['pan_doc' => 'pan', 'aadhaar_doc' => 'aadhaar', 'cheque_doc' => 'cheque', 'address_proof_front' => 'address_proof_front', 'address_proof_back' => 'address_proof_back'] as $field => $type) {
            if ($request->hasFile($field)) {
                $files[$type] = $request->file($field);
            }
        }

        if ($files === []) {
            return back()->withErrors([
                'resubmit' => 'Please choose at least one replacement document to upload.',
            ]);
        }

        ($this->resubmit)((int) $distributor->id, $files);

        return redirect()->route('kyc.resubmit.show')
            ->with('status', 'Thank you — your replacement documents are in the queue. We will email you once the review is complete.');
    }
}
