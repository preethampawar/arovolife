@extends('layouts.wizard')
@section('title', 'Step 8 — Placement')
@php $currentStep = 8; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <div class="mb-7 lift-in" style="animation-delay: 80ms;">
        <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Binary tree placement</p>
        <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight mb-4">
            Your <span class="text-brand-600">placement</span> is locked.
        </h1>
        <p class="text-base text-gray-600 max-w-lg">
            Your sponsor selected this position when they shared the referral link with you.
            It cannot be changed from here.
        </p>
    </div>

    <form method="POST" action="{{ url('/register/placement') }}" class="card-refined p-7 sm:p-8 space-y-5 lift-in" style="animation-delay: 200ms;">
        @csrf

        @if($sponsor)
        <div class="rounded-xl border border-brand-200/60 bg-gradient-to-br from-brand-50 to-white p-4 lift-in" style="animation-delay: 320ms;">
            <p class="text-[11px] uppercase tracking-[0.18em] text-brand-700 font-semibold mb-1.5">Sponsor</p>
            <p class="font-mono text-base text-brand-700 font-semibold tracking-wider">{{ $sponsor->adn }}</p>
            <p class="text-xs text-slate-500 mt-1">Compensation details are explained in the Direct Seller Agreement you'll sign at the next step.</p>
        </div>
        @endif

        @if($placement)
        <div class="rounded-xl border border-slate-200 bg-white p-4 lift-in" style="animation-delay: 380ms;">
            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold mb-1.5">Placement target</p>
            <p class="font-mono text-base text-slate-800 font-semibold tracking-wider">{{ $placement->adn }}</p>
            @if($sideOpt)
                <p class="text-xs text-slate-600 mt-1.5">
                    You will be placed on the
                    <strong class="text-brand-700">{{ $sideOpt === 'L' ? 'left' : 'right' }} leg</strong>
                    of this distributor.
                </p>
            @else
                <p class="text-xs text-slate-600 mt-1.5">
                    You will be placed on the first available leg under this distributor (left preferred).
                </p>
            @endif
        </div>
        @endif

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('register.documents') }}"
               class="inline-flex items-center px-5 py-3 rounded-full border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="btn-cta group flex-1 rounded-full bg-brand-500 hover:bg-brand-600 text-white py-3.5 text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-brand-300/40 lift-in shadow-lg shadow-brand-500/30 hover:shadow-xl hover:shadow-brand-500/40"
                style="animation-delay: 440ms;">
                <span class="inline-flex items-center justify-center gap-2.5">
                    Continue to consent
                    <svg class="btn-arrow w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 8h11M9 4l4 4-4 4"/>
                    </svg>
                </span>
            </button>
        </div>
    </form>
</div>
@endsection
