@extends('layouts.wizard')
@section('title', 'Step 10 — Confirm & Complete')
@php $currentStep = 10; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Review & Finalise</h2>
    <p class="text-gray-600 text-sm mb-8">
        Please review your details. Once confirmed, your arovolife Distributor Number (ADN) will be issued.
    </p>

    <div class="bg-white rounded-2xl border border-gray-200 p-8 mb-6 space-y-4">
        @if($personal)
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-gray-500 text-xs uppercase tracking-wider mb-0.5">Date of Birth</p>
                <p class="text-gray-800">{{ $personal['date_of_birth'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-gray-500 text-xs uppercase tracking-wider mb-0.5">State</p>
                <p class="text-gray-800">{{ $personal['state'] ?? '—' }}</p>
            </div>
        </div>
        @endif

        @if($pan)
        <div class="text-sm">
            <p class="text-gray-500 text-xs uppercase tracking-wider mb-0.5">PAN (last 4)</p>
            <p class="text-gray-800">XXXXXX{{ substr($pan['pan_number'] ?? '????', -4) }}</p>
        </div>
        @endif

        @if($isCouple ?? false)
        <div class="rounded-lg bg-amber-50 border border-amber-200 p-4">
            <p class="text-xs font-medium text-amber-800">Couple registration</p>
            <p class="text-xs text-amber-800 mt-1">
                You and <strong>{{ $spouse['spouse_full_name'] ?? 'your spouse' }}</strong> will both be registered as
                co-distributors. We'll email <strong>{{ $spouse['spouse_email'] ?? '' }}</strong> a separate activation
                link so they can set their own password and view their account.
            </p>
        </div>
        @endif

        <div class="rounded-lg bg-brand-50 border border-brand-500/50 p-4">
            <p class="text-xs text-brand-500 font-medium">30-Day Cooling-Off Period</p>
            <p class="text-xs text-brand-600 mt-1">
                After registration you have <strong>30 days</strong> to cancel with no penalty. Joining is free of
                charge — there is nothing to refund at registration; once paid memberships exist, any fees paid
                during the window will be returned in full. One-click cancellation is available from your dashboard.
            </p>
        </div>
    </div>

    <form method="POST" action="{{ url('/register/complete') }}">
        @csrf
        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('register.documents') }}"
               class="inline-flex items-center px-5 py-4 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-base font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-bold py-4 text-base transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Confirm & Issue My ADN →
            </button>
        </div>
    </form>

    <p class="mt-4 text-center text-xs text-gray-400">
        By confirming you agree to all previously accepted terms. Registration is free of charge.
    </p>
</div>
@endsection
