@extends('layouts.wizard')
@section('title', 'Step 7 — Nominee Details')
@php $currentStep = 7; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Nominee Details</h2>
    <p class="text-gray-600 text-sm mb-6">
        Designate someone to act on your behalf in case of unforeseen circumstances. This is optional.
    </p>

    {{-- Skip option --}}
    <div class="mb-4 flex justify-end">
        <form method="POST" action="{{ route('register.nominee.submit') }}">
            @csrf
            <input type="hidden" name="skip" value="1">
            <button type="submit" class="text-sm text-gray-500 underline hover:text-gray-700 transition-colors">
                Skip for now
            </button>
        </form>
    </div>

    <form method="POST" action="{{ route('register.nominee.submit') }}" class="space-y-6 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

        {{-- Full Name --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Full Name <span class="text-red-700">*</span>
            </label>
            <input name="full_name" type="text" required
                value="{{ old('full_name', $nominee['full_name'] ?? '') }}"
                placeholder="As on government ID"
                maxlength="191"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('full_name')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Relationship --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Relationship <span class="text-red-700">*</span>
            </label>
            <select name="relationship" required
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                <option value="" disabled {{ old('relationship', $nominee['relationship'] ?? '') === '' ? 'selected' : '' }}>Select relationship</option>
                @foreach([
                    'spouse'  => 'Spouse',
                    'child'   => 'Child',
                    'parent'  => 'Parent',
                    'sibling' => 'Sibling',
                    'other'   => 'Other',
                ] as $value => $label)
                <option value="{{ $value }}" {{ old('relationship', $nominee['relationship'] ?? '') === $value ? 'selected' : '' }}>
                    {{ $label }}
                </option>
                @endforeach
            </select>
            @error('relationship')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Date of Birth --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Date of Birth <span class="text-red-700">*</span>
            </label>
            <input name="date_of_birth" type="date" required
                value="{{ old('date_of_birth', $nominee['date_of_birth'] ?? '') }}"
                max="{{ date('Y-m-d', strtotime('-1 day')) }}"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('date_of_birth')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- PAN Number (optional) --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                PAN Number <span class="text-xs text-gray-500 font-normal">(optional)</span>
            </label>
            <input name="pan_number" type="text"
                value="{{ old('pan_number', $nominee['pan_number'] ?? '') }}"
                placeholder="e.g. ABCDE1234F"
                maxlength="20"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('pan_number')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Aadhaar Number (optional) --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Aadhaar Number <span class="text-xs text-gray-500 font-normal">(optional)</span>
            </label>
            <input name="aadhaar" type="text" inputmode="numeric"
                value="{{ old('aadhaar', '') }}"
                placeholder="12-digit Aadhaar number"
                maxlength="12"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p class="mt-1 text-xs text-gray-500">The nominee's Aadhaar number will be encrypted at rest and never displayed in full.</p>
            @error('aadhaar')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Mobile (optional) --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Mobile Number <span class="text-xs text-gray-500 font-normal">(optional)</span>
            </label>
            <input name="mobile" type="tel"
                value="{{ old('mobile', $nominee['mobile'] ?? '') }}"
                placeholder="10-digit mobile number"
                maxlength="15"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('mobile')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Email (optional) --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Email Address <span class="text-xs text-gray-500 font-normal">(optional)</span>
            </label>
            <input name="email" type="email"
                value="{{ old('email', $nominee['email'] ?? '') }}"
                placeholder="nominee@example.com"
                maxlength="191"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('email')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Address (optional) --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Address <span class="text-xs text-gray-500 font-normal">(optional)</span>
            </label>
            <textarea name="address" rows="3" maxlength="2000"
                placeholder="Nominee's full residential address"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-none">{{ old('address', $nominee['address'] ?? '') }}</textarea>
            @error('address')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <hr class="border-gray-100">

        {{-- Consent Checkbox --}}
        <div>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="consent" value="1" required
                    {{ old('consent') ? 'checked' : '' }}
                    class="mt-0.5 text-brand-600 border-gray-300 rounded focus:ring-brand-500">
                <span class="text-sm text-gray-700">
                    I confirm the nominee has been informed of this data collection and consents to its use for succession purposes.
                    <span class="text-red-700">*</span>
                </span>
            </label>
            @error('consent')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        @include('registration._draft_notice')

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.demographics') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                &larr; Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Save &amp; Continue &rarr;
            </button>
        </div>
    </form>
</div>
@endsection
