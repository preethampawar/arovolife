<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\IdPhotoDecodeError;
use App\Modules\Identity\Services\IdPhotoStorage;
use App\Modules\Identity\Services\RequestPasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

        return view('admin.distributors.edit', [
            'distributor' => $distributor,
            'states' => self::indianStates(),
            'sponsorAdn' => $sponsorAdn,
            'placementParentAdn' => $placementParentAdn,
        ]);
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
            'bank_ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/i'],
            // Optional — empty = unchanged. When provided, the value is
            // re-encrypted via Crypt::encryptString and the audit-log diff
            // shows the redacted last-4 only.
            'bank_account' => ['nullable', 'string', 'min:9', 'max:18', 'regex:/^\d+$/'],
        ], [
            'phone_e164.required' => 'Please enter a phone number.',
            'phone_e164.unique' => 'Another distributor already uses this phone number.',
            'email.required' => 'Please enter an email address.',
            'email.email' => 'That doesn\'t look like a valid email.',
            'email.unique' => 'Another distributor already uses this email.',
            'state.required' => 'Please pick the state.',
            'bank_ifsc.required' => 'Please enter the bank IFSC.',
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
                'bank_ifsc' => strtoupper($validated['bank_ifsc']),
            ];
            if (! empty($validated['bank_account'])) {
                $distributorUpdate['bank_account_enc'] = Crypt::encryptString($validated['bank_account']);
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
