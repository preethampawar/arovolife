@extends('layouts.app')
@section('title', 'Franchise Application Status')

@section('content')

<div class="max-w-xl mx-auto py-10">
    <h1 class="text-2xl font-bold mb-6">Franchise Application</h1>

    @if(session('success'))
    <div class="rounded-xl border border-green-200 bg-green-50 p-4 mb-6 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @endif

    @if(! $franchise)
    {{-- No application submitted yet. --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-6 text-sm text-gray-700">
        <p class="font-semibold mb-2 text-gray-900">You have not yet applied for a franchise.</p>
        <p class="mb-4 text-gray-600">
            If you are interested in opening an arovolife franchise outlet, you can submit
            an application below. An admin will review it and contact you.
        </p>
        <a href="{{ route('franchise.apply') }}"
            class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-5 py-2.5 text-sm transition-colors">
            Apply now
        </a>
    </div>

    @elseif($franchise->status === 'pending_approval')
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
        <p class="font-semibold mb-2 text-amber-900">Your application is under review.</p>
        <p class="mb-3">
            We received your franchise application on
            <strong>{{ $franchise->applied_at?->format('d M Y') ?? '—' }}</strong>.
            Our team will review it and contact you shortly.
        </p>
        <dl class="text-xs grid grid-cols-2 gap-y-1.5 text-amber-800">
            <dt class="text-amber-700">Reference code</dt>
            <dd class="font-mono font-semibold">{{ $franchise->code }}</dd>
            <dt class="text-amber-700">Location</dt>
            <dd>{{ $franchise->district }}{{ $franchise->district && $franchise->state ? ', ' : '' }}{{ $franchise->state }}</dd>
        </dl>
    </div>

    @elseif($franchise->status === 'active')
    <div class="rounded-2xl border border-green-200 bg-green-50 p-6 text-sm text-green-900">
        <p class="font-semibold mb-2 text-green-900">Your franchise is active.</p>
        <dl class="text-xs grid grid-cols-2 gap-y-1.5 text-green-800">
            <dt class="text-green-700">Franchise code</dt>
            <dd class="font-mono font-semibold tracking-wider">{{ $franchise->code }}</dd>
            @if($franchise->activated_at)
            <dt class="text-green-700">Active since</dt>
            <dd>{{ $franchise->activated_at->format('d M Y') }}</dd>
            @endif
            <dt class="text-green-700">Location</dt>
            <dd>{{ $franchise->district }}{{ $franchise->district && $franchise->state ? ', ' : '' }}{{ $franchise->state }}</dd>
        </dl>
    </div>

    @elseif($franchise->status === 'rejected')
    <div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-800">
        <p class="font-semibold mb-2">Your application was not approved.</p>
        @if($franchise->admin_notes)
        <p class="mb-3">Reason: {{ $franchise->admin_notes }}</p>
        @endif
        <p class="text-red-700 text-xs">
            If you have questions, contact
            <a class="underline" href="mailto:support@arovolife.com">support@arovolife.com</a>.
        </p>
    </div>

    @elseif($franchise->status === 'suspended')
    <div class="rounded-2xl border border-gray-300 bg-gray-50 p-6 text-sm text-gray-700">
        <p class="font-semibold mb-2 text-gray-900">Your franchise has been suspended.</p>
        @if($franchise->admin_notes)
        <p class="mb-3">{{ $franchise->admin_notes }}</p>
        @endif
        <p class="text-xs text-gray-500">
            Contact
            <a class="underline text-brand-600" href="mailto:support@arovolife.com">support@arovolife.com</a>
            for assistance.
        </p>
    </div>

    @else
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 text-sm text-gray-700">
        <p class="font-semibold mb-2 text-gray-900">Application status: {{ ucwords(str_replace('_', ' ', $franchise->status)) }}</p>
        <p class="text-xs text-gray-500">
            Contact
            <a class="underline text-brand-600" href="mailto:support@arovolife.com">support@arovolife.com</a>
            for more information.
        </p>
    </div>
    @endif
</div>

@endsection
