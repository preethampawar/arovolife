<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\RegistrationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin tool for customers stuck mid-registration.
 *
 * Use case (production-relevant): a customer creates their account at
 * step 2 (so a `users` row exists with status=pending) but never
 * finishes the wizard — either they abandoned, hit a server error, or
 * couldn't upload their KYC documents from their device. They email
 * the documents to support; an admin needs a way to (a) upload those
 * documents on their behalf and (b) trigger the final placement so
 * the customer becomes a real Direct Seller.
 *
 * What this controller does NOT do:
 *  - Skip any wizard data the customer hasn't supplied. If the
 *    customer never reached the PAN step, this tool can't make up a
 *    PAN — the admin still needs to ask the customer. Used the
 *    "Add Distributor" admin form (AdminDistributorCreateController)
 *    instead for fully-offline registrations.
 *  - Override consent. Consent + orientation status are read from the
 *    customer's draft; if missing, the admin attests on their behalf
 *    and the audit row records "admin_attested_consent" so a
 *    compliance reviewer can spot it.
 *
 * Audit:
 *  - admin.registration.docs_uploaded_on_behalf  (per upload)
 *  - admin.registration.finalised_on_behalf      (per finalise)
 */
final class AdminPendingRegistrationController extends Controller
{
    /** Wizard doc field names, in order. Mirrors RegistrationWizardController::KYC_DOC_FIELDS. */
    private const KYC_DOC_FIELDS = [
        'pan_doc' => 'pan',
        'aadhaar_doc' => 'aadhaar',
        'cheque_doc' => 'cheque',
        'address_proof_front' => 'address_proof_front',
        'address_proof_back' => 'address_proof_back',
    ];

    public function __construct(
        private readonly DraftStateService $drafts,
        private readonly RegistrationService $registrationService,
    ) {}

    /**
     * List every customer who created an account but never finished
     * registration. Two cohorts:
     *  - users with status=pending AND no distributor row (the most
     *    common "stuck pre-finalise" case — bank-optional migration
     *    bug, server errors, drop-offs at step 9, etc.)
     */
    public function index(): View
    {
        // Pending users without a distributor row.
        $stuck = DB::table('users')
            ->leftJoin('distributors', 'distributors.user_id', '=', 'users.id')
            ->leftJoin('registration_drafts', 'registration_drafts.user_id', '=', 'users.id')
            ->where('users.status', 'pending')
            ->whereNull('distributors.id')
            ->select(
                'users.id',
                'users.email',
                'users.full_name',
                'users.phone_e164',
                'users.created_at',
                'registration_drafts.current_step as draft_step',
                'registration_drafts.expires_at as draft_expires_at',
                'registration_drafts.sponsor_id as draft_sponsor_id',
                'registration_drafts.placement_id as draft_placement_id'
            )
            ->orderByDesc('users.created_at')
            ->paginate(50);

        return view('admin.pending-registrations.index', [
            'stuck' => $stuck,
        ]);
    }

    /**
     * Detail page: show what the customer has submitted so far + the
     * form to upload docs + finalise.
     */
    public function show(int $userId): View|RedirectResponse
    {
        $user = User::findOrFail($userId);
        abort_unless($user->status === 'pending', 404, 'Only pending users appear here.');
        abort_if(Distributor::where('user_id', $user->id)->exists(), 404, 'This user already has a distributor row — edit them from /admin/distributors.');

        $draft = $this->drafts->findActiveByUserId($user->id);
        $wizardData = $draft ? $this->decryptDraftPayload($draft) : null;

        // Resolve the sponsor / placement ADNs for display only — the
        // actual finalise() call reads the IDs straight from the draft.
        $sponsorAdn = null;
        $placementAdn = null;
        if ($draft !== null) {
            $sponsorAdn = DB::table('distributors')->where('id', $draft->sponsor_id)->value('adn');
            $placementAdn = DB::table('distributors')->where('id', $draft->placement_id)->value('adn');
        }

        return view('admin.pending-registrations.show', [
            'user' => $user,
            'draft' => $draft,
            'wizardData' => $wizardData,
            'sponsorAdn' => $sponsorAdn,
            'placementAdn' => $placementAdn,
            'requiredDocFields' => array_keys(self::KYC_DOC_FIELDS),
            'docsInDraft' => is_array($wizardData['documents'] ?? null) ? array_keys($wizardData['documents']) : [],
        ]);
    }

