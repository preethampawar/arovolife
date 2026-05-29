<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Identity\Services\IdPhotoDecodeError;
use App\Modules\Identity\Services\IdPhotoStorage;
use App\Modules\Identity\Http\Rules\NotPwned;
use App\Modules\Identity\Http\Rules\StrongPassword;
use App\Modules\Identity\Services\DistributorIdCardStats;
use App\Modules\Identity\Services\RequestPasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin-driven edits to a distributor's compliance-safe profile.
 *
 * What CAN be edited here is deliberately narrow. Per CLAUDE.md hard rule
 * #6 (one PAN = one ADN) and #8 (PII encrypted at rest), the following are
 * locked and CANNOT appear in any `$validated` array on this controller:
 *
 *   - distributors.adn                  — auto-allocated, immutable
 *   - distributors.pan_hash / pan_last4 / pan_encrypted
 *   - distributors.aadhaar_ref / aadhaar_last4 / aadhaar_encrypted
 *   - distributors.sponsor_id / placement_parent_id / placement_side
 *     (placement is irreversible after registration — the line-change
 *     request flow handles legitimate moves; even there an admin can't
 *     freely re-parent without compliance review)
 *   - distributors.cooling_off_end_at   — statutory 30-day clock (DSR §7)
 *
 * Every field change writes an audit_log entry with a redacted diff. The
 * bank account number is NEVER logged in plaintext — only the new last-4
 * suffix appears in details, and the encrypted column is rotated via
 * Crypt::encryptString.
 */
final class AdminDistributorEditController extends Controller
{
    public function __construct(
        private readonly IdPhotoStorage $idPhotoStorage,
    ) {}

    public function edit(int $id): View
    {
        $distributor = Distributor::with('user')->findOrFail($id);

        // Resolve the immutable read-only summary (sponsor ADN, placement
        // parent ADN) in one place so the view stays declarative.
        $sponsorAdn = $distributor->sponsor_id !== null
            ? DB::table('distributors')->where('id', $distributor->sponsor_id)->value('adn')
            : null;
        $placementParentAdn = $distributor->placement_parent_id !== null
            && $distributor->placement_parent_id !== $distributor->id
                ? DB::table('distributors')->where('id', $distributor->placement_parent_id)->value('adn')
                : null;

        // Pre-signed S3 URL for the current ID photo so the edit page can
        // render a preview above the "Replace ID photo" upload field.
        // Null when no photo has been uploaded yet.
        $idPhotoUrl = app(DistributorIdCardStats::class)->photoUrl($distributor);

        // KYC review snapshot — count docs total + verified so the edit
        // page can render an inline status pill ("3 docs pending review"
        // / "All approved on 21 May 2026") with a one-click jump to the
        // dedicated /admin/kyc/{id} review page. Counts only — the
        // document list itself stays on the KYC review page where the
        // streaming endpoint enforces its own audit log.
        $kycDocs = KycDocument::query()
            ->where('distributor_id', $distributor->id)
            ->selectRaw('count(*) as total, sum(case when verified_at is not null then 1 else 0 end) as verified, max(verified_at) as latest_verified_at')
            ->first();
        $kycStatus = [
            'total' => (int) ($kycDocs->total ?? 0),
            'verified' => (int) ($kycDocs->verified ?? 0),
            'latest_verified_at' => $kycDocs->latest_verified_at ?? null,
        ];

        return view('admin.distributors.edit', [
            'distributor' => $distributor,
            'states' => self::indianStates(),
            'sponsorAdn' => $sponsorAdn,
            'placementParentAdn' => $placementParentAdn,
            'idPhotoUrl' => $idPhotoUrl,
            'kycStatus' => $kycStatus,
        ]);
    }

