@extends('layouts.app')
@section('title', 'Cancel registration')

@section('content')

<div class="max-w-xl mx-auto py-10">
    <h1 class="text-2xl font-bold mb-2">Cancel your arovolife registration</h1>
    <p class="text-sm text-gray-600 mb-8">
        You may cancel your distributor registration at any time during your 30-day cooling-off
        window, in line with the Direct Seller Agreement and the Consumer Protection (Direct Selling)
        Rules, 2021.
    </p>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 mb-8">
        <p class="text-xs text-gray-500 uppercase tracking-wider mb-2">Your registration</p>
        <p class="font-mono font-bold text-brand-600 tracking-widest text-lg mb-4">{{ $distributor->adn }}</p>

        <dl class="text-sm grid grid-cols-2 gap-y-2">
            <dt class="text-gray-600">Effective date</dt>
            <dd class="text-gray-900 font-medium">{{ $distributor->effective_date->format('d M Y') }}</dd>

            <dt class="text-gray-600">Cooling-off ends</dt>
            <dd class="text-gray-900 font-medium">{{ $distributor->cooling_off_end_at->format('d M Y H:i') }}</dd>
        </dl>
    </div>

    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
        @foreach($errors->all() as $error)
        <p>{{ $error }}</p>
        @endforeach
    </div>
    @endif

    @if($isWithinWindow)
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 mb-6 text-sm text-amber-900">
        <p class="font-semibold mb-2">What happens when you cancel</p>
        <ul class="list-disc pl-5 space-y-1">
            <li>Your distributor account is closed and you will be signed out.</li>
            <li>You can no longer log in or use any distributor features.</li>
            <li>We send a written confirmation by email.</li>
            <li>This action cannot be undone.</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('cooling-off.cancel') }}">
        @csrf
        <input type="hidden" name="confirm" value="yes">
        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium px-6 py-3 text-sm transition-colors">
            Confirm cancellation
        </button>
    </form>

    <a href="{{ route('dashboard') }}" class="block text-center text-sm text-gray-500 hover:text-gray-700 mt-4">
        Back to dashboard
    </a>
    @else
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 text-sm text-gray-700">
        <p class="font-semibold mb-2">Your cooling-off window has ended.</p>
        <p>
            The 30-day window expired on {{ $distributor->cooling_off_end_at->format('d M Y') }}.
            For account closure outside this window, please contact
            <a class="text-brand-600 underline" href="mailto:support@arovolife.com">support@arovolife.com</a>.
        </p>
    </div>

    <a href="{{ route('dashboard') }}" class="block text-center text-sm text-gray-500 hover:text-gray-700 mt-4">
        Back to dashboard
    </a>
    @endif
</div>

@endsection
