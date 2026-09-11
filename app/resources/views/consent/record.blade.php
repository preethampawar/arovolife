@extends('layouts.app')
@section('title', 'My consents & agreements')

@section('content')
<div>

    <h1 class="text-2xl font-bold text-gray-900 mb-1">My consents &amp; agreements</h1>
    <p class="text-sm text-gray-600 mb-6 leading-relaxed">
        What you agreed to when you registered, which version of each document it was, and when. Under
        §6 of the Digital Personal Data Protection Act, 2023 you can withdraw this consent at any time.
    </p>

    @if ($consents->isEmpty())
        {{-- An empty list is not "you consented to nothing". Saying which it
             is matters: one of them would mean the platform is processing
             data it was never given permission for. --}}
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-6 mb-6">
            <p class="text-sm text-amber-900 leading-relaxed">
                We do not hold a dated acceptance record for your ADN. You accepted the agreement, the
                code of ethics, the plan and the privacy notice when you registered — that acceptance was
                not written to this record, and we are sorry. You can read all four below, and you can
                withdraw your consent in the ordinary way.
            </p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 mb-6">
            <h2 class="text-sm font-semibold text-gray-800 mb-3">The documents you agreed to</h2>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($titles as $type => $title)
                    <li class="py-2.5">
                        <a href="{{ url($urls[$type]) }}" class="text-brand-700 hover:text-brand-800 font-medium">
                            {{ $title }} →
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-600">
                        <tr>
                            <th class="text-left font-semibold px-4 py-3">Document</th>
                            <th class="text-left font-semibold px-4 py-3">Version</th>
                            <th class="text-left font-semibold px-4 py-3">Accepted</th>
                            <th class="text-left font-semibold px-4 py-3">From</th>
                            <th class="text-left font-semibold px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($consents as $consent)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ url($urls[$consent->document_type] ?? '/p/'.$consent->document_type) }}"
                                       class="text-brand-700 hover:text-brand-800 font-medium">
                                        {{ $titles[$consent->document_type] ?? ucfirst($consent->document_type) }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-gray-700 font-mono text-xs">{{ $consent->document_version }}</td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{ $consent->accepted_at?->format('d M Y, H:i') ?? '—' }}
                                </td>
                                {{-- The IP is part of the electronic record under
                                     IT Act §10A — it is what ties the acceptance
                                     to a session. Shown only to the person it
                                     belongs to. --}}
                                <td class="px-4 py-3 text-gray-600 font-mono text-xs">{{ $consent->ip ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($consent->withdrawn_at)
                                        <span class="text-red-700 text-xs font-semibold">
                                            Withdrawn {{ $consent->withdrawn_at->format('d M Y') }}
                                        </span>
                                    @else
                                        <span class="text-green-700 text-xs font-semibold">In force</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-600 mb-6 leading-relaxed">
            The version shown is the one you accepted. Documents are amended from time to time under §6.2
            of the Direct Seller Agreement, with notice; the links above open the version published today,
            which may differ from the one recorded here.
        </p>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <h2 class="font-semibold text-gray-800 mb-1">Withdrawing your consent</h2>
        <p class="text-sm text-gray-600 mb-4 leading-relaxed">
            It must be as easy to take back as it was to give. It also closes your ADN — we cannot operate
            one without consent to process the KYC and payment data the law requires us to hold for a
            Direct Seller. The next screen explains exactly what happens before you confirm anything.
        </p>
        <div class="flex flex-wrap items-center gap-4">
            <a href="{{ route('profile.show') }}"
               class="text-sm text-gray-700 hover:text-gray-900 font-medium">← Back to my profile</a>
            @if ($hasLiveConsent)
                <a href="{{ route('consent.withdraw') }}" class="text-sm text-red-700 hover:text-red-800 font-medium">
                    Withdraw consent →
                </a>
            @else
                <span class="text-sm text-gray-600">Your consent has already been withdrawn.</span>
            @endif
        </div>
    </div>

    <p class="mt-6 text-xs text-gray-600 leading-relaxed">
        Questions about your data, or a correction to make? Write to the Data Protection Officer at
        <a href="mailto:dpo@arovolife.com" class="underline">dpo@arovolife.com</a>.
    </p>
</div>
@endsection