    /**
     * Admin directly sets a new password on the distributor's user
     * account. Bypasses the email reset flow — useful when the
     * distributor doesn't have email access right now (e.g. phone-led
     * onboarding) or the admin is sitting next to them.
     *
     * Same StrongPassword + NotPwned rules the public registration
     * form uses, so the bar doesn't drop just because an admin is
     * setting it. The user's password_set_at timestamp is updated so
     * LoginController gates pass.
     */
    public function setPassword(Request $request, int $id): RedirectResponse
    {
        // Validate first so client errors don't open a useless transaction.
        $request->validate([
            'new_password' => ['required', 'string', 'min:12', new StrongPassword(), new NotPwned()],
            'new_password_confirmation' => ['required', 'string', 'same:new_password'],
        ], [
            'new_password.required' => 'Please enter a new password.',
            'new_password.min' => 'Password must be at least 12 characters.',
            'new_password_confirmation.required' => 'Please re-enter the new password to confirm.',
            'new_password_confirmation.same' => 'The two passwords don\'t match.',
        ]);

        $newPasswordHash = Hash::make($request->input('new_password'));
        $ip = $request->ip();

        $email = DB::transaction(function () use ($id, $newPasswordHash, $ip): string {
            // Lock the distributor row + user row so two admins cannot
            // race a credential reset on the same account.
            $distributor = Distributor::with('user')->lockForUpdate()->findOrFail($id);
            $user = $distributor->user;
            abort_if($user === null, 404);

            // Lock the user row too (the with() loaded it but didn't
            // acquire a lock). A direct lockForUpdate query guarantees
            // the row is held for the duration of this transaction.
            User::query()->where('id', $user->id)->lockForUpdate()->first();

            $user->update([
                'password_hash' => $newPasswordHash,
                'password_set_at' => now(),
                // Marks any stale rate-limit lockout for clearing on the
                // user's next login attempt. Without this, a user who
                // hammered the OLD password and tripped the throttle stays
                // locked out even with the fresh credential — the staging
                // "new password doesn't work" reports were this.
                'login_throttle_cleared_at' => now(),
            ]);

            // Revoke any active password-reset tokens so the link the
            // admin (or anyone else) may have emailed earlier becomes
            // unusable — an admin-set password is the canonical fresh
            // credential.
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'admin.distributor.password_set',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                // NEVER log raw or hashed passwords — only the fact
                // that one was set. Audit reviewers see "admin X set a
                // password for user Y on date Z" and nothing more.
                'details' => ['email' => $user->email, 'method' => 'direct'],
                'ip' => $ip,
            ]);

            return (string) $user->email;
        });

        return back()->with('status', 'New password set for '.$email.'. The previous password and any pending reset link are now invalid.');
    }

    /**
     * Admin updates the distributor's PAN and/or Aadhaar.
     *
     * Hard rule #6 (one PAN = one ADN) is enforced via a sha256-hash
     * lookup that excludes the current row. Either field may be
     * submitted independently; both are optional. If either changes:
     *
     *   - The full value is re-encrypted (Crypt::encryptString) and
     *     stored on pan_encrypted / aadhaar_encrypted; the sha256 hash
     *     (PAN) and the last-4 columns are updated atomically.
     *   - All kyc_documents for this distributor have verified_at
     *     reset to NULL — the identity has changed, the existing
     *     KYC sign-off no longer applies. The admin must re-review.
     *   - The user row's status flips from 'active' → 'pending' so
     *     dashboards reflect the re-review requirement.
     *   - Audit log records before/after last-4 only (never the full
     *     PAN / Aadhaar — per CLAUDE.md logging rule).
     *
     * Aadhaar regenerates a fresh stub aadhaar_ref because Phase 1
     * doesn't have a live AUA/KUA partner; in Phase 2+ this is where
     * the partner re-verification call lands.
     */
    public function updateIdentity(Request $request, int $id): RedirectResponse
    {
        // Cheap validation runs first — failures don't open a transaction.
        $validated = $request->validate([
            'pan_number' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i'],
            'aadhaar_number' => ['nullable', 'string', 'digits:12'],
        ], [
            'pan_number.regex' => 'PAN must be 5 letters, 4 digits, 1 letter (e.g. ABCDE1234F).',
            'aadhaar_number.digits' => 'Aadhaar must be exactly 12 digits.',
        ]);

        $newPan = isset($validated['pan_number']) ? strtoupper(trim($validated['pan_number'])) : '';
        $newAadhaar = isset($validated['aadhaar_number']) ? preg_replace('/\D+/', '', (string) $validated['aadhaar_number']) : '';

        if ($newPan === '' && $newAadhaar === '') {
            return back()->withErrors(['identity' => 'Enter a new PAN, a new Aadhaar, or both. Nothing to update.'])->withInput();
        }

        $ip = $request->ip();

        try {
            $result = DB::transaction(function () use ($id, $newPan, $newAadhaar, $ip): array {
                // Lock the distributor + user rows so two admins can't
                // race a PAN edit on the same account, and so the dedup
                // check below isn't undermined by a concurrent UPDATE
                // on the same row.
                $distributor = Distributor::with('user')->lockForUpdate()->findOrFail($id);
                $user = $distributor->user;
                abort_if($user === null, 404);
                User::query()->where('id', $user->id)->lockForUpdate()->first();

                // PAN dedup against every OTHER distributor (Hard rule #6).
                // The current row is excluded so re-saving an unchanged
                // PAN doesn't false-positive on itself. A concurrent admin
                // creating a different distributor with the SAME PAN is
                // caught by the uniq_distributors_pan_hash index on commit;
                // wrapping THIS exception in a friendly error is overkill
                // for what's effectively a sub-millisecond collision.
                if ($newPan !== '') {
                    $panHash = hash('sha256', $newPan, true);
                    $clashes = DB::table('distributors')
                        ->where('pan_hash', $panHash)
                        ->where('id', '!=', $distributor->id)
                        ->exists();
                    if ($clashes) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'pan_number' => 'Another Direct Seller account already exists for this PAN.',
                        ]);
                    }
                }

                $before = [
                    'pan_last4' => $distributor->pan_last4,
                    'aadhaar_last4' => $distributor->aadhaar_last4,
                ];

                // Distributor model has `encrypted` casts on pan_encrypted +
                // aadhaar_encrypted, so we assign plaintext here and the
                // cast handles AES round-tripping. Pre-encrypting would
                // double-wrap (encrypt(encrypt(value))) and break decrypt.
                $update = [];
                if ($newPan !== '') {
                    $update['pan_hash'] = hash('sha256', $newPan, true);
                    $update['pan_last4'] = substr($newPan, -4);
                    $update['pan_encrypted'] = $newPan;
                }
                if ($newAadhaar !== '') {
                    $update['aadhaar_last4'] = substr($newAadhaar, -4);
                    $update['aadhaar_encrypted'] = $newAadhaar;
                    // Phase 1 stub ref — replaced by the AUA/KUA partner
                    // response in Phase 2+. The new ref invalidates any
                    // earlier audit references to the prior submission.
                    $update['aadhaar_ref'] = 'STUB_'.strtoupper(uniqid('REF', true));
                }
                $distributor->update($update);

                // Reset KYC review state on every document attached to
                // this distributor. The admin must re-approve via
                // /admin/kyc/{id}.
                KycDocument::query()
                    ->where('distributor_id', $distributor->id)
                    ->whereNotNull('verified_at')
                    ->update(['verified_at' => null, 'verifier_id' => null]);

                // Mirror the re-review state on the user row so the
                // dashboard and distributor list reflect "pending" until
                // the admin re-approves. activated_at is preserved as
                // historical record; ApproveKycSubmission overwrites it
                // next time.
                if ($user->status === 'active') {
                    User::query()->where('id', $user->id)->update(['status' => 'pending']);
                }

                $distributor->refresh();
                $after = [
                    'pan_last4' => $distributor->pan_last4,
                    'aadhaar_last4' => $distributor->aadhaar_last4,
                ];

                AuditLog::create([
                    'actor_id' => Auth::id(),
                    'action' => 'admin.distributor.identity_updated',
                    'subject_type' => 'distributor',
                    'subject_id' => $distributor->id,
                    'details' => [
                        'pan_changed' => $newPan !== '',
                        'aadhaar_changed' => $newAadhaar !== '',
                        'before' => $before,
                        'after' => $after,
                        'kyc_reset' => true,
                    ],
                    'ip' => $ip,
                ]);

                return [
                    'distributor_id' => (int) $distributor->id,
                    'pan_changed' => $newPan !== '',
                    'aadhaar_changed' => $newAadhaar !== '',
                ];
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Bubble out of the transaction as a normal 422 redirect.
            return back()->withErrors($e->errors())->withInput();
        }

        $changed = [];
        if ($result['pan_changed']) {
            $changed[] = 'PAN';
        }
        if ($result['aadhaar_changed']) {
            $changed[] = 'Aadhaar';
        }

        return back()->with(
            'status',
            implode(' + ', $changed).' updated. KYC has been reset to pending — re-approve at /admin/kyc/'.$result['distributor_id'].'.'
        );
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $distributor = Distributor::with('user')->findOrFail($id);
        $user = $distributor->user;
        abort_if($user === null, 404);

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:120'],
            'phone_e164' => [
                'required', 'string', 'max:16',
                Rule::unique('users', 'phone_e164')->ignore($user->id),
            ],
            'email' => [
                'required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'date_of_birth' => ['nullable', 'date'],
            'state' => ['required', 'string', 'max:64'],
            // Bank is optional. IFSC is nullable; if the admin types one
            // it must validate. The pre-existing account_number field was
            // already nullable + format-validated. Clearing the IFSC
            // (submitting blank) is the explicit way to detach bank from
            // a distributor — the controller below writes null + null in
            // that case.
            'bank_ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/i'],
            'bank_account' => ['nullable', 'string', 'min:9', 'max:18', 'regex:/^\d+$/'],
        ], [
            'phone_e164.required' => 'Please enter a phone number.',
            'phone_e164.unique' => 'Another distributor already uses this phone number.',
            'email.required' => 'Please enter an email address.',
            'email.email' => 'That doesn\'t look like a valid email.',
            'email.unique' => 'Another distributor already uses this email.',
            'state.required' => 'Please pick the state.',
            'bank_ifsc.regex' => 'IFSC must be 11 characters: 4 letters, 0, then 6 alphanumeric.',
            'bank_account.regex' => 'Bank account number must contain digits only.',
            'bank_account.min' => 'Bank account number must be at least 9 digits.',
            'bank_account.max' => 'Bank account number must be at most 18 digits.',
        ]);

        // Before-snapshot for the diff (DOB → ISO so the comparison is
        // string-vs-string regardless of whether the model casts it).
        $before = [
            'full_name' => $user->full_name,
            'phone_e164' => $user->phone_e164,
            'email' => $user->email,
            'date_of_birth' => $this->dobToString($user->date_of_birth),
            'state' => $distributor->state,
            'bank_ifsc' => $distributor->bank_ifsc,
        ];

        DB::transaction(function () use ($user, $distributor, $validated): void {
            // Lock both rows so two admins can't last-write-wins a
            // routine profile edit. The fetched models above were
            // already read once; re-locking by id is the cheapest way
            // to acquire the lock without duplicating model state.
            Distributor::query()->where('id', $distributor->id)->lockForUpdate()->first();
            User::query()->where('id', $user->id)->lockForUpdate()->first();

            $userUpdate = [
                'phone_e164' => $validated['phone_e164'],
                'email' => strtolower($validated['email']),
                'date_of_birth' => $validated['date_of_birth'] ?? null,
            ];
            // full_name is nullable on the form (legacy rows may have it
            // unset). Only push the field when it's actually provided so
            // we never accidentally null out an existing name with a
            // blank submission.
            if (isset($validated['full_name']) && $validated['full_name'] !== '') {
                $userUpdate['full_name'] = $validated['full_name'];
            }
            $user->update($userUpdate);

            $distributorUpdate = [
                'state' => $validated['state'],
            ];
            // Bank IFSC: blank submission detaches bank from the
            // distributor (also clears the encrypted account number).
            // Non-blank submission updates IFSC (and account if provided).
            $ifscInput = trim((string) ($validated['bank_ifsc'] ?? ''));
            $accountInput = trim((string) ($validated['bank_account'] ?? ''));

            if ($ifscInput === '') {
                $distributorUpdate['bank_ifsc'] = null;
                $distributorUpdate['bank_account_enc'] = null;
            } else {
                $distributorUpdate['bank_ifsc'] = strtoupper($ifscInput);
                if ($accountInput !== '') {
                    $distributorUpdate['bank_account_enc'] = Crypt::encryptString($accountInput);
                }
            }
            $distributor->update($distributorUpdate);
        });

        // After-snapshot for the diff. Refresh both models so we observe
        // the post-update values exactly as stored.
        $user->refresh();
        $distributor->refresh();

        $after = [
            'full_name' => $user->full_name,
            'phone_e164' => $user->phone_e164,
            'email' => $user->email,
            'date_of_birth' => $this->dobToString($user->date_of_birth),
            'state' => $distributor->state,
            'bank_ifsc' => $distributor->bank_ifsc,
        ];

        $changes = [];
        foreach ($after as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                $changes[$k] = ['from' => $before[$k], 'to' => $v];
            }
        }
        if (! empty($validated['bank_account'])) {
            // NEVER log the plaintext account number — only the last 4 of
            // the new value goes into the audit trail. The "from" side is
            // intentionally opaque ("(previous)") because we'd have to
            // decrypt the old envelope to derive last-4, which expands the
            // blast radius of an audit-log leak.
            $changes['bank_account'] = [
                'from_redacted' => '(previous)',
                'to_redacted' => '****'.substr($validated['bank_account'], -4),
            ];
        }

        if ($changes !== []) {
            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'admin.distributor.updated',
                'subject_type' => 'distributor',
                'subject_id' => $distributor->id,
                'details' => ['changes' => $changes],
                'ip' => $request->ip(),
            ]);
        }

        return redirect()->route('admin.distributors.show', $distributor->id)
            ->with('status', 'Distributor profile updated.');
    }

    /**
     * Trigger a password reset email for the distributor. The reset link
     * is sent to the distributor's email; the admin never sees the token.
     *
     * Spouse accounts that haven't activated yet (password_set_at NULL)
     * are silently no-op'd inside RequestPasswordReset — they're expected
     * to use the activation magic link they already received. The audit
     * entry is written regardless of delivery so the admin trail is
     * complete.
     */
    public function sendPasswordReset(Request $request, int $id): RedirectResponse
    {
        $distributor = Distributor::with('user')->findOrFail($id);
        $user = $distributor->user;
        abort_if($user === null, 404);

        app(RequestPasswordReset::class)($user->email);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.distributor.password_reset_sent',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => ['email' => $user->email],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', 'Password reset link sent to '.$user->email.'.');
    }

    /**
     * Admin replaces the distributor's ID photo. Same EXIF-strip + atomic
     * S3 swap as the self-upload surface (the work is delegated to
     * {@see IdPhotoStorage}), but the audit row records the admin as
     * actor and the distributor's user_id as subject.
     */
    public function updateIdPhoto(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png',
                'max:5120',
                'dimensions:min_width=200,min_height=200,max_width=4000,max_height=4000',
            ],
        ], [
            'photo.dimensions' => 'Please upload an image between 200×200 and 4000×4000 pixels.',
            'photo.mimes' => 'Only JPG and PNG photos are supported right now.',
        ]);

        $distributor = Distributor::with('user')->findOrFail($id);
        $user = $distributor->user;
        abort_if($user === null, 404);

        $file = $request->file('photo');

        try {
            $meta = $this->idPhotoStorage->replace($user, $file);
        } catch (IdPhotoDecodeError $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.distributor.id_photo_updated',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => [
                'distributor_id' => $distributor->id,
                'old_key' => $meta['old_key'],
                'new_key' => $meta['new_key'],
                'size_bytes_uploaded' => $file->getSize(),
                'size_bytes_stored' => $meta['size_bytes_stored'],
                'mime' => $meta['mime'],
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', 'ID photo updated.');
    }

    private function dobToString(mixed $dob): ?string
    {
        if ($dob === null || $dob === '') {
            return null;
        }
        if ($dob instanceof \DateTimeInterface) {
            return $dob->format('Y-m-d');
        }

        return (string) $dob;
    }

    /**
     * @return array<string, string>
     */
    public static function indianStates(): array
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
