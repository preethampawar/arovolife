@extends('layouts.app')
@section('title', 'My Family Card')

@section('content')
<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 mb-1">My Family Card</h1>
            <p class="text-sm text-gray-600">Your arovolife Family Card. Download it as a PDF or print it. The card is not an insurance policy and gives no health cover or treatment.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('family-card.show') }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-sm font-semibold text-white transition-colors">
                <x-lucide-download class="w-4 h-4" />
                Download PDF / Print
            </a>
            <a href="{{ route('profile.show') }}#family-members"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium text-gray-700 transition-colors">
                <x-lucide-users class="w-4 h-4" />
                Manage family members
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 justify-items-center">
        <div class="w-full max-w-[540px]">
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold mb-2">Front</p>
            @include('membership._family-card-front', ['compact' => false])
        </div>
        <div class="w-full max-w-[540px]">
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold mb-2">Back</p>
            @include('membership._family-card-back')
        </div>
    </div>

    <p class="mt-6 text-xs text-gray-600">
        Download PDF / Print opens the card on its own page. Choose <span class="font-medium">Save as PDF</span> in your browser's print dialog to download it; the front prints on page 1 and the back on page 2.
    </p>
</div>
@endsection
