@extends('layouts.wizard')
@section('title', 'Step 5 — Identity Documents')
@php $currentStep = 5; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Identity Documents</h2>
    <p class="text-gray-600 text-sm mb-8">
        Provide your PAN and Aadhaar numbers. One PAN can only be linked to one arovolife
        Distributor Number (ADN). Both are held encrypted while our compliance team verifies
        your KYC documents, then dropped — only the last 4 digits of each remain afterwards.
    </p>

    <form method="POST" action="{{ route('register.identity-documents') }}" class="space-y-6 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

        {{-- PAN section --}}
        <div>
            <h3 class="text-base font-semibold text-gray-800 mb-4">PAN Details</h3>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">PAN Number <span class="text-red-700">*</span></label>
                <input name="pan_number" type="text" required
                    value="{{ old('pan_number', $data['pan_number'] ?? '') }}"
                    placeholder="ABCDE1234F"
                    pattern="[A-Z]{5}[0-9]{4}[A-Z]"
                    maxlength="10"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                    oninput="this.value=this.value.toUpperCase()">
                <p class="mt-1 text-xs text-gray-500">Format: 5 letters + 4 digits + 1 letter (e.g., ABCDE1234F)</p>
                @error('pan_number')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>

            @if($isCouple ?? false)
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Spouse PAN Number <span class="text-red-700">*</span></label>
                <input name="spouse_pan_number" type="text" required
                    value="{{ old('spouse_pan_number', $data['spouse_pan_number'] ?? '') }}"
                    placeholder="PQRSE5678G"
                    pattern="[A-Z]{5}[0-9]{4}[A-Z]"
                    maxlength="10"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                    oninput="this.value=this.value.toUpperCase()">
                <p class="mt-1 text-xs text-gray-500">Spouse's PAN. Must differ from yours.</p>
                @error('spouse_pan_number')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
            @endif
        </div>

        <hr class="border-gray-100">

        {{-- Aadhaar section --}}
        <div>
            <h3 class="text-base font-semibold text-gray-800 mb-4">Aadhaar Details</h3>

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

            @if($isCouple ?? false)
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Spouse Aadhaar Number <span class="text-red-700">*</span></label>
                <input name="spouse_aadhaar_number" type="text" required
                    value="{{ old('spouse_aadhaar_number', $data['spouse_aadhaar_number'] ?? '') }}"
                    placeholder="XXXX XXXX XXXX"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="14"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm tracking-widest focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                    oninput="this.value=this.value.replace(/[^0-9 ]/g,'').replace(/(\d{4})(?=\d)/g,'$1 ').slice(0,14)">
                <p class="mt-1 text-xs text-gray-500">Spouse's 12-digit Aadhaar number.</p>
                @error('spouse_aadhaar_number')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
            @endif

            <div class="rounded-lg bg-brand-50 border border-brand-200 p-4 mt-4">
                <p class="text-xs text-brand-700">
                    Your PAN and Aadhaar are encrypted at rest until our compliance team verifies your KYC
                    documents (typically within 24–48 hours). After verification they are purged from our
                    database; only the last 4 digits and a one-way hash remain for duplicate-prevention.
                </p>
            </div>

            <div class="mt-4">
                <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg bg-white border border-gray-200">
                    <input type="checkbox" name="consent_aadhaar" value="1" required
                        {{ old('consent_aadhaar') ? 'checked' : '' }}
                        class="mt-0.5 rounded text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                    <span class="text-sm text-gray-700">
                        I consent to Aadhaar-based identity verification for KYC purposes.
                    </span>
                </label>
                @error('consent_aadhaar')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
        </div>

        @include('registration._draft_notice')

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.consent') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                &larr; Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Verify Identity &amp; Continue &rarr;
            </button>
        </div>
    </form>
</div>
@endsection
