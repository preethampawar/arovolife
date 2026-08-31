@extends('layouts.app')
@section('title', 'My Arete Development Centre')

@section('content')

<div>
    <h1 class="text-2xl font-bold mb-6">My Arete Development Centre</h1>

    @if(session('success'))
    <div class="rounded-xl border border-green-200 bg-green-50 p-4 mb-6 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    @if($ownedCenters->isNotEmpty())
    <div class="rounded-2xl border border-green-200 bg-green-50 p-6 mb-6 text-sm text-green-900">
        <p class="font-semibold mb-3">{{ $ownedCenters->count() === 1 ? 'You run an Arete Development Centre.' : 'You run these Arete Development Centres.' }}</p>
        <ul class="space-y-2">
            @foreach($ownedCenters as $centre)
            <li class="flex items-start justify-between gap-3">
                <div>
                    <p class="font-semibold">{{ $centre->name }}</p>
                    <p class="text-xs text-green-800">{{ $centre->displayLocation() }} · Phase {{ $centre->development_phase }}</p>
                </div>
                <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $centre->isActive() ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ ucfirst($centre->status) }}</span>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    @php $statusKey = $application?->status; @endphp

    @if(! $application)
    <div class="rounded-2xl border border-gray-200 bg-white p-6 text-sm text-gray-700">
        <p class="font-semibold mb-2 text-gray-900">You have not applied to open an Arete Development Centre.</p>
        <p class="mb-4 text-gray-600">
            An Arete Development Centre is a training, product-demonstration and support centre for arovolife
            distributors in your area. Applying is free and does not guarantee approval.
        </p>
        <a href="{{ route('my.adc.apply') }}" class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-5 py-2.5 text-sm transition-colors">Apply now</a>
    </div>

    @elseif(in_array($statusKey, ['submitted', 'under_review'], true))
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
        <p class="font-semibold mb-2">Your application is {{ $statusKey === 'under_review' ? 'under review' : 'awaiting review' }}.</p>
        <p class="mb-3">We received your application for <strong>{{ $application->centre_name }}</strong> on
            <strong>{{ $application->submitted_at?->timezone('Asia/Kolkata')->format('d M Y') ?? '—' }}</strong>. We will email you the outcome.</p>
        <dl class="text-xs grid grid-cols-2 gap-y-1.5 text-amber-800">
            <dt class="text-amber-700">Location</dt><dd>{{ $application->displayLocation() }} — {{ $application->pincode }}</dd>
            <dt class="text-amber-700">Documents</dt><dd>{{ $application->documents->count() }} uploaded</dd>
        </dl>
    </div>

    @elseif($statusKey === 'needs_changes')
    <div class="rounded-2xl border border-orange-200 bg-orange-50 p-6 text-sm text-orange-900">
        <p class="font-semibold mb-2">Your application needs changes.</p>
        <p class="mb-3">arovolife reviewed your application for <strong>{{ $application->centre_name }}</strong> and asked for the following:</p>
        <p class="mb-4 rounded-lg bg-white/70 p-3 text-orange-900">{{ $application->admin_notes }}</p>
        <a href="{{ route('my.adc.edit') }}" class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-5 py-2.5 text-sm transition-colors">Update and resubmit</a>
    </div>

    @elseif($statusKey === 'approved')
    <div class="rounded-2xl border border-green-200 bg-green-50 p-6 text-sm text-green-900">
        <p class="font-semibold mb-2">Your application was approved.</p>
        <p>The centre <strong>{{ $application->center?->name ?? $application->centre_name }}</strong> is active in the arovolife registry
            {{ $application->reviewed_at ? 'since '.$application->reviewed_at->timezone('Asia/Kolkata')->format('d M Y') : '' }}.</p>
    </div>

    @elseif($statusKey === 'rejected')
    <div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-800">
        <p class="font-semibold mb-2">Your application was not approved.</p>
        @if($application->admin_notes)<p class="mb-3">Reason: {{ $application->admin_notes }}</p>@endif
        <p class="text-red-700 text-xs mb-4">You may apply again later if your circumstances change.</p>
        <a href="{{ route('my.adc.apply') }}" class="inline-flex items-center rounded-lg border border-red-300 text-red-800 font-medium px-4 py-2 text-sm hover:bg-red-100 transition-colors">Apply again</a>
    </div>
    @endif
</div>

@endsection
