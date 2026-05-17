@extends('layouts.app')
@section('title', 'Registration in Progress — arovolife')

@section('content')
<div class="max-w-md mx-auto">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">You have a registration in progress</h1>
    <p class="text-sm text-gray-600 mb-6">
        You clicked a referral link from a different sponsor than the one you started
        registering with. Please choose how you'd like to continue.
    </p>

    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-6 text-sm text-amber-800">
        <p class="font-semibold mb-1">Conflict detected</p>
        <p>Your current registration is with sponsor <span class="font-mono font-bold">{{ $existingAdn }}</span>.</p>
        <p class="mt-1">The new link is from sponsor <span class="font-mono font-bold">{{ $newAdn }}</span>.</p>
    </div>

    <div class="space-y-4">
        {{-- Option 1: Resume existing draft --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="text-base font-semibold text-gray-800 mb-1">Continue existing registration</h2>
            <p class="text-sm text-gray-500 mb-4">
                Pick up where you left off with sponsor <span class="font-mono font-medium text-gray-700">{{ $existingAdn }}</span>.
            </p>
            <a href="{{ $resumeRoute }}"
               class="block w-full text-center rounded-full bg-brand-500 hover:bg-brand-600 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 shadow-lg shadow-brand-500/30">
                Continue with {{ $existingAdn }}
            </a>
        </div>

        {{-- Option 2: Discard and start fresh --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="text-base font-semibold text-gray-800 mb-1">Start fresh with {{ $newAdn }}</h2>
            <p class="text-sm text-gray-500 mb-4">
                Discard your current draft and begin a new registration under sponsor
                <span class="font-mono font-medium text-gray-700">{{ $newAdn }}</span>.
                This cannot be undone.
            </p>
            <form method="POST" action="{{ route('register.draft.discard') }}">
                @csrf
                <button type="submit"
                    class="w-full rounded-full border border-red-300 bg-red-50 hover:bg-red-100 text-red-700 font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-red-400">
                    Discard draft and start fresh with {{ $newAdn }}
                </button>
            </form>
        </div>
    </div>

    <p class="mt-6 text-center text-xs text-gray-500">
        Not sure which to pick?
        <a href="{{ route('contact.show') }}" class="text-brand-700 hover:text-brand-800 font-medium">Contact us</a>
        and we'll help.
    </p>
</div>
@endsection
