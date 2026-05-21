@extends('admin.layouts.admin')
@section('title', 'Edit ' . $distributor->adn)
@section('heading', 'Edit Distributor: ' . $distributor->adn)

@section('content')

<div class="mb-6 flex items-center justify-between gap-3 flex-wrap">
    <a href="{{ route('admin.distributors.show', $distributor->id) }}" class="text-sm text-gray-700 hover:text-gray-900">← Back to profile</a>
</div>

<form method="POST" action="{{ route('admin.distributors.update', $distributor->id) }}" class="space-y-6">
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

    {{-- Locked information --}}
    <div class="bg-gray-50 rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-2">Locked information</h3>
        <p class="text-xs text-gray-700 mb-4">
            These fields are immutable for compliance reasons (DSR 2021 + DPDP 2023 + one-PAN-one-ADN). They are shown here for reference only.
        </p>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-xs text-gray-700 mb-0.5">ADN</p>
                <p class="text-gray-800 font-mono">{{ $distributor->adn }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-700 mb-0.5">PAN (last 4)</p>
                <p class="text-gray-800 font-mono">XXXXXX{{ $distributor->pan_last4 }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-700 mb-0.5">Aadhaar (last 4)</p>
                <p class="text-gray-800 font-mono">XXXX XXXX {{ $distributor->aadhaar_last4 ?? '—' }}</p>
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
            <form method="POST" action="{{ route('admin.distributors.password-reset', $distributor->id) }}">
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
            <form method="POST" action="{{ route('admin.distributors.set-password', $distributor->id) }}" class="space-y-3" autocomplete="off">
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

        <form method="POST" action="{{ route('admin.distributors.id-photo', $distributor->id) }}" enctype="multipart/form-data" class="flex items-center gap-3">
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
