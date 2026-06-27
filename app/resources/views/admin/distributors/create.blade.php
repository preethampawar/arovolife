@extends('admin.layouts.admin')
@section('title', 'Add Distributor')
@section('heading', 'Add Distributor')

@section('content')

<div class="mb-6 flex items-center justify-between gap-3 flex-wrap">
    <a href="{{ route('admin.distributors.index') }}" class="text-sm text-gray-700 hover:text-gray-900">← Back to Distributors</a>
</div>

<div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
    <p class="font-semibold mb-1">Admin-attested registration</p>
    <p class="text-xs">
        You are creating this account on the prospect's behalf. Orientation and consent will be marked admin-attested in the audit log;
        the prospect must still sign the paper Direct Seller Agreement. The account is created with no password — an activation link is
        emailed automatically so the prospect can set their own.
    </p>
</div>

<form method="POST" action="{{ route('admin.distributors.store') }}" class="space-y-6">
    @csrf

    {{-- Sponsor & Placement --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-4">Sponsor & Placement</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="sponsor_adn">Sponsor ADN <x-help-tip text="ADN of the distributor who introduced this joiner. Sets the sponsorship relationship; this does not change the Genos placement." /></label>
                <input type="text" id="sponsor_adn" name="sponsor_adn" required maxlength="11" minlength="9"
                    value="{{ old('sponsor_adn') }}"
                    placeholder="e.g. 444555666"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="placement_adn">Placement ADN <span class="text-gray-600">(usually = sponsor)</span> <x-help-tip text="ADN of the distributor under whom this person sits in the Genos placement tree. Usually the same as the sponsor." /></label>
                <input type="text" id="placement_adn" name="placement_adn" required maxlength="11" minlength="9"
                    value="{{ old('placement_adn') }}"
                    placeholder="e.g. 444555666"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="side">Side <span class="text-gray-600">(optional)</span> <x-help-tip text="Which group (left or right) under the placement parent to attach to. Leave on Auto to use the first open slot." /></label>
                <select id="side" name="side"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Auto (first open slot)</option>
                    <option value="L" @selected(old('side') === 'L')>L — Left</option>
                    <option value="R" @selected(old('side') === 'R')>R — Right</option>
                </select>
            </div>
        </div>
    </div>

    {{-- User --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-4">User</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="full_name">Full name <x-help-tip text="The prospect's legal full name as per their PAN." /></label>
                <input type="text" id="full_name" name="full_name" required maxlength="120"
                    value="{{ old('full_name') }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="email">Email <x-help-tip text="The prospect's email address; used for login and to send the account activation link." /></label>
                <input type="email" id="email" name="email" required maxlength="191"
                    value="{{ old('email') }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="phone_e164">Mobile (10-digit Indian) <x-help-tip text="A 10-digit Indian mobile number; used for login and SMS notifications." /></label>
                <input type="text" id="phone_e164" name="phone_e164" required maxlength="10" minlength="10" inputmode="numeric"
                    value="{{ old('phone_e164') }}"
                    placeholder="e.g. 9876543210"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="date_of_birth">Date of birth <x-help-tip text="The prospect's date of birth; used to enforce the per-state minimum-age rule." /></label>
                <input type="date" id="date_of_birth" name="date_of_birth" required
                    value="{{ old('date_of_birth') }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
        </div>
    </div>

    {{-- Identity --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-4">Identity (PAN + Aadhaar)</h3>
        <p class="text-xs text-gray-700 mb-4">
            Both PAN and Aadhaar are encrypted at rest. Only the last 4 of each is shown in the audit log. After KYC approval the encrypted blobs are nulled and the original uploaded scans are purged.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="pan_number">PAN <x-help-tip text="Permanent Account Number — used for KYC/tax. One PAN maps to one distributor; stored encrypted." /></label>
                <input type="text" id="pan_number" name="pan_number" required maxlength="10" minlength="10"
                    value="{{ old('pan_number') }}"
                    placeholder="ABCDE1234F"
                    style="text-transform: uppercase"
                    autocomplete="off"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="aadhaar_number">Aadhaar (12 digits) <x-help-tip text="12-digit Aadhaar for identity verification. Only the last 4 digits are retained after the verification partner confirms it." /></label>
                <input type="text" id="aadhaar_number" name="aadhaar_number" required maxlength="14" inputmode="numeric"
                    value="{{ old('aadhaar_number') }}"
                    placeholder="1234 5678 9012"
                    autocomplete="off"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
        </div>
    </div>

    {{-- Bank (optional — server-side rule is nullable + required_with;
         leave both fields blank to skip, or fill both to record bank). --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-1">Bank <span class="text-gray-500 text-sm font-normal">(optional)</span></h3>
        <p class="text-xs text-gray-600 mb-4">
            Leave both fields blank if the distributor hasn't shared bank
            details yet — they can be added later from the edit page.
            If you fill one, both are required.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="bank_account">Bank account number <x-help-tip text="Bank details used only for future bonus payouts; stored securely. Leave blank to skip; if you fill one bank field, both are required." /></label>
                {{-- maxlength + pattern stay so digit-only typing is still
                     enforced when present; required + minlength removed
                     so blank values pass the browser check. The server
                     re-validates with required_with so a half-filled bank
                     (account but no IFSC) still gets rejected. --}}
                <input type="text" id="bank_account" name="bank_account"
                    maxlength="18" inputmode="numeric" pattern="\d{9,18}"
                    value="{{ old('bank_account') }}"
                    autocomplete="off"
                    placeholder="9–18 digits, or leave blank"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="bank_ifsc">Bank IFSC <x-help-tip text="11-character IFSC of the bank branch; used only for future bonus payouts. Stored securely." /></label>
                <input type="text" id="bank_ifsc" name="bank_ifsc"
                    maxlength="11" pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}"
                    value="{{ old('bank_ifsc') }}"
                    style="text-transform: uppercase"
                    placeholder="HDFC0001234 (or blank)"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
        </div>
    </div>

    {{-- State --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h3 class="font-semibold text-gray-800 mb-4">State</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-700 mb-1" for="state">State <x-help-tip text="The prospect's state of residence; determines the minimum-age rule applied at registration." /></label>
                <select id="state" name="state" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">— pick a state —</option>
                    @foreach($states as $code => $name)
                        <option value="{{ $code }}" @selected(old('state') === $code)>{{ $name }} ({{ $code }})</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">
            Create distributor
        </button>
        <a href="{{ route('admin.distributors.index') }}"
           class="px-5 py-2.5 rounded-lg bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium transition-colors">
            Cancel
        </a>
    </div>
</form>

@endsection