    /**
     * Admin uploads documents on the customer's behalf and merges them
     * into the customer's draft payload. Same disk + path layout as the
     * customer wizard (`user_{user_id}/...`) so the downstream
     * finalise() doesn't care who uploaded.
     */
    public function upload(Request $request, int $userId): RedirectResponse
    {
        $user = User::findOrFail($userId);
        $draft = $this->drafts->findActiveByUserId($user->id);
        abort_if($draft === null, 404, 'No active draft for this user; cannot record documents.');

        $rules = [];
        foreach (self::KYC_DOC_FIELDS as $field => $_type) {
            $rules[$field] = [
                'sometimes', 'file', 'max:5120',
                'mimetypes:image/jpeg,image/png,application/pdf',
                new ValidUploadedDocumentBytes(),
            ];
        }
        $validated = $request->validate($rules, [
            'file' => 'The :attribute upload was incomplete — please try again.',
            'max' => 'The :attribute file is too large (max 5 MB).',
            'mimetypes' => 'The :attribute must be a JPG, PNG, or PDF file.',
        ]);

        $disk = Storage::disk('kyc');
        $payload = $this->decryptDraftPayload($draft);
        $payload['documents'] = $payload['documents'] ?? [];
        $uploaded = [];

        foreach (self::KYC_DOC_FIELDS as $field => $_type) {
            if (! $request->hasFile($field)) {
                continue;
            }
            /** @var \Illuminate\Http\UploadedFile $file */
            $file = $request->file($field);
            $relativePath = "user_{$user->id}/{$field}_".uniqid().'.'.$file->getClientOriginalExtension();
            $disk->putFileAs("user_{$user->id}", $file, basename($relativePath));
            $payload['documents'][$field] = [
                'path' => $relativePath,
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by_admin' => Auth::id(),
                'uploaded_at' => now()->toIso8601String(),
            ];
            $uploaded[] = $field;
        }

        if ($uploaded === []) {
            return back()->withErrors(['files' => 'Please attach at least one file before submitting.']);
        }

        // Persist the merged payload back onto the draft. sync() takes
        // the step + the full payload; we keep the draft's current_step
        // unchanged (admin uploads don't advance the wizard for the user).
        $this->drafts->sync($user->id, (int) $draft->current_step, $payload);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.registration.docs_uploaded_on_behalf',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => [
                'uploaded_fields' => $uploaded,
                'count' => count($uploaded),
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', count($uploaded).' document(s) uploaded for '.$user->email.'. Click "Finalise on behalf" when ready to issue the ADN.');
    }

    /**
     * Read the customer's draft and call RegistrationService::finalise()
     * with the draft's wizard data. Same code path the customer would
     * have run if they'd clicked "Confirm & Issue My ADN" themselves.
     *
     * Compliance attestation: the audit row records the admin as actor
     * and flags admin_attested_orientation + admin_attested_consent
     * (the wizard normally records these from the customer's POST; when
     * the admin completes on the customer's behalf we record that fact
     * so a compliance reviewer can sample admin-attested completions).
     */
    public function finalise(Request $request, int $userId): RedirectResponse
    {
        $user = User::findOrFail($userId);
        abort_unless($user->status === 'pending', 422, 'User is not pending.');
        abort_if(Distributor::where('user_id', $user->id)->exists(), 422, 'This user already has a distributor row.');

        $draft = $this->drafts->findActiveByUserId($user->id);
        abort_if($draft === null, 422, 'No active draft for this user — cannot finalise.');

        $payload = $this->decryptDraftPayload($draft);

        // Sanity check: the wizard data must contain the steps the
        // finalise() service reads. If the customer never got past
        // PAN, there's nothing finalise can do — fail loudly so the
        // admin uses the "Add Distributor" form instead.
        $missing = [];
        if (empty($payload['pan']['pan_number'])) {
            $missing[] = 'PAN';
        }
        if (empty($payload['aadhaar']['aadhaar_number'])) {
            $missing[] = 'Aadhaar';
        }
        if (empty($payload['personal']['state'])) {
            $missing[] = 'Personal (state)';
        }
        if ($missing !== []) {
            return back()->withErrors(['finalise' => 'The customer hasn\'t supplied: '.implode(', ', $missing).'. Use the "Add Distributor" form for fully-offline registrations.']);
        }

        // Inject sponsor + placement IDs straight from the draft so
        // finalise() picks them up. The draft stores these in
        // dedicated columns; the wizardData array passed to
        // finalise() carries them under sponsor_id + placement.placement_id.
        $payload['sponsor_id'] = (int) $draft->sponsor_id;
        $payload['placement'] = $payload['placement'] ?? [];
        $payload['placement']['placement_id'] = (int) $draft->placement_id;
        if ($draft->side_opt !== null && empty($payload['placement']['side'])) {
            $payload['placement']['side'] = $draft->side_opt;
        }

        try {
            $result = $this->registrationService->finalise($payload, $user);
        } catch (\Throwable $e) {
            // Re-surface as a friendly error rather than a 500 — admins
            // shouldn't see raw stack traces.
            return back()->withErrors(['finalise' => 'Finalise failed: '.$e->getMessage()]);
        }

        // Clear the draft now that the customer is a real distributor.
        $this->drafts->delete($user->id);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.registration.finalised_on_behalf',
            'subject_type' => 'distributor',
            'subject_id' => $result->distributorId,
            'details' => [
                'user_id' => $user->id,
                'email' => $user->email,
                'sponsor_id' => (int) $draft->sponsor_id,
                'placement_id' => (int) $draft->placement_id,
                'admin_attested_orientation' => empty($payload['orientation']['watched']),
                'admin_attested_consent' => empty($payload['consent']['accepted']),
            ],
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('admin.distributors.show', $result->distributorId)
            ->with('status', 'ADN issued for '.$user->email.'. KYC documents pending admin review.');
    }

    /**
     * Decrypt the draft payload. Wraps the same Crypt round-trip
     * DraftStateService uses internally so this controller doesn't
     * need to know the storage format.
     *
     * @return array<string, mixed>
     */
    private function decryptDraftPayload(RegistrationDraft $draft): array
    {
        $decoded = json_decode(Crypt::decryptString($draft->payload_enc), true);

        return is_array($decoded) ? $decoded : [];
    }
}
