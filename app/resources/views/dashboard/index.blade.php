@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')

@if(session('adn_issued'))
<div class="mb-8 rounded-2xl border border-green-200 bg-green-50 p-6">
    <h3 class="text-lg font-bold text-green-700 mb-1">Registration submitted</h3>
    <p class="text-sm text-green-700">
        Welcome to arovolife. Your Distributor Number (ADN) has been issued and your
        30-day cooling-off period begins today. Your KYC documents are under review by
        an admin - you will be notified once approved.
    </p>
</div>
@endif

@if($distributor && $user->status === 'pending')
<div class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 p-6">
    <h3 class="text-base font-semibold text-amber-800 mb-1">KYC under review</h3>
    <p class="text-sm text-amber-800 mb-3">
        An admin is reviewing the PAN, Aadhaar, bank, and address-proof documents you uploaded.
        Most reviews complete within 1–2 business days. You will receive an email when the
        review is done.
    </p>
    <a href="{{ route('dashboard.documents') }}" class="inline-flex items-center text-sm text-amber-900 font-semibold underline hover:no-underline">
        Add or replace documents →
    </a>
</div>
@endif

@php
    $accountStatus = $user->accountStatusLabel();
    $hasDistributorBlock = $distributor !== null;
    $inviteUrl = $hasDistributorBlock ? url('/join').'?sponsor='.$distributor->adn : null;
    $bothFull  = $hasDistributorBlock ? (! $leftOpen && ! $rightOpen) : false;
@endphp

@include('dashboard._hero')

@if($distributor)
    @include('dashboard._kpi-strip')
    @include('dashboard._quick-actions')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2 flex flex-col gap-6 min-w-0">
            @include('dashboard._genos-balance')
            @include('dashboard._team-growth')
            @if($bonusSummary !== [])
                @include('dashboard._income-snapshot')
            @endif
        </div>
        <div class="flex flex-col gap-6 min-w-0">
            @include('dashboard._placement')
            @include('dashboard._cooling-off')
            @include('dashboard._messages')
        </div>
    </div>

    <div class="mb-6">
        @include('dashboard._profile-stats')
    </div>

    <div class="mb-6">
        @include('dashboard._documents')
    </div>

    @include('dashboard._my-team')
@else
    {{-- Registration incomplete --}}
    <div class="bg-white rounded-2xl border border-amber-200 p-8 text-center">
        <p class="text-amber-700 font-semibold mb-2">Registration not yet complete</p>
        <p class="text-sm text-gray-800 mb-4">Complete your registration to receive your ADN.</p>
        <a href="{{ route('register.orientation') }}"
            class="inline-flex items-center gap-2 rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-medium px-6 py-2.5 text-sm transition-colors">
            Continue Registration →
        </a>
    </div>
@endif

{{-- Phase 1 notice --}}
<div class="mt-10 rounded-xl border border-gray-200 bg-white/50 p-4 text-xs text-gray-700">
    <strong class="text-gray-800">Phase 1 Platform</strong> —
    Product catalogue, orders, commissions and wallet features are coming in later phases.
    For support, email support@arovolife.com.
</div>

@endsection
