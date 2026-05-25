@extends('layouts.app')
@section('title', 'Request line-change')

@section('content')

<div class="max-w-xl mx-auto py-10">
    <h1 class="text-2xl font-bold mb-2">Request a line-change</h1>

    {{-- Form-purpose note (platform convention). --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-4 text-sm text-blue-900">
        <p class="font-semibold mb-1">What this form does</p>
        <p class="leading-relaxed">
            This requests a move of your position in the binary tree to sit under a
            different placement parent. It changes your <strong>binary placement only</strong> —
            your sponsor stays the same. An admin must approve the request before anything moves.
        </p>
    </div>

    <p class="text-sm text-gray-600 mb-6">
        Within five working days of registration, and only if you have not yet introduced
        anyone to arovolife, you may request this change. Direct Seller Agreement §10.
        You may use a line change <strong>once</strong>.
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

    @if(session('status'))
    <div class="rounded-xl border border-green-200 bg-green-50 p-4 mb-6 text-sm text-green-800">
        {{ session('status') }}
    </div>
    @endif

    @if($alreadyUsed)
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 text-sm text-gray-700">
        <p class="font-semibold mb-1">You've already used your one line change</p>
        <p>Each distributor may change their placement once. For anything further, contact
            <a class="text-brand-600 underline" href="mailto:support@arovolife.com">support@arovolife.com</a>.</p>
    </div>
    @elseif($hasDownline)
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 text-sm text-gray-700">
        <p class="font-semibold mb-1">Line-change is no longer available</p>
        <p>You already have referrals placed in your tree, so your placement can't be moved. For help, contact
            <a class="text-brand-600 underline" href="mailto:support@arovolife.com">support@arovolife.com</a>.</p>
    </div>
    @elseif($existing && $existing->status === 'pending')
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
        <p class="font-semibold mb-1">Pending request</p>
        <p>You submitted a line-change request on
            {{ $existing->requested_at->format('d M Y H:i') }}. An admin will review it shortly.</p>
    </div>
    @elseif($existing && $existing->status === 'rejected')
    <div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-800 mb-6">
        <p class="font-semibold mb-1">Your last request was not approved</p>
        @if($existing->decision_note)<p class="mb-2">Reason: {{ $existing->decision_note }}</p>@endif
        <p>If you are still within the window, you may submit a new request below.</p>
    </div>
    @endif

    @if(! $alreadyUsed && ! $hasDownline && (! $existing || $existing->status !== 'pending') && $isWithinWindow)
    <form method="POST" action="{{ route('line-change.submit') }}" class="space-y-5"
        data-confirm="Submit this line-change request for admin review?"
        data-confirm-title="Confirm line-change request"
        data-confirm-impact="This changes your binary placement only — your sponsor stays the same. The change happens only after an admin approves it.">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                New placement parent ADN
                <x-help-tip text="The 9-digit ADN of the distributor you want to be placed under in the binary tree. They must have joined before you and have a free leg. Your sponsor does not change." />
            </label>
            <input type="text" name="to_parent_adn" value="{{ old('to_parent_adn') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono tracking-widest focus:border-brand-500 focus:ring-brand-500"
                placeholder="111222333" required>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Reason (optional)
                <x-help-tip text="Briefly tell the admin why you want this placement change. Shown to the reviewer; max 512 characters." />
            </label>
            <textarea name="reason" rows="3" maxlength="512"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                placeholder="Briefly tell us why you want this change.">{{ old('reason') }}</textarea>
        </div>

        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-3 text-sm transition-colors">
            Submit request
        </button>
    </form>
    @elseif(! $alreadyUsed && ! $hasDownline && (! $existing || $existing->status !== 'pending'))
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
