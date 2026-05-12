@extends('layouts.wizard')
@section('title', 'Step 5 — Aadhaar Verification')
@php $currentStep = 6; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Aadhaar Verification</h2>
    <p class="text-gray-600 text-sm mb-8">
        Aadhaar verification is conducted via a UIDAI-approved AUA/KUA partner.
        <strong class="text-gray-700">Raw Aadhaar numbers are never stored</strong> — only a reference ID and last 4 digits.
    </p>

    <form method="POST" action="{{ url('/register/kyc/aadhaar') }}" class="space-y-5 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Last 4 digits of Aadhaar <span class="text-red-700">*</span></label>
            <input name="aadhaar_last4" type="text" required
                value="{{ old('aadhaar_last4', $data['last4'] ?? '') }}"
                placeholder="XXXX"
                pattern="\d{4}"
                inputmode="numeric"
                autocomplete="off"
                minlength="4"
                maxlength="4"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm tracking-widest focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('aadhaar_last4')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        @if($isCouple ?? false)
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Last 4 digits of spouse's Aadhaar <span class="text-red-700">*</span></label>
            <input name="spouse_aadhaar_last4" type="text" required
                value="{{ old('spouse_aadhaar_last4', $data['spouse_last4'] ?? '') }}"
                placeholder="XXXX"
                pattern="\d{4}"
                inputmode="numeric"
                autocomplete="off"
                minlength="4"
                maxlength="4"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm tracking-widest focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p class="mt-1 text-xs text-gray-500">Must differ from yours.</p>
            @error('spouse_aadhaar_last4')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        @endif

        <div class="rounded-lg bg-brand-50 border border-brand-200 p-4">
            <p class="text-xs text-brand-700">
                🔒 Aadhaar verification is performed via UIDAI-approved gateway. Only the last 4 digits and a reference
                token from the gateway are stored — never the full 12-digit number.
                (Phase 1: stub verification — gateway integration in Phase 2.)
            </p>
        </div>

        <div>
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg bg-white border border-gray-200">
                <input type="checkbox" name="consent_aadhaar" value="1" required
                    class="mt-0.5 rounded text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                <span class="text-sm text-gray-700">
                    I consent to verification of my Aadhaar details via the UIDAI-approved gateway for identity verification purposes.
                </span>
            </label>
            @error('consent_aadhaar')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.pan') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Verify Aadhaar & Continue →
            </button>
        </div>
    </form>
</div>
@endsection
