@extends('layouts.wizard')
@section('title', 'Step 4 — PAN Verification')
@php $currentStep = 5; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">PAN Verification</h2>
    <p class="text-gray-600 text-sm mb-8">
        One PAN can only be linked to one arovolife Distributor Number (ADN).
        Your PAN is held encrypted while our compliance team verifies your KYC documents,
        then dropped — only the last 4 digits remain afterwards.
    </p>

    <form method="POST" action="{{ url('/register/kyc/pan') }}" class="space-y-5 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

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
        <div>
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

        <div class="rounded-lg bg-brand-50 border border-brand-200 p-4">
            <p class="text-xs text-brand-700">
                🔒 Your PAN is encrypted at rest until your KYC documents are verified by our compliance team
                (typically within 24–48 hours). After verification it is purged from our database; only the last 4
                digits and a one-way hash remain for duplicate-prevention.
            </p>
        </div>

        @include('registration._draft_notice')

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.consent') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Verify PAN & Continue →
            </button>
        </div>
    </form>
</div>
@endsection
