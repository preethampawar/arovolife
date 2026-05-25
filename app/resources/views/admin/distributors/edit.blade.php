@extends('admin.layouts.admin')
@section('title', 'Edit ' . $distributor->adn)
@section('heading', 'Edit Distributor: ' . $distributor->adn)

@section('content')

<div class="mb-6 flex items-center justify-between gap-3 flex-wrap">
    <a href="{{ route('admin.distributors.show', $distributor->id) }}" class="text-sm text-gray-700 hover:text-gray-900">← Back to profile</a>
</div>

<form method="POST" action="{{ route('admin.distributors.update', $distributor->id) }}" class="space-y-6"
    data-confirm="Save these profile changes?"
    data-confirm-title="Confirm save"
    data-confirm-impact="Saves the distributor's profile, address and bank details. The change is audit-logged and can be edited again later.">
    @csrf
    @method('PATCH')

    {{-- Profile --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-4">Profile</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" maxlength="120"
                    value="{{ old('full_name', $distributor->user->full_name) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="phone_e164">Phone (E.164)</label>
                <input type="text" id="phone_e164" name="phone_e164" required maxlength="16"
                    value="{{ old('phone_e164', $distributor->user->phone_e164) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="email">Email</label>
                <input type="email" id="email" name="email" required maxlength="191"
                    value="{{ old('email', $distributor->user->email) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="date_of_birth">Date of birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth"
                    value="{{ old('date_of_birth', optional($distributor->user->date_of_birth)->format('Y-m-d') ?? $distributor->user->date_of_birth) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
        </div>
    </div>

    {{-- Address --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-4">Address</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="state">State</label>
                <select id="state" name="state" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    @foreach($states as $code => $name)
                        <option value="{{ $code }}" @selected(old('state', $distributor->state) === $code)>{{ $name }} ({{ $code }})</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Bank details (optional) --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-1">Bank details <span class="text-gray-500 text-sm font-normal">(optional)</span></h3>
        <p class="text-xs text-gray-700 mb-4">
            The current account number is encrypted at rest. To rotate it,
            enter a new account number; leave it blank to keep the existing
            value. To <strong>remove</strong> bank entirely (e.g. distributor
            doesn't have a bank account on file yet), clear the IFSC field —
            both fields will be set to NULL.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="bank_account">Bank account number (leave blank to keep current)</label>
                <input type="text" id="bank_account" name="bank_account"
                    maxlength="18" inputmode="numeric" pattern="\d{9,18}"
                    placeholder="Enter new account number to rotate"
                    autocomplete="off"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="bank_ifsc">Bank IFSC <span class="text-gray-500">(clear to detach bank)</span></label>
                <input type="text" id="bank_ifsc" name="bank_ifsc"
                    maxlength="11" pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}"
                    value="{{ old('bank_ifsc', $distributor->bank_ifsc) }}"
                    style="text-transform: uppercase"
                    placeholder="HDFC0001234 (or blank to detach)"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
        </div>
    </div>

    {{-- Tree position (truly immutable — ADN, sponsor, placement
         define the binary tree structure and cannot be re-keyed). --}}
    <div class="bg-gray-50 rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-2">Tree position</h3>
        <p class="text-xs text-gray-700 mb-4">
            ADN, sponsor, and placement are immutable — they define this distributor's position in the binary tree. Shown for reference only.
        </p>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-xs text-gray-700 mb-0.5">ADN</p>
                <p class="text-gray-800 font-mono">{{ $distributor->adn }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-700 mb-0.5">Sponsor ADN</p>
                <p class="text-gray-800 font-mono">{{ $sponsorAdn ?? '— (root)' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-700 mb-0.5">Placement parent ADN</p>
                <p class="text-gray-800 font-mono">{{ $placementParentAdn ?? '— (root)' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-700 mb-0.5">Placement side / depth</p>
                <p class="text-gray-800">{{ $distributor->placement_side ?? '—' }} · Level {{ $distributor->depth }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-700 mb-0.5">Cooling-off ends</p>
                <p class="text-gray-800">{{ optional($distributor->cooling_off_end_at)->format('d M Y') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-700 mb-0.5">Registered</p>
                <p class="text-gray-800">{{ optional($distributor->created_at)->format('d M Y') }}</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">
            Save changes
        </button>
        <a href="{{ route('admin.distributors.show', $distributor->id) }}"
           class="px-5 py-2.5 rounded-lg bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium transition-colors">
            Cancel
        </a>
    </div>
</form>

{{-- Out-of-band admin actions: password reset + ID photo replace. These
     live OUTSIDE the main edit form because they are independent POST
     endpoints (each with its own audit_log entry) — embedding them as
     buttons inside the edit form would conflate the diff scope. --}}
<div class="mt-8 space-y-4">

    {{-- Identity (PAN + Aadhaar) — compliance-sensitive edits.
         Posts to its own endpoint so the audit trail can record an
         identity_updated event independent of the routine name/email
         update. Updating either field resets KYC review state across
         every kyc_document attached to this distributor. --}}
    <div class="bg-white rounded-2xl border border-amber-300 p-6">
        <h3 class="font-semibold text-gray-800 mb-2 flex items-center gap-2">
            Identity (PAN + Aadhaar)
            <span class="text-[10px] font-semibold uppercase tracking-wider text-amber-700 bg-amber-100 px-2 py-0.5 rounded">Compliance-sensitive</span>
        </h3>
        <p class="text-xs text-gray-700 mb-4 leading-relaxed">
            Use only to correct a registration typo or replace a wrongly captured number.
            Either field may be updated independently — leave the other blank to keep
            the existing value. Saving here will:
        </p>
        <ul class="text-xs text-gray-700 mb-4 list-disc pl-5 space-y-1">
            <li>Re-encrypt the full value (Crypt::encryptString) and refresh the SHA-256 hash + last-4.</li>
            <li>Re-check PAN uniqueness — Hard rule #6 (<span class="font-mono">one PAN = one ADN</span>).</li>
            <li>Reset <span class="font-mono">verified_at</span> on every uploaded KYC document for this distributor; status flips back to <em>pending</em>. The admin must re-approve via <span class="font-mono">/admin/kyc/{{ $distributor->id }}</span>.</li>
            <li>Audit-log the change — before/after <em>last 4 only</em>; the full PAN / Aadhaar never enters the log.</li>
        </ul>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4 text-xs text-gray-700">
            <div>
                <p class="text-gray-500 mb-0.5">Current PAN (last 4)</p>
                <p class="text-gray-800 font-mono text-sm">XXXXXX{{ $distributor->pan_last4 }}</p>
            </div>
            <div>
                <p class="text-gray-500 mb-0.5">Current Aadhaar (last 4)</p>
                <p class="text-gray-800 font-mono text-sm">XXXX XXXX {{ $distributor->aadhaar_last4 ?? '—' }}</p>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.distributors.identity', $distributor->id) }}" class="space-y-3" autocomplete="off"
            data-confirm="Update identity and reset KYC?"
            data-confirm-title="Confirm identity update"
            data-confirm-impact="Updates the PAN/Aadhaar on file and resets every KYC document on this distributor to pending — they must be re-approved before the account is usable. The change is audit-logged (last 4 only).">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-700 mb-1" for="pan_number">New PAN (leave blank to keep current)</label>
                    <input type="text" id="pan_number" name="pan_number"
                        maxlength="10" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]"
                        placeholder="ABCDE1234F"
                        style="text-transform: uppercase"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-amber-500">
                    @error('pan_number')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-700 mb-1" for="aadhaar_number">New Aadhaar (12 digits, leave blank to keep current)</label>
                    <input type="text" id="aadhaar_number" name="aadhaar_number"
                        maxlength="14" inputmode="numeric"
                        placeholder="1234 5678 9012"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-amber-500">
                    @error('aadhaar_number')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>
            </div>
            @error('identity')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
            <button type="submit"
                class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium transition-colors">
                Update identity & reset KYC
            </button>
        </form>
    </div>

    {{-- KYC review status + inline actions. The full document list +
         streaming endpoints live on /admin/kyc/{id} so each document
         view stays inside that page's audit boundary. --}}
    @php
        $allVerified = $kycStatus['total'] > 0 && $kycStatus['verified'] === $kycStatus['total'];
        $partVerified = $kycStatus['verified'] > 0 && $kycStatus['verified'] < $kycStatus['total'];
    @endphp
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <div class="flex items-start justify-between gap-3 mb-3">
            <h3 class="font-semibold text-gray-800">KYC review</h3>
            @if($kycStatus['total'] === 0)
                <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-600 bg-gray-100 px-2 py-1 rounded">No documents</span>
            @elseif($allVerified)
                <span class="text-[11px] font-semibold uppercase tracking-wider text-green-700 bg-green-50 px-2 py-1 rounded">All approved</span>
            @elseif($partVerified)
                <span class="text-[11px] font-semibold uppercase tracking-wider text-amber-700 bg-amber-50 px-2 py-1 rounded">Partial</span>
            @else
                <span class="text-[11px] font-semibold uppercase tracking-wider text-red-700 bg-red-50 px-2 py-1 rounded">Pending</span>
            @endif
        </div>
        <p class="text-xs text-gray-700 mb-4">
            @if($kycStatus['total'] === 0)
                The distributor hasn't uploaded any KYC documents yet — they need to complete the documents step of registration before approval is possible.
            @else
                <span class="font-semibold">{{ $kycStatus['verified'] }}</span> of <span class="font-semibold">{{ $kycStatus['total'] }}</span> documents reviewed.
                @if($allVerified)
                    Last approval: {{ optional($kycStatus['latest_verified_at'])->format('d M Y, H:i') ?? '—' }}.
                @endif
            @endif
        </p>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.kyc.show', $distributor->id) }}"
               class="px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">
                Open KYC review →
            </a>
            @if(!$allVerified && $kycStatus['total'] > 0)
                <form method="POST" action="{{ route('admin.kyc.approve', $distributor->id) }}" class="inline"
                    data-confirm="Approve all {{ $kycStatus['total'] }} KYC documents?"
                    data-confirm-title="Confirm KYC approval"
                    data-confirm-impact="Flips the account to active and purges the stored full PAN/Aadhaar, keeping only the last 4. The activation is audit-logged.">
                    @csrf
                    <button type="submit"
                        class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors">
                            Approve all documents
                    </button>
                </form>
                <details class="inline-block">
                    <summary class="cursor-pointer px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition-colors list-none">
                        Reject…
                    </summary>
                    <form method="POST" action="{{ route('admin.kyc.reject', $distributor->id) }}" class="mt-2 space-y-2 p-3 rounded-lg border border-red-200 bg-red-50"
                        data-confirm="Reject this KYC submission?"
                        data-confirm-title="Confirm KYC rejection"
                        data-confirm-impact="Sets the account to rejected and emails the distributor your reason. This is reversible — they can re-upload corrected documents.">
                        @csrf
                        <label class="block text-xs text-gray-700">Rejection reason (will be emailed to the distributor)</label>
                        <textarea name="reason" required maxlength="500" rows="3"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
                        <button type="submit"
                            class="px-3 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-medium transition-colors">
                            Confirm reject
                        </button>
                    </form>
                </details>
            @endif
        </div>
        <p class="text-xs text-gray-500 mt-3">
            Document uploads + per-document view/approve live on the dedicated KYC review page.
        </p>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-2">Password</h3>
        <p class="text-xs text-gray-700 mb-4">
            Two ways to recover access for <span class="font-mono">{{ $distributor->user->email }}</span> —
            email a reset link (distributor chooses their own new password)
            or set one directly (admin-driven, useful when the distributor
            has no email access right now).
        </p>

        {{-- A) Email reset link --}}
        <div class="mb-5 pb-5 border-b border-gray-200">
            <p class="text-xs font-semibold text-gray-700 mb-2 uppercase tracking-wider">Option A — Email a reset link</p>
            <p class="text-xs text-gray-600 mb-3">
                Sends a 60-minute reset link. If the account has never activated a password, this silently no-ops
                (the prospect should use the activation link instead).
            </p>
            <form method="POST" action="{{ route('admin.distributors.password-reset', $distributor->id) }}"
                data-confirm="Send a password reset link?"
                data-confirm-title="Confirm reset link"
                data-confirm-impact="Emails the distributor a reset link valid for 60 minutes so they can set a new password. No password changes until they use the link.">
                @csrf
                <button type="submit" class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium transition-colors">
                    Send password reset link
                </button>
            </form>
        </div>

        {{-- B) Direct set --}}
        <div>
            <p class="text-xs font-semibold text-gray-700 mb-2 uppercase tracking-wider">Option B — Set a new password directly</p>
            <p class="text-xs text-gray-600 mb-3">
                Minimum 12 characters. Same strength rules the public form uses (rejects common phrases + known-breached passwords).
                Any pending reset link is invalidated immediately. Audit-logged as <span class="font-mono">admin.distributor.password_set</span>.
            </p>
            <form method="POST" action="{{ route('admin.distributors.set-password', $distributor->id) }}" class="space-y-3" autocomplete="off"
                data-confirm="Set a new password directly?"
                data-confirm-title="Confirm new password"
                data-confirm-impact="Sets a new password on this account and invalidates any pending reset link immediately. The action is audit-logged.">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-700 mb-1" for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password" required minlength="12"
                            autocomplete="new-password"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
                        @error('new_password')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 mb-1" for="new_password_confirmation">Confirm new password</label>
                        <input type="password" id="new_password_confirmation" name="new_password_confirmation" required minlength="12"
                            autocomplete="new-password"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
                        @error('new_password_confirmation')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                    </div>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition-colors">
                    Set new password
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-2">ID photo</h3>
        <p class="text-xs text-gray-700 mb-4">
            JPG or PNG, between 200×200 and 4000×4000 pixels, max 5 MB. The image is EXIF-stripped on upload and the previous photo (if any) is deleted from storage.
        </p>

        @if(!empty($idPhotoUrl))
            <div class="mb-4 flex items-start gap-4">
                <div class="shrink-0">
                    <img src="{{ $idPhotoUrl }}"
                         alt="Current ID photo"
                         class="w-32 h-32 object-cover rounded-lg border border-gray-300 bg-gray-50">
                </div>
                <div class="text-xs text-gray-700 leading-relaxed">
                    <p class="font-semibold text-gray-800 mb-1">Current photo on file</p>
                    <p>This is what the distributor sees on their dashboard ID card and what KYC reviewers see in the queue.</p>
                    <p class="mt-2 text-gray-500">Pre-signed link valid for 15 minutes.</p>
                </div>
            </div>
        @else
            <div class="mb-4 flex items-center gap-4 rounded-lg bg-gray-50 border border-dashed border-gray-300 p-4">
                <div class="w-32 h-32 rounded-lg bg-gray-100 flex items-center justify-center text-xs text-gray-500 text-center px-2">No photo<br>uploaded yet</div>
                <p class="text-xs text-gray-700">The distributor hasn't uploaded an ID photo. Upload one below on their behalf if needed.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.distributors.id-photo', $distributor->id) }}" enctype="multipart/form-data" class="flex items-center gap-3"
            data-confirm="Upload this ID photo?"
            data-confirm-title="Confirm photo upload"
            data-confirm-impact="Replaces the distributor's current ID photo; the previous photo is deleted from storage. You can upload a different photo later.">
            @csrf
            <input type="file" name="photo" accept="image/jpeg,image/png" required
                class="text-sm text-gray-800 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold hover:file:bg-gray-200">
            <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">
                {{ !empty($idPhotoUrl) ? 'Replace photo' : 'Upload photo' }}
            </button>
        </form>
    </div>
</div>

@endsection
