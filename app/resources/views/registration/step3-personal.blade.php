@extends('layouts.wizard')
@section('title', 'Step 3 — Personal Details')
@php $currentStep = 8; @endphp

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

        {{-- Couple-registration toggle hidden in this step order.
             The controller hardcodes $isCouple = false in handlePersonal()
             because Personal moved to step 8 (after PAN/Aadhaar/Documents),
             so the toggle would land too late to gate spouse-data collection.
             Re-enable by moving the toggle to step 2 (Account) and undoing
             this hide + the controller hardcoded flag (search US-1.13). --}}

        @include('registration._draft_notice')

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.bank') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Continue to Documents →
            </button>
        </div>
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
    if (primary) primary.max = max;
}

document.querySelector('[name="state"]').addEventListener('change', function() {
    const note = document.getElementById('state-note');
    const minAge = (this.value === 'MH') ? 21 : 18;
    if (this.value === 'MH') {
        note.textContent = 'Maharashtra requires a minimum age of 21 years.';
        note.className = 'mt-1 text-xs text-amber-700';
    } else {
        note.textContent = '';
    }
    setMaxDob(minAge);
});
</script>
@endsection
