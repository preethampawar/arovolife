@extends('layouts.app')
@section('title', 'My Arete Development Centre')

@section('content')

<div>
    <h1 class="text-2xl font-bold mb-6">My Arete Development Centre</h1>

    @if(session('success'))
    <div class="rounded-xl border border-green-200 bg-green-50 p-4 mb-6 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    @error('declarations')
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- A centre cannot receive a parcel until its owner has accepted the
         declarations at the version in force, so this is where the owner clears
         that block. Shown above everything else because an unaccepted centre is
         not receiving orders. --}}
    @foreach($ownedCenters as $centre)
    @continue(! array_key_exists($centre->id, $outstandingDeclarations))
    <div class="rounded-2xl border border-amber-300 bg-amber-50 p-6 mb-6">
        <div class="flex items-start gap-3 mb-3">
            <x-lucide-file-signature class="w-5 h-5 text-amber-700 shrink-0 mt-0.5" />
            <div>
                <p class="font-semibold text-amber-900">Please accept the centre declarations for {{ $centre->name }}.</p>
                <p class="text-sm text-amber-900 mt-1">
                    These are the undertakings you give arovolife about how the centre is run. Until you accept
                    them, no order can be sent to this centre for a buyer to collect.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('my.adc.declarations.accept', $centre) }}" class="space-y-3"
              data-confirm="Accept these declarations for {{ $centre->name }}?"
              data-confirm-title="Accept centre declarations"
              data-confirm-impact="Your acceptance is recorded with the date, time and IP address, and is the basis on which orders may be sent to your centre for collection.">
            @csrf
            @foreach($declarationTexts as $key => $text)
            <label class="flex gap-3 items-start text-sm text-amber-900">
                <input type="checkbox" name="declarations[]" value="{{ $key }}" required class="mt-1 accent-brand-500">
                <span>{{ $text }}</span>
            </label>
            @endforeach
            <p class="text-xs text-amber-800">
                The period referred to above is <strong>{{ $maxDwellDays }} days</strong> from the day a parcel
                reaches your centre. Each declaration must be accepted. Your acceptance is recorded with the date,
                time and IP address against version {{ $declarationVersion }}.
            </p>
            <button type="submit"
                class="inline-flex items-center rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-medium px-5 py-2.5 text-sm transition-colors">
                Accept declarations
            </button>
        </form>
    </div>
    @endforeach

    @if($ownedCenters->isNotEmpty())
    <div class="rounded-2xl border border-green-200 bg-green-50 p-6 mb-6 text-sm text-green-900">
        <p class="font-semibold mb-3">{{ $ownedCenters->count() === 1 ? 'You run an Arete Development Centre.' : 'You run these Arete Development Centres.' }}</p>
        <ul class="space-y-2">
            @foreach($ownedCenters as $centre)
            <li class="flex items-start justify-between gap-3">
                <div>
                    <p class="font-semibold">{{ $centre->name }}</p>
                    <p class="text-xs text-green-800">{{ $centre->displayLocation() }} · Phase {{ $centre->development_phase }}</p>
                    <p class="text-xs text-green-800">An uncollected parcel may wait here {{ $maxDwellDays }} days before you return it.</p>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $centre->isActive() ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ ucfirst($centre->status) }}</span>
                    @if(array_key_exists($centre->id, $outstandingDeclarations))
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-800">Declarations pending</span>
                    @endif
                </div>
            </li>
            @endforeach
        </ul>
        @if($collectionEnabled)
        <a href="{{ route('my.adc.consignments') }}" class="mt-4 inline-flex items-center rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-medium px-4 py-2 text-sm transition-colors">Parcels for my centre</a>
        @endif
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
        <a href="{{ route('my.adc.apply') }}" class="inline-flex items-center rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-medium px-5 py-2.5 text-sm transition-colors">Apply now</a>
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
        <a href="{{ route('my.adc.edit') }}" class="inline-flex items-center rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-medium px-5 py-2.5 text-sm transition-colors">Update and resubmit</a>
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

<x-confirm-modal />

@endsection
