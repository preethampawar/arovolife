<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Registration;

use App\Modules\Genealogy\Services\Exceptions\PlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotsExhaustedError;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Http\Rules\NotPwned;
use App\Modules\Identity\Http\Rules\StrongPassword;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RegistrationService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class RegistrationWizardController extends Controller
{
    public function __construct(
        private readonly WizardStateService $wizard,
        private readonly RegistrationService $registrationService,
    ) {}

    // ── Entry: parse referral link query params and stash in session ──────

    /**
     * Handles the public `/register` GET. Per ADR-0003 the join page is
     * only reachable via a referral link. Direct visits redirect to
     * Contact Us.
     */
    public function start(Request $request): RedirectResponse
    {
        $sponsorAdn = strtoupper(trim((string) $request->query('sponsor', '')));
        $placementAdn = strtoupper(trim((string) $request->query('placement', '')));
        $sideOpt = strtoupper(trim((string) $request->query('side', '')));

        if ($sideOpt !== '' && ! in_array($sideOpt, ['L', 'R'], true)) {
            return redirect('/contact-us?reason=invalid_referral_link');
        }
        $sideOpt = $sideOpt === '' ? null : $sideOpt;

        // No referral link at all → polite redirect that asks the visitor
        // to leave their details so support can hand them a real link.
        if ($sponsorAdn === '' || $placementAdn === '') {
            return redirect('/contact-us?reason=referral_link_required');
        }

        // Validate ADN shape before hitting the DB so a malformed query
        // string (e.g. an injection attempt) is rejected without a row scan.
        // Lookahead requires at least one alphanumeric so all-dash strings
        // like `------` don't waste a query.
        $adnRegex = '/^(?=.*[A-Z0-9])[A-Z0-9-]{6,18}$/';
        if (! preg_match($adnRegex, $sponsorAdn) || ! preg_match($adnRegex, $placementAdn)) {
            return redirect('/contact-us?reason=invalid_referral_link');
        }

        $sponsor = DB::table('distributors')->where('adn', $sponsorAdn)->first();
        $placement = DB::table('distributors')->where('adn', $placementAdn)->first();

        if ($sponsor === null || $placement === null) {
            return redirect('/contact-us?reason=invalid_referral_link');
        }

        $engine = app(PlacementEngine::class);

        // Cross-line guard at link-open time — the engine re-checks at
        // place() but failing here yields a clean Contact Us redirect
        // rather than a stack trace inside finalise().
        if (! $engine->isSelfOrDescendant((int) $sponsor->id, (int) $placement->id)) {
            return redirect('/contact-us?reason=invalid_referral_link');
        }

        if (! $engine->hasOpenSlot((int) $placement->id, $sideOpt)) {
            return redirect('/contact-us?reason=invalid_referral_link');
        }

        $this->wizard->stashIntent(
            sponsorId: (int) $sponsor->id,
            placementId: (int) $placement->id,
            sideOpt: $sideOpt,
            extras: [
                'sponsor_adn' => $sponsor->adn,
                'placement_adn' => $placement->adn,
            ],
        );

        return redirect()->route('register.account.show');
    }

    // ── Step 1: Account ────────────────────────────────────────────────────

    public function showAccount(): View|RedirectResponse
    {
        $intent = $this->wizard->intent();
        if ($intent === null) {
            return redirect('/contact-us?reason=referral_link_required');
        }

        return view('registration.step1-account', [
            'sponsorAdn' => $intent['sponsor_adn'] ?? '',
            'placementAdn' => $intent['placement_adn'] ?? '',
            'sideOpt' => $intent['side_opt'] ?? null,
        ]);
    }

    public function handleAccount(Request $request): RedirectResponse
    {
        $intent = $this->wizard->intent();
        if ($intent === null) {
            return redirect('/contact-us?reason=referral_link_required');
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone_e164' => ['required', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone_e164'],
            'password' => ['required', 'string', 'min:8', 'confirmed', new StrongPassword, new NotPwned],
        ], [
            'full_name.required'   => 'Please enter your full name as it appears on your PAN card.',
            'full_name.max'        => 'Full name must be at most 255 characters.',
            'email.required'       => 'Please enter your email address.',
            'email.email'          => 'That doesn’t look like a valid email — check for typos like missing @ or .com.',
            'email.unique'         => 'An account already exists with this email. Try signing in, or use the "Forgot password" link.',
            'phone_e164.required'  => 'Please enter your 10-digit Indian mobile number.',
            'phone_e164.regex'     => 'Enter a 10-digit Indian mobile number (must start with 6, 7, 8, or 9).',
            'phone_e164.unique'    => 'An account already exists with this mobile number.',
            'password.required'    => 'Please choose a password.',
            'password.min'         => 'Password must be at least 8 characters.',
            'password.confirmed'   => 'The two passwords don’t match — please re-type them.',
        ], [
            'full_name'  => 'full name',
            'phone_e164' => 'mobile number',
        ]);

        // Normalise phone — form sends digits only, DB stores E.164 with +91 prefix
        $phone = $validated['phone_e164'];
        if (! str_starts_with($phone, '+')) {
            $phone = '+91'.ltrim($phone, '0');
        }

        $user = User::create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'phone_e164' => $phone,
            'password_hash' => Hash::make($validated['password']),
            'password_set_at' => now(),
            'status' => 'pending',
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        $this->wizard->start(
            userId: $user->id,
            sponsorId: (int) $intent['sponsor_id'],
            placementId: (int) $intent['placement_id'],
            sideOpt: $intent['side_opt'] ?? null,
        );

        return redirect()->route('register.orientation');
    }

    // ── Step 2: Orientation ────────────────────────────────────────────────

    public function showOrientation(): View
    {
        return view('registration.step2-orientation');
    }

    public function handleOrientation(Request $request): RedirectResponse
    {
        $request->validate([
            'quiz_q1' => ['required', 'in:A'],
            'quiz_q2' => ['required', 'in:B'],
            'quiz_q3' => ['required', 'in:C'],
            'confirmed_watched' => ['required', 'accepted'],
        ], [
            'quiz_q1.required'           => 'Please answer Question 1.',
            'quiz_q1.in'                 => 'Question 1: that’s not the correct answer. Please re-read the orientation and try again.',
            'quiz_q2.required'           => 'Please answer Question 2.',
            'quiz_q2.in'                 => 'Question 2: that’s not the correct answer. Please re-read the orientation and try again.',
            'quiz_q3.required'           => 'Please answer Question 3.',
            'quiz_q3.in'                 => 'Question 3: that’s not the correct answer. Please re-read the orientation and try again.',
            'confirmed_watched.required' => 'Please confirm you have watched the full orientation video.',
            'confirmed_watched.accepted' => 'You must confirm watching the orientation video before continuing.',
        ]);

        $this->wizard->saveStepData(2, [
            'quiz_passed' => true,
            'watched_at' => now()->toISOString(),
        ]);

        return redirect()->route('register.personal');
    }

    // ── Step 3: Personal Details ───────────────────────────────────────────

    public function showPersonal(): View
    {
        return view('registration.step3-personal', [
            'states' => $this->indianStates(),
            'data' => $this->wizard->getStepData(3) ?? [],
        ]);
    }

    public function handlePersonal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date_of_birth' => ['required', 'date'],
            'state' => ['required', 'in:'.implode(',', array_keys($this->indianStates()))],
            'address' => ['required', 'string', 'max:1000'],
            'register_with_spouse' => ['nullable', 'in:yes'],
        ], [
            'date_of_birth.required' => 'Please enter your date of birth.',
            'date_of_birth.date'     => 'Please enter a valid date of birth (YYYY-MM-DD).',
            'state.required'         => 'Please pick the state you live in.',
            'state.in'                => 'Please pick a valid Indian state from the list.',
            'address.required'       => 'Please enter your full residential address.',
            'address.max'            => 'Address must be at most 1000 characters.',
        ], [
            'date_of_birth' => 'date of birth',
        ]);

        // Per US-1.12 the minimum age is admin-configurable per-state. The
        // default is 18 (DSR 2021). Maharashtra is 21 by default. Admins can
        // edit `compliance.state_age_minimums` without a code release.
        $minAge = $this->minimumAgeForState($validated['state']);
        $request->validate([
            'date_of_birth' => ['before:-'.$minAge.' years'],
        ], [
            'date_of_birth.before' => "Direct Sellers in this state must be at least {$minAge} years old.",
        ]);

        $isCouple = ($validated['register_with_spouse'] ?? null) === 'yes';
        $spouse = null;

        if ($isCouple) {
            // Spouse identity must differ from primary's. We check this
            // BEFORE the validator so the user gets the targeted message
            // ("must differ from your own") rather than a generic
            // "already exists" — the primary's user row is already in the
            // table at step 1, so a `unique:users,email` rule would
            // mis-blame "already exists" for what is really "you typed
            // your own email."
            $primary = Auth::user();
            $primaryEmail = $primary !== null ? strtolower((string) $primary->email) : '';
            $primaryPhone = $primary !== null ? (string) $primary->phone_e164 : '';

            $rawSpouseEmail = strtolower((string) $request->input('spouse_email', ''));
            $rawSpousePhone = (string) $request->input('spouse_phone_e164', '');
            // Stored phone is +91-prefixed E.164; the form sends the bare
            // 10-digit number. Normalise BEFORE comparing or running the
            // unique-check, otherwise a spouse can re-use the primary's
            // phone undetected.
            $normalisedSpousePhone = str_starts_with($rawSpousePhone, '+')
                ? $rawSpousePhone
                : '+91'.ltrim($rawSpousePhone, '0');

            $earlyErrors = [];
            if ($rawSpouseEmail !== '' && $rawSpouseEmail === $primaryEmail) {
                $earlyErrors['spouse_email'] = 'Spouse email must differ from your own.';
            }
            // $normalisedSpousePhone is always at least '+91' by construction;
            // skip the equality check when the raw input was empty.
            if ($rawSpousePhone !== '' && $normalisedSpousePhone === $primaryPhone) {
                $earlyErrors['spouse_phone_e164'] = 'Spouse phone must differ from your own.';
            }
            if (! empty($earlyErrors)) {
                return back()->withInput()->withErrors($earlyErrors);
            }

            // Replace the bare 10-digit phone in the request with its
            // E.164 form so the `unique:users,phone_e164` rule queries the
            // right shape.
            $request->merge(['spouse_phone_e164' => $normalisedSpousePhone]);

            // Spouse data validated against the SAME state-age rule — both
            // adults must be of age in the registering state.
            $spouse = $request->validate([
                'spouse_full_name' => ['required', 'string', 'max:255'],
                'spouse_dob' => ['required', 'date', 'before:-'.$minAge.' years'],
                'spouse_email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'spouse_phone_e164' => ['required', 'regex:/^\+91[6-9]\d{9}$/', 'unique:users,phone_e164'],
            ], [
                'spouse_full_name.required' => 'Please enter your spouse’s full name.',
                'spouse_dob.required'       => 'Please enter your spouse’s date of birth.',
                'spouse_dob.date'           => 'Please enter a valid spouse date of birth (YYYY-MM-DD).',
                'spouse_dob.before'         => "Spouse must be at least {$minAge} years old in this state.",
                'spouse_email.required'     => 'Please enter your spouse’s email address.',
                'spouse_email.email'        => 'That doesn’t look like a valid spouse email — check for typos.',
                'spouse_email.unique'       => 'An account already exists with this spouse email.',
                'spouse_phone_e164.required' => 'Please enter your spouse’s mobile number.',
                'spouse_phone_e164.regex'    => 'Spouse mobile must be a 10-digit Indian number (starting with 6, 7, 8, or 9).',
                'spouse_phone_e164.unique'   => 'An account already exists with this spouse mobile number.',
            ], [
                'spouse_full_name'   => 'spouse full name',
                'spouse_dob'         => 'spouse date of birth',
                'spouse_phone_e164'  => 'spouse mobile number',
            ]);
        }

        $this->wizard->saveStepData(3, [
            'date_of_birth' => $validated['date_of_birth'],
            'state' => $validated['state'],
            'address' => $validated['address'],
            'couple_enabled' => $isCouple,
            'spouse' => $spouse,
        ]);

        return redirect()->route('register.pan');
    }

    private function minimumAgeForState(string $state): int
    {
        $row = DB::table('settings')->where('key', 'compliance.state_age_minimums')->value('value');
        $overrides = $row !== null ? (array) (json_decode((string) $row, true) ?: []) : [];

        return (int) ($overrides[$state] ?? 18);
    }

    // ── Step 4: PAN KYC ────────────────────────────────────────────────────

    public function showPan(): View
    {
        return view('registration.step4-pan', [
            'data' => $this->wizard->getStepData(4) ?? [],
            'isCouple' => (bool) ($this->wizard->getStepData(3)['couple_enabled'] ?? false),
        ]);
    }

    public function handlePan(Request $request): RedirectResponse
    {
        $isCouple = (bool) ($this->wizard->getStepData(3)['couple_enabled'] ?? false);

        // Auto-uppercase before regex check so a curl POST with lowercase
        // doesn't get a confusing "format invalid" message — PAN is a
        // case-insensitive identifier in practice (the dedup hash strips
        // case anyway).
        $request->merge([
            'pan_number' => strtoupper(trim((string) $request->input('pan_number', ''))),
            'spouse_pan_number' => strtoupper(trim((string) $request->input('spouse_pan_number', ''))),
        ]);

        $rules = ['pan_number' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/']];
        if ($isCouple) {
            $rules['spouse_pan_number'] = ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/', 'different:pan_number'];
        }
        $validated = $request->validate($rules, [
            'pan_number.required'         => 'Please enter your PAN number.',
            'pan_number.regex'            => 'PAN must be exactly 10 characters: 5 letters, 4 digits, then 1 letter (e.g. ABCDE1234F).',
            'spouse_pan_number.required'  => 'Please enter your spouse’s PAN number.',
            'spouse_pan_number.regex'     => 'Spouse PAN must be exactly 10 characters: 5 letters, 4 digits, then 1 letter (e.g. ABCDE1234F).',
            'spouse_pan_number.different' => 'Spouse PAN must differ from yours — one PAN can only register once.',
        ], [
            'pan_number'        => 'PAN',
            'spouse_pan_number' => 'spouse PAN',
        ]);

        // Hard rule #6: one PAN = one ADN. The dedup query covers BOTH primary
        // and secondary distributor rows, since they share the `distributors`
        // table — a PAN already used as a spouse is also caught here.
        $primaryHash = hash('sha256', strtoupper(trim($validated['pan_number'])), true);
        if (DB::table('distributors')->where('pan_hash', $primaryHash)->exists()) {
            return back()->withErrors(['pan_number' => 'A Direct Seller account already exists for this PAN.']);
        }

        if ($isCouple) {
            $spouseHash = hash('sha256', strtoupper(trim($validated['spouse_pan_number'])), true);
            if (DB::table('distributors')->where('pan_hash', $spouseHash)->exists()) {
                return back()->withErrors(['spouse_pan_number' => 'A Direct Seller account already exists for this PAN.']);
            }
        }

        $this->wizard->saveStepData(4, [
            'pan_number' => $validated['pan_number'],
            'spouse_pan_number' => $isCouple ? $validated['spouse_pan_number'] : null,
        ]);

        return redirect()->route('register.aadhaar');
    }

    // ── Step 5: Aadhaar KYC ────────────────────────────────────────────────

    public function showAadhaar(): View
    {
        return view('registration.step5-aadhaar', [
            'data' => $this->wizard->getStepData(5) ?? [],
            'isCouple' => (bool) ($this->wizard->getStepData(3)['couple_enabled'] ?? false),
        ]);
    }

    public function handleAadhaar(Request $request): RedirectResponse
    {
        $isCouple = (bool) ($this->wizard->getStepData(3)['couple_enabled'] ?? false);

        $rules = [
            'aadhaar_last4' => ['required', 'digits:4'],
            'consent_aadhaar' => ['required', 'accepted'],
        ];
        if ($isCouple) {
            $rules['spouse_aadhaar_last4'] = ['required', 'digits:4', 'different:aadhaar_last4'];
        }
        $validated = $request->validate($rules, [
            'aadhaar_last4.required'         => 'Please enter the last 4 digits of your Aadhaar number.',
            'aadhaar_last4.digits'           => 'Please enter exactly 4 digits.',
            'consent_aadhaar.required'       => 'Please consent to UIDAI verification before continuing.',
            'consent_aadhaar.accepted'       => 'You must consent to Aadhaar verification by our UIDAI partner to proceed.',
            'spouse_aadhaar_last4.required'  => 'Please enter the last 4 digits of your spouse’s Aadhaar.',
            'spouse_aadhaar_last4.digits'    => 'Please enter exactly 4 digits for spouse Aadhaar.',
            'spouse_aadhaar_last4.different' => 'Spouse Aadhaar last-4 must differ from yours.',
        ], [
            'aadhaar_last4'         => 'Aadhaar last 4 digits',
            'spouse_aadhaar_last4'  => 'spouse Aadhaar last 4 digits',
            'consent_aadhaar'       => 'Aadhaar consent',
        ]);

        // Phase 1 stub: generate reference IDs (real implementation uses UIDAI AUA/KUA partner)
        $ref = 'STUB_'.strtoupper(uniqid('REF', true));
        $spouseRef = $isCouple ? 'STUB_'.strtoupper(uniqid('REFS', true)) : null;

        $this->wizard->saveStepData(5, [
            'last4' => $validated['aadhaar_last4'],
            'ref' => $ref,
            'spouse_last4' => $isCouple ? $validated['spouse_aadhaar_last4'] : null,
            'spouse_ref' => $spouseRef,
        ]);

        return redirect()->route('register.bank');
    }

    // ── Step 6: Bank KYC ──────────────────────────────────────────────────

    public function showBank(): View
    {
        return view('registration.step6-bank', [
            'data' => $this->wizard->getStepData(6) ?? [],
        ]);
    }

    public function handleBank(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'account_number' => ['required', 'string', 'min:9', 'max:18', 'regex:/^\d+$/'],
            'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ], [
            'account_number.required' => 'Please enter your bank account number.',
            'account_number.min'      => 'Bank account number must be at least 9 digits.',
            'account_number.max'      => 'Bank account number must be at most 18 digits.',
            'account_number.regex'    => 'Bank account number must contain digits only — no spaces or letters.',
            'ifsc.required'           => 'Please enter your bank’s IFSC code.',
            'ifsc.regex'              => 'IFSC must be 11 characters: 4 letters, 0, then 6 alphanumeric (e.g. HDFC0001234).',
        ], [
            'account_number' => 'bank account number',
            'ifsc'           => 'IFSC code',
        ]);

        $this->wizard->saveStepData(6, $validated);

        return redirect()->route('register.documents');
    }

    // ── Step 7: Documents ─────────────────────────────────────────────────

    public function showDocuments(): View
    {
        return view('registration.step7-documents', [
            'isCouple' => (bool) ($this->wizard->getStepData(3)['couple_enabled'] ?? false),
        ]);
    }

    /** @var array<string, string> Logical type → form field name (primary applicant) */
    private const KYC_DOC_FIELDS = [
        'pan' => 'pan_doc',
        'aadhaar' => 'aadhaar_doc',
        'cheque' => 'cheque_doc',
        'address_proof_front' => 'address_proof_front',
        'address_proof_back' => 'address_proof_back',
    ];

    /** @var array<string, string> Spouse-only doc fields (couple registrations) */
    private const KYC_SPOUSE_DOC_FIELDS = [
        'pan' => 'spouse_pan_doc',
        'aadhaar' => 'spouse_aadhaar_doc',
    ];

    public function handleDocuments(Request $request): RedirectResponse
    {
        $isCouple = (bool) ($this->wizard->getStepData(3)['couple_enabled'] ?? false);

        // mimetypes (not just mimes) checks actual file bytes via finfo —
        // a renamed text file labelled image/jpeg is rejected. max:5120 KB
        // matches the 5 MB cap from the master plan.
        $rules = [];
        foreach (self::KYC_DOC_FIELDS as $field) {
            $rules[$field] = [
                'required', 'file', 'max:5120',
                'mimetypes:image/jpeg,image/png,application/pdf',
                new ValidUploadedDocumentBytes,
            ];
        }
        if ($isCouple) {
            foreach (self::KYC_SPOUSE_DOC_FIELDS as $field) {
                $rules[$field] = [
                    'required', 'file', 'max:5120',
                    'mimetypes:image/jpeg,image/png,application/pdf',
                    new ValidUploadedDocumentBytes,
                ];
            }
        }
        // Bare rule names (not `*.rule`) are Laravel's "apply to every field
        // with this rule" form. We can use that here because every field in
        // $rules uses the same rule set.
        $request->validate($rules, [
            'required'   => 'Please upload :attribute (JPG, PNG, or PDF, max 5 MB).',
            'file'       => 'The :attribute upload was incomplete — please try again.',
            'max'        => 'The :attribute file is too large (max 5 MB).',
            'mimetypes'  => 'The :attribute must be a JPG, PNG, or PDF file.',
        ], [
            'pan_doc'             => 'your PAN scan',
            'aadhaar_doc'         => 'your Aadhaar scan',
            'cheque_doc'          => 'a cancelled cheque scan',
            'address_proof_front' => 'your address proof (front side)',
            'address_proof_back'  => 'your address proof (back side)',
            'spouse_pan_doc'      => 'your spouse’s PAN scan',
            'spouse_aadhaar_doc'  => 'your spouse’s Aadhaar scan',
        ]);

        $userId = (int) Auth::id();
        $disk = Storage::disk('kyc');

        $stored = $this->storeKycFiles($request, self::KYC_DOC_FIELDS, "user_{$userId}", $disk);
        $spouseStored = $isCouple
            ? $this->storeKycFiles($request, self::KYC_SPOUSE_DOC_FIELDS, "user_{$userId}/spouse", $disk)
            : [];

        $this->wizard->saveStepData(7, [
            'documents' => $stored,
            'spouse_documents' => $spouseStored,
        ]);

        return redirect()->route('register.placement');
    }

    /**
     * @param  array<string, string>  $fields  type → form-field map
     * @return array<string, array<string, string>> type → {path, sha256, original_filename}
     */
    private function storeKycFiles(
        Request $request,
        array $fields,
        string $pathPrefix,
        Filesystem $disk,
    ): array {
        $stored = [];
        foreach ($fields as $type => $field) {
            $file = $request->file($field);
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $sha256 = (string) hash_file('sha256', $file->getRealPath());
            $path = "{$pathPrefix}/{$type}_".substr($sha256, 0, 12).".{$extension}";

            $disk->putFileAs(dirname($path), $file, basename($path));

            $stored[$type] = [
                'path' => $path,
                'sha256' => $sha256,
                'original_filename' => $file->getClientOriginalName(),
            ];
        }

        return $stored;
    }

    // ── Step 8: Placement (read-only summary; resolved at link time) ───────

    public function showPlacement(): View
    {
        $sponsorId = $this->wizard->sponsorId();
        $sponsor = $sponsorId
            ? DB::table('distributors')->where('id', $sponsorId)->first()
            : null;

        $placementId = $this->wizard->placementId();
        $placement = $placementId
            ? DB::table('distributors')->where('id', $placementId)->first()
            : null;

        return view('registration.step8-placement', [
            'sponsor' => $sponsor,
            'placement' => $placement,
            'sideOpt' => $this->wizard->placementSideOpt(),
        ]);
    }

    public function handlePlacement(Request $request): RedirectResponse
    {
        // Per ADR-0003 the placement target is locked at referral-link
        // open time. Step 8 is a read-only summary that simply advances.
        if ($this->wizard->getStepData(8) === null) {
            $this->wizard->saveStepData(8, [
                'placement_id' => $this->wizard->placementId(),
                'side' => $this->wizard->placementSideOpt(),
            ]);
        }

        return redirect()->route('register.consent');
    }

    // ── Step 9: Consent ───────────────────────────────────────────────────

    public function showConsent(): View
    {
        return view('registration.step9-consent');
    }

    public function handleConsent(Request $request): RedirectResponse
    {
        $request->validate([
            'consent_tnc' => ['required', 'accepted'],
            'consent_ethics' => ['required', 'accepted'],
            'consent_plan' => ['required', 'accepted'],
            'consent_privacy' => ['required', 'accepted'],
        ], [
            'consent_tnc.required'      => 'Please tick the Terms & Conditions consent.',
            'consent_tnc.accepted'      => 'You must accept the Direct Seller Agreement & Terms of Service to continue.',
            'consent_ethics.required'   => 'Please tick the Code of Ethics consent.',
            'consent_ethics.accepted'   => 'You must accept the Code of Ethics to continue.',
            'consent_plan.required'     => 'Please tick the Compensation Plan consent.',
            'consent_plan.accepted'     => 'You must acknowledge the Compensation Plan to continue.',
            'consent_privacy.required'  => 'Please tick the Privacy Policy consent.',
            'consent_privacy.accepted'  => 'You must accept the Privacy Policy to continue.',
        ]);

        $this->wizard->saveStepData(9, [
            'accepted' => true,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() ?? '',
            'at' => now()->toISOString(),
        ]);

        return redirect()->route('register.complete');
    }

    // ── Step 10: Complete ─────────────────────────────────────────────────

    public function showComplete(): View
    {
        $state = $this->wizard->get();

        return view('registration.step10-complete', [
            'personal' => $state['data']['personal'] ?? [],
            'pan' => $state['data']['pan'] ?? [],
            'sponsor_id' => $state['sponsor_id'] ?? null,
            'isCouple' => (bool) ($state['data']['personal']['couple_enabled'] ?? false),
            'spouse' => $state['data']['personal']['spouse'] ?? [],
        ]);
    }

    public function handleComplete(Request $request): RedirectResponse
    {
        $state = $this->wizard->get();

        if ($state === null) {
            // Same fallback as EnsureRegistrationProgress middleware —
            // session lost mid-flow; route to login rather than Contact Us.
            return redirect()->route('login')->with(
                'status',
                'Your registration session expired. Please sign in if you completed registration, or use your referral link again to start over.'
            );
        }

        // Defensive: a session race (two tabs writing concurrently) could
        // drop a step's data block; finalise would silently fall back to
        // empty defaults and write `pan_last4 = '0000'` etc. Refuse to
        // proceed unless every required step has data, and bounce the
        // user back to the missing step.
        foreach ([2 => 'orientation', 3 => 'personal', 4 => 'pan', 5 => 'aadhaar', 6 => 'bank', 7 => 'documents', 8 => 'placement', 9 => 'consent'] as $stepNum => $key) {
            if (($state['data'][$key] ?? null) === null) {
                return redirect()->route('register.'.$key)->withErrors([
                    'wizard' => "We could not find your {$key} data — please re-submit this step.",
                ]);
            }
        }

        $user = Auth::user();

        $wizardData = array_merge($state['data'], [
            'sponsor_id' => $state['sponsor_id'],
        ]);

        // Stitch the couple block from the per-step fragments saved across
        // steps 3, 4, 5, 7 so RegistrationService::finalise() sees one
        // coherent structure.
        if (! empty($wizardData['personal']['couple_enabled'])) {
            $spouse = $wizardData['personal']['spouse'] ?? [];
            $spousePhone = (string) ($spouse['spouse_phone_e164'] ?? '');
            if (! str_starts_with($spousePhone, '+')) {
                $spousePhone = '+91'.ltrim($spousePhone, '0');
            }

            $wizardData['couple'] = [
                'enabled' => true,
                'spouse_full_name' => $spouse['spouse_full_name'] ?? null,
                'spouse_dob' => $spouse['spouse_dob'] ?? null,
                'spouse_email' => $spouse['spouse_email'] ?? null,
                'spouse_phone_e164' => $spousePhone,
                'spouse_pan_number' => $wizardData['pan']['spouse_pan_number'] ?? null,
                'spouse_aadhaar_last4' => $wizardData['aadhaar']['spouse_last4'] ?? null,
                'spouse_aadhaar_ref' => $wizardData['aadhaar']['spouse_ref'] ?? null,
                'spouse_documents' => $wizardData['documents']['spouse_documents'] ?? [],
            ];
        }

        try {
            $result = $this->registrationService->finalise($wizardData, $user);
        } catch (UniqueConstraintViolationException $e) {
            // The C-1 race: a concurrent registration committed the same
            // PAN between step-4 dedup and now. The unique index on
            // distributors.pan_hash gives us a clean throwable here so we
            // route the user back to step 4 with a friendly message rather
            // than returning a generic 500.
            return redirect()->route('register.pan')->withErrors([
                'pan_number' => 'A Direct Seller account already exists for this PAN. If you are registering with your spouse, please re-check both PAN numbers.',
            ]);
        } catch (PlacementSlotFullError|PlacementSlotsExhaustedError $e) {
            // The slot we validated at link-open time has filled up while
            // the user was completing the wizard (race against another
            // registration sharing the same placement_id). The user has
            // already submitted PAN/Aadhaar/bank — we cannot silently
            // remap them. Clear the wizard, drop them on Contact Us with a
            // dedicated reason so support can either issue a new link or
            // hand-place them, and audit the event for the support trail.
            $this->wizard->clear();

            return redirect('/contact-us?reason=invalid_referral_link')
                ->with('status', 'The placement we reserved for you was claimed by another registration before yours completed. Please contact support; your details are safe and we can resume your registration with a fresh placement.');
        }

        $this->wizard->clear();

        return redirect()->route('dashboard')->with('adn_issued', $result->distributorId);
    }

    /** @return array<string, string> */
    private function indianStates(): array
    {
        return [
            'AN' => 'Andaman and Nicobar Islands',
            'AP' => 'Andhra Pradesh',
            'AR' => 'Arunachal Pradesh',
            'AS' => 'Assam',
            'BR' => 'Bihar',
            'CH' => 'Chandigarh',
            'CT' => 'Chhattisgarh',
            'DN' => 'Dadra and Nagar Haveli',
            'DD' => 'Daman and Diu',
            'DL' => 'Delhi',
            'GA' => 'Goa',
            'GJ' => 'Gujarat',
            'HR' => 'Haryana',
            'HP' => 'Himachal Pradesh',
            'JK' => 'Jammu and Kashmir',
            'JH' => 'Jharkhand',
            'KA' => 'Karnataka',
            'KL' => 'Kerala',
            'LD' => 'Lakshadweep',
            'MP' => 'Madhya Pradesh',
            'MH' => 'Maharashtra',
            'MN' => 'Manipur',
            'ML' => 'Meghalaya',
            'MZ' => 'Mizoram',
            'NL' => 'Nagaland',
            'OR' => 'Odisha',
            'PY' => 'Puducherry',
            'PB' => 'Punjab',
            'RJ' => 'Rajasthan',
            'SK' => 'Sikkim',
            'TN' => 'Tamil Nadu',
            'TG' => 'Telangana',
            'TR' => 'Tripura',
            'UP' => 'Uttar Pradesh',
            'UT' => 'Uttarakhand',
            'WB' => 'West Bengal',
        ];
    }
}
