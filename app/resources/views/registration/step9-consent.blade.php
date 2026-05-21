@extends('layouts.wizard')
@section('title', 'Step 9 — Legal Consent')
@php $currentStep = 4; @endphp

@section('content')
<div class="max-w-2xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Legal Agreements</h2>
    <p class="text-gray-600 text-sm mb-8">
        Please read and accept all four documents. Your acceptance is binding under the
        Information Technology Act, 2000 (§10A) and constitutes a valid electronic contract.
        Your IP address and browser fingerprint will be recorded.
    </p>

    <form method="POST" action="{{ url('/register/consent') }}" class="space-y-5">
        @csrf

        {{-- TnC --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Direct Seller Agreement & T&amp;C</h3>
                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">v1.0.0</span>
            </div>
            <div class="bg-white rounded-lg p-4 text-xs text-gray-600 max-h-32 overflow-y-auto leading-relaxed mb-4">
                This Direct Seller Agreement governs your relationship with Arovolife Private Limited (CIN U46909TS2026PTC210896).
                As a Direct Seller you agree to: (1) sell arovolife products only to end consumers directly; (2) not charge any joining fees;
                (3) comply with the Consumer Protection (Direct Selling) Rules, 2021; (4) maintain ethical conduct per the Code of Ethics;
                (5) honour the 30-day cooling-off right of any person you recruit.
                Registration is free of cost. No forced purchase is required. Income is derived solely from product sales, not recruitment.
            </div>
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg bg-white border border-gray-200 hover:border-brand-500 transition-colors">
                <input type="checkbox" name="consent_tnc" value="1" required
                    class="mt-0.5 rounded text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                <span class="text-sm text-gray-700">
                    I have read and accept the <strong class="text-gray-800">Direct Seller Agreement &amp; Terms and Conditions</strong> (v1.0.0)
                </span>
            </label>
            @error('consent_tnc')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        {{-- Code of Ethics --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Code of Ethics</h3>
                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">v1.0.0</span>
            </div>
            <div class="bg-white rounded-lg p-4 text-xs text-gray-600 max-h-32 overflow-y-auto leading-relaxed mb-4">
                As a Direct Seller I will: (1) make no false or misleading claims about products or earnings;
                (2) not imply guaranteed or assured income to prospects; (3) not recruit minors;
                (4) not sell on e-commerce marketplaces or offline retail stores;
                (5) honour consumer cooling-off and refund rights; (6) not pressurise prospects;
                (7) maintain transparency in all dealings.
            </div>
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg bg-white border border-gray-200 hover:border-brand-500 transition-colors">
                <input type="checkbox" name="consent_ethics" value="1" required
                    class="mt-0.5 rounded text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                <span class="text-sm text-gray-700">
                    I have read and accept the <strong class="text-gray-800">Code of Ethics</strong> (v1.0.0)
                </span>
            </label>
            @error('consent_ethics')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        {{-- Compensation Plan --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Compensation Plan Disclosure</h3>
                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">v1.0.0</span>
            </div>
            <div class="bg-white rounded-lg p-4 text-xs text-gray-600 max-h-32 overflow-y-auto leading-relaxed mb-4">
                All commissions, bonuses and rewards under the arovolife compensation plan are derived exclusively
                from the sale of products to end consumers. No commission, bonus or payout of any kind may be
                earned solely from the act of recruiting new Direct Sellers.
                The plan details are available in the Product Catalogue (published separately).
                Income amounts shown in any plan illustration represent maximum achievable levels
                based on historical top performer data — they are not guarantees of typical results.
            </div>
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg bg-white border border-gray-200 hover:border-brand-500 transition-colors">
                <input type="checkbox" name="consent_plan" value="1" required
                    class="mt-0.5 rounded text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                <span class="text-sm text-gray-700">
                    I have read and understand the <strong class="text-gray-800">Compensation Plan Disclosure</strong> (v1.0.0)
                </span>
            </label>
            @error('consent_plan')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        {{-- Privacy Notice --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Privacy Notice (DPDP Act 2023)</h3>
                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">v1.0.0</span>
            </div>
            <div class="bg-white rounded-lg p-4 text-xs text-gray-600 max-h-32 overflow-y-auto leading-relaxed mb-4">
                arovolife collects your personal data for the limited purpose of managing your Direct Seller account.
                Data collected includes: name, email, phone, PAN (hashed), Aadhaar last-4 + reference, bank IFSC.
                Raw Aadhaar is never stored. PAN is stored as hash + last-4 only.
                Your data is retained for 8 years as required by DSR 2021.
                You have the right to access, correct and request erasure of data beyond statutory retention.
                Data Fiduciary contact: privacy@arovolife.com
            </div>
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg bg-white border border-gray-200 hover:border-brand-500 transition-colors">
                <input type="checkbox" name="consent_privacy" value="1" required
                    class="mt-0.5 rounded text-brand-600 border-gray-300 bg-gray-100 focus:ring-brand-500">
                <span class="text-sm text-gray-700">
                    I have read and accept the <strong class="text-gray-800">Privacy Notice</strong> (v1.0.0)
                </span>
            </label>
            @error('consent_privacy')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        @include('registration._draft_notice')

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.orientation') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Accept All & Continue →
            </button>
        </div>
    </form>
</div>
@endsection
