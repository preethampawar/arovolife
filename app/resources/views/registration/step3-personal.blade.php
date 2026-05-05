@extends('layouts.wizard')
@section('title', 'Step 3 — Personal Details')
@php $currentStep = 3; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Personal Details</h2>
    <p class="text-gray-600 text-sm mb-8">You must be 18+ years old (21+ in Maharashtra) to register as a Direct Seller.</p>

    <form method="POST" action="{{ url('/register/personal') }}" class="space-y-5 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Date of Birth <span class="text-red-700">*</span></label>
            <input name="date_of_birth" type="date" required
                value="{{ old('date_of_birth', $data['date_of_birth'] ?? '') }}"
                min="{{ now()->subYears(100)->format('Y-m-d') }}"
                max="{{ now()->subYears(18)->format('Y-m-d') }}"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('date_of_birth')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">State / Union Territory <span class="text-red-700">*</span></label>
            <select name="state" required
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                <option value="">Select your state</option>
                @foreach($states as $code => $name)
                <option value="{{ $code }}" {{ old('state', $data['state'] ?? '') === $code ? 'selected' : '' }}>
                    {{ $name }}
                </option>
                @endforeach
            </select>
            @error('state')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            <p class="mt-1 text-xs text-gray-500" id="state-note"></p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Residential Address <span class="text-red-700">*</span></label>
            <textarea name="address" required rows="3"
                placeholder="House/Flat No., Street, City, PIN"
                maxlength="1000"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-none">{{ old('address', $data['address'] ?? '') }}</textarea>
            @error('address')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        @php
            $isCouple = old('register_with_spouse', ($data['couple_enabled'] ?? false) ? 'yes' : null) === 'yes';
            $sp = $data['spouse'] ?? [];
        @endphp

        {{-- Couple registration toggle --}}
        <div class="border-t border-gray-100 pt-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="register_with_spouse" value="yes" id="register_with_spouse"
                    {{ $isCouple ? 'checked' : '' }}
                    class="mt-0.5 w-4 h-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900">Register with my spouse (couple registration)</span>
                    <span class="block text-xs text-gray-500 mt-0.5">
                        Both of you will sign the Direct Seller Agreement and complete KYC. Per T&amp;C §7,
                        a couple shares one business unit and one ADN — only the primary's ADN is used externally.
                    </span>
                </span>
            </label>
        </div>

        {{-- Spouse fields — shown only when the toggle is on --}}
        <div id="spouse-fields" class="space-y-5 {{ $isCouple ? '' : 'hidden' }}">
            <div class="rounded-lg bg-gray-50 border border-gray-200 p-5 space-y-4">
                <p class="text-xs uppercase tracking-wider text-gray-500 font-medium">Spouse details</p>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Spouse full name <span class="text-red-700">*</span></label>
                    <input name="spouse_full_name" type="text" maxlength="255"
                        value="{{ old('spouse_full_name', $sp['spouse_full_name'] ?? '') }}"
                        class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    @error('spouse_full_name')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Spouse date of birth <span class="text-red-700">*</span></label>
                    <input name="spouse_dob" type="date"
                        value="{{ old('spouse_dob', $sp['spouse_dob'] ?? '') }}"
                        min="{{ now()->subYears(100)->format('Y-m-d') }}"
                        max="{{ now()->subYears(18)->format('Y-m-d') }}"
                        class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    @error('spouse_dob')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Spouse email <span class="text-red-700">*</span></label>
                    <input name="spouse_email" type="email" maxlength="255"
                        value="{{ old('spouse_email', $sp['spouse_email'] ?? '') }}"
                        class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    @error('spouse_email')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Spouse mobile <span class="text-red-700">*</span></label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3 py-2.5 border border-r-0 border-gray-200 bg-gray-100 rounded-l-lg text-sm text-gray-700">+91</span>
                        <input name="spouse_phone_e164" type="text" inputmode="numeric" maxlength="10"
                            value="{{ old('spouse_phone_e164', $sp['spouse_phone_e164'] ?? '') }}"
                            placeholder="9876543210"
                            class="flex-1 rounded-r-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    @error('spouse_phone_e164')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <button type="submit"
            class="w-full rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
            Continue to PAN Verification →
        </button>
    </form>
</div>

<script>
// State-aware minimum age. Maharashtra requires 21+ (T&C §1.1); rest of
// India is 18+. We update the `max` on BOTH DOB inputs so the date picker
// blocks under-age selection client-side; server-side still re-validates
// against the admin-configurable settings.compliance.state_age_minimums.
function setMaxDob(years) {
    const d = new Date();
    d.setFullYear(d.getFullYear() - years);
    const max = d.toISOString().slice(0, 10);
    const primary = document.querySelector('[name="date_of_birth"]');
    const spouse = document.querySelector('[name="spouse_dob"]');
    if (primary) primary.max = max;
    if (spouse) spouse.max = max;
}

document.querySelector('[name="state"]').addEventListener('change', function() {
    const note = document.getElementById('state-note');
    const minAge = (this.value === 'MH') ? 21 : 18;
    if (this.value === 'MH') {
        note.textContent = 'Maharashtra requires minimum age of 21 years for both you and your spouse (if applicable).';
        note.className = 'mt-1 text-xs text-amber-700';
    } else {
        note.textContent = '';
    }
    setMaxDob(minAge);
});

// Toggle spouse fields when checkbox flips. Form submission still validates
// server-side so this is purely a UX nicety.
(function () {
    const checkbox = document.getElementById('register_with_spouse');
    const panel = document.getElementById('spouse-fields');
    if (!checkbox || !panel) return;

    // If the user has already saved spouse data (couple_enabled == true on
    // initial render), un-checking the box deletes all spouse fields AND
    // any spouse KYC files already uploaded. Confirm before allowing it.
    const startedAsCouple = {{ ($data['couple_enabled'] ?? false) ? 'true' : 'false' }};

    checkbox.addEventListener('change', function () {
        if (!this.checked && startedAsCouple) {
            const ok = window.confirm(
                "Are you sure?\n\n" +
                "Unchecking this will permanently delete all the spouse details you have entered " +
                "(name, date of birth, email, mobile, PAN, Aadhaar) and any spouse KYC documents " +
                "you have uploaded. This cannot be undone.\n\n" +
                "Click OK to proceed, or Cancel to keep the spouse registration."
            );
            if (!ok) {
                this.checked = true;
                return;
            }
        }
        panel.classList.toggle('hidden', !this.checked);
        // When the user un-checks, also clear the in-form spouse inputs so
        // a stray submit doesn't carry stale strings into the request body.
        if (!this.checked) {
            panel.querySelectorAll('input').forEach((el) => { el.value = ''; });
        }
    });
})();
</script>
@endsection
