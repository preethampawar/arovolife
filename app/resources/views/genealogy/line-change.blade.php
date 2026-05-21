@extends('layouts.app')
@section('title', 'Request line-change')

@section('content')

<div class="max-w-xl mx-auto py-10">
    <h1 class="text-2xl font-bold mb-2">Request a line-change</h1>
    <p class="text-sm text-gray-600 mb-6">
        Within five working days of registration, you may request to be moved to a
        different sponsor — provided you have not yet introduced anyone to arovolife.
        This is in line with the Direct Seller Agreement §10.
    </p>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
        <dl class="text-sm grid grid-cols-2 gap-y-2">
            <dt class="text-gray-600">Your ADN</dt>
            <dd class="font-mono font-bold text-brand-600 tracking-widest">{{ $self->adn }}</dd>

            <dt class="text-gray-600">Effective date</dt>
            <dd class="text-gray-900 font-medium">{{ $self->effective_date->format('d M Y') }}</dd>

            <dt class="text-gray-600">Working days since registration</dt>
            <dd class="text-gray-900 font-medium">{{ $businessDaysSince }} of 5</dd>
        </dl>
    </div>

    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
        @foreach($errors->all() as $error)
        <p>{{ $error }}</p>
        @endforeach
    </div>
    @endif

    @if($existing && $existing->status === 'pending')
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
        <p class="font-semibold mb-1">Pending request</p>
        <p>
            You submitted a line-change request on
            {{ $existing->requested_at->format('d M Y H:i') }}. An admin will review it shortly.
        </p>
    </div>
    @elseif($isWithinWindow)
    <form method="POST" action="{{ route('line-change.submit') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">New sponsor ADN</label>
            <input type="text" name="to_sponsor_adn" value="{{ old('to_sponsor_adn') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono tracking-widest focus:border-brand-500 focus:ring-brand-500"
                placeholder="ARO123456" required>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Reason (optional)</label>
            <textarea name="reason" rows="3" maxlength="512"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                placeholder="Briefly tell us why you want this change.">{{ old('reason') }}</textarea>
        </div>

        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-3 text-sm transition-colors">
            Submit request
        </button>
    </form>
    @else
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 text-sm text-gray-700">
        <p class="font-semibold mb-2">The 5-working-day window has ended.</p>
        <p>For account changes outside this window, please contact
            <a class="text-brand-600 underline" href="mailto:support@arovolife.com">support@arovolife.com</a>.</p>
    </div>
    @endif

    <a href="{{ route('dashboard') }}" class="block text-center text-sm text-gray-500 hover:text-gray-700 mt-6">
        Back to dashboard
    </a>
</div>

@endsection
