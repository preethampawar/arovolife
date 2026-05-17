@extends('layouts.wizard')
@section('title', 'Step 6 — Bank Details')
@php $currentStep = 7; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Bank Account Details</h2>
    <p class="text-gray-600 text-sm mb-8">
        Your bank account details are required for commission payouts (Phase 2+).
        Account numbers are encrypted before storage.
    </p>

    <form method="POST" action="{{ url('/register/kyc/bank') }}" class="space-y-5 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Account Number <span class="text-red-700">*</span></label>
            <input name="account_number" type="text" required
                value="{{ old('account_number', $data['account_number'] ?? '') }}"
                placeholder="Enter your bank account number"
                pattern="\d{9,18}"
                inputmode="numeric"
                autocomplete="off"
                minlength="9"
                maxlength="18"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p class="mt-1 text-xs text-gray-500">9–18 digits, numbers only</p>
            @error('account_number')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">IFSC Code <span class="text-red-700">*</span></label>
            <input name="ifsc" type="text" required
                value="{{ old('ifsc', $data['ifsc'] ?? '') }}"
                placeholder="e.g. HDFC0001234"
                pattern="[A-Z]{4}0[A-Z0-9]{6}"
                maxlength="11"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm uppercase tracking-wider focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                oninput="this.value=this.value.toUpperCase()">
            <p class="mt-1 text-xs text-gray-500">11-character IFSC code on your cheque book</p>
            @error('ifsc')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="rounded-lg bg-brand-50 border border-brand-200 p-4">
            <p class="text-xs text-brand-700">
                🔒 Your account number is encrypted (AES-256) before being stored.
                Penny-drop verification will be performed before first payout.
                (Phase 1: stub verification.)
            </p>
        </div>

        @include('registration._draft_notice')

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.aadhaar') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Save Bank Details & Continue →
            </button>
        </div>
    </form>
</div>
@endsection
