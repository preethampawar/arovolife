@extends('layouts.app')
@section('title', 'Withdraw consent')

@section('content')
<div>

    <h1 class="text-2xl font-bold text-gray-900 mb-2">Withdraw consent</h1>
    <p class="text-sm text-gray-600 mb-6">
        You gave consent when you registered. You can take it back at any time — that is your right under
        §6 of the Digital Personal Data Protection Act, 2023, and §10.5 of our Privacy Policy.
    </p>

    @if (! $hasLiveConsent)
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <p class="text-sm text-gray-700">
                Your consent has already been withdrawn. There is nothing further to do here.
            </p>
        </div>
    @else
        {{-- The consequence, stated before the form and not after it. Every
             consent held here is foundational to operating an ADN, so this is
             not a preferences screen — it closes the account. Someone who
             learns that afterwards has been misled by the design. --}}
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-6 mb-6">
            <h2 class="text-sm font-bold text-amber-900 mb-2">Read this first — it closes your ADN</h2>
            <ul class="list-disc pl-5 space-y-1.5 text-sm text-amber-900 leading-relaxed">
                <li>
                    Your distributorship ends. We cannot operate an ADN without consent to process the
                    KYC and payment data the law requires us to hold for a Direct Seller.
                </li>
                <li>
                    You stop earning. Bonuses already credited to your wallet remain payable; nothing
                    accrues after today.
                </li>
                <li>
                    <strong>Withdrawal is not deletion.</strong> Records we are required by law to keep —
                    tax records, invoices, grievance files — are retained for the periods set out in
                    <a href="{{ url('/p/privacy') }}" class="underline">the Privacy Policy</a>. Everything
                    else is deleted on the schedule stated there.
                </li>
                <li>
                    It does not undo anything that already happened. Processing carried out while consent
                    was in force stays lawful.
                </li>
                <li>
                    Re-registering later means starting again: a new ADN, a new position, no downline.
                </li>
            </ul>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white p-6 mb-6">
            <h2 class="text-sm font-semibold text-gray-800 mb-3">What you consented to</h2>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($consents as $consent)
                    <li class="flex items-center justify-between py-2">
                        <span class="text-gray-700">{{ ucfirst($consent->document_type) }}</span>
                        <span class="text-gray-600 text-xs">
                            version {{ $consent->document_version }},
                            accepted {{ $consent->accepted_at?->format('d M Y') }}
                            @if ($consent->withdrawn_at)
                                — <span class="text-red-700">withdrawn {{ $consent->withdrawn_at->format('d M Y') }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>

        <form method="POST" action="{{ route('consent.withdraw.store') }}"
              class="rounded-xl border border-gray-200 bg-white p-6"
              data-confirm="Withdraw consent and close your ADN?"
              data-confirm-title="This cannot be undone"
              data-confirm-impact="Your distributorship ends today. Bonuses already credited stay payable; nothing accrues after this. Re-registering later means a new ADN with no downline.">
            @csrf

            <label for="reason" class="block text-sm font-medium text-gray-700 mb-1">
                Why are you withdrawing? <span class="text-gray-600 font-normal">(optional)</span>
            </label>
            <textarea id="reason" name="reason" rows="3" maxlength="255"
                      class="w-full rounded-lg border-gray-300 text-sm mb-4"
                      placeholder="This helps us understand what went wrong. It does not affect your request."></textarea>
            @error('reason')<p class="mb-3 text-xs text-red-700">{{ $message }}</p>@enderror

            <label for="confirmation" class="block text-sm font-medium text-gray-700 mb-1">
                Type <strong>WITHDRAW</strong> to confirm <span class="text-red-700">*</span>
            </label>
            <input id="confirmation" name="confirmation" type="text" required autocomplete="off"
                   class="w-full rounded-lg border-gray-300 text-sm mb-1 font-mono">
            <p class="text-xs text-gray-600 mb-4">
                We ask you to type it rather than tick a box, because a box gets ticked without the
                paragraph above being read.
            </p>
            @error('confirmation')<p class="mb-3 text-xs text-red-700">{{ $message }}</p>@enderror

            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('profile.show') }}"
                   class="inline-flex items-center px-5 py-2.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">
                    ← Keep my account
                </a>
                <button type="submit"
                        class="rounded-lg bg-red-700 hover:bg-red-800 text-white font-semibold px-5 py-2.5 text-sm">
                    Withdraw consent and close my ADN
                </button>
            </div>
        </form>
    @endif

    <p class="mt-6 text-xs text-gray-600 leading-relaxed">
        If you want your data erased rather than your consent withdrawn, that is a separate right with
        different limits — see §10.4 of the Privacy Policy, or write to the Data Protection Officer at
        <a href="mailto:dpo@arovolife.com" class="underline">dpo@arovolife.com</a>.
    </p>
</div>
@endsection
