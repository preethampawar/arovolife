@extends('layouts.wizard')
@section('title', 'Step 5 — Aadhaar Verification')
@php $currentStep = 6; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Aadhaar Verification</h2>
    <p class="text-gray-600 text-sm mb-8">
        Enter your 12-digit Aadhaar number. It is encrypted at rest while our compliance
        team verifies your KYC documents, then dropped — only the last 4 digits remain
        afterwards.
    </p>

    <form method="POST" action="{{ url('/register/kyc/aadhaar') }}" class="space-y-5 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Aadhaar Number <span class="text-red-700">*</span></label>
            <input name="aadhaar_number" type="text" required
                value="{{ old('aadhaar_number', $data['aadhaar_number'] ?? '') }}"
                placeholder="XXXX XXXX XXXX"
                inputmode="numeric"
                autocomplete="off"
                maxlength="14"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm tracking-widest focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                oninput="this.value=this.value.replace(/[^0-9 ]/g,'').replace(/(\d{4})(?=\d)/g,'$1 ').slice(0,14)">
            <p class="mt-1 text-xs text-gray-500">12 digits. Spaces are added automatically for readability.</p>
            @error('aadhaar_number')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="rounded-lg bg-brand-50 border border-brand-200 p-4">
            <p class="text-xs text-brand-700">
                🔒 Your Aadhaar number is encrypted at rest until your KYC documents are verified by our compliance team
                (typically within 24–48 hours). After verification it is purged from our database; only the last 4
                digits and a vendor reference token remain.
            </p>
        </div>

        <div>
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg bg-white border border-gray-200">
                <input type="checkbox" name="consent_aadhaar" value="1" required
                    class="mt-0.5 rounded text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                <span class="text-sm text-gray-700">
                    I consent to arovolife storing my Aadhaar number for KYC verification, and to its purge after my documents are verified.
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
                Continue →
            </button>
        </div>
    </form>
</div>
@endsection
