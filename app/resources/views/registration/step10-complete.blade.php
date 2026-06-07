@extends('layouts.wizard')
@section('title', 'Step 10 — Confirm & Complete')
@php $currentStep = 10; @endphp

@section('content')
<div class="max-w-2xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Review & Finalise</h2>
    <p class="text-gray-600 text-sm mb-2">
        Please review everything below before we issue your arovolife Distributor Number (ADN). Nothing is saved
        until you confirm.
    </p>
    <p class="text-xs text-gray-500 mb-8">
        PAN, Aadhaar and bank account are masked for your security — only the last 4 digits are shown.
    </p>

    {{-- ── Full inline review ───────────────────────────────────────────────
         Every field the applicant entered (account, personal, PAN/Aadhaar
         last-4, bank, placement, uploaded docs), shown inline so they can
         catch typos BEFORE the ADN is minted. Previously a popup. --}}
    @php
        $rowClass = 'grid grid-cols-[140px_1fr] gap-3 py-1 text-xs';
        $sectionClass = 'rounded-lg border border-gray-200 bg-gray-50/60 p-4';
        $sectionHeadClass = 'text-[11px] uppercase tracking-wider font-semibold text-gray-700 mb-2';
    @endphp

    <div class="space-y-4 mb-6">
        {{-- Account --}}
        <div class="{{ $sectionClass }}">
            <p class="{{ $sectionHeadClass }}">Account</p>
            <div class="space-y-0.5">
                <div class="{{ $rowClass }}"><span class="text-gray-600">Full name</span><span class="text-gray-900 font-medium">{{ $account['full_name'] ?? '—' }}</span></div>
                <div class="{{ $rowClass }}"><span class="text-gray-600">Email</span><span class="text-gray-900">{{ $account['email'] ?? '—' }}</span></div>
                <div class="{{ $rowClass }}"><span class="text-gray-600">Phone</span><span class="text-gray-900 font-mono">{{ $account['phone_e164'] ?? '—' }}</span></div>
            </div>
        </div>

        {{-- Personal --}}
        <div class="{{ $sectionClass }}">
            <p class="{{ $sectionHeadClass }}">Personal</p>
            <div class="space-y-0.5">
                <div class="{{ $rowClass }}"><span class="text-gray-600">Date of birth</span><span class="text-gray-900">{{ $personal['date_of_birth'] ?? '—' }}</span></div>
                <div class="{{ $rowClass }}"><span class="text-gray-600">State</span><span class="text-gray-900">{{ $personal['state'] ?? '—' }}</span></div>
                @if(!empty($personal['address']))
                <div class="{{ $rowClass }}"><span class="text-gray-600">Address</span><span class="text-gray-900">{{ $personal['address'] }}</span></div>
                @endif
            </div>
        </div>

        {{-- KYC --}}
        <div class="{{ $sectionClass }}">
            <p class="{{ $sectionHeadClass }}">KYC</p>
            <div class="space-y-0.5">
                <div class="{{ $rowClass }}"><span class="text-gray-600">PAN</span><span class="text-gray-900 font-mono">XXXXXX{{ substr($pan['pan_number'] ?? '????', -4) }}</span></div>
                <div class="{{ $rowClass }}"><span class="text-gray-600">Aadhaar</span><span class="text-gray-900 font-mono">XXXX-XXXX-{{ $aadhaar['last4'] ?? '????' }}</span></div>
            </div>
        </div>

        {{-- Bank --}}
        <div class="{{ $sectionClass }}">
            <p class="{{ $sectionHeadClass }}">Bank</p>
            @if(!empty($bank['account_number']) || !empty($bank['ifsc']))
            <div class="space-y-0.5">
                <div class="{{ $rowClass }}"><span class="text-gray-600">Account no.</span><span class="text-gray-900 font-mono">XXXX{{ substr((string) ($bank['account_number'] ?? ''), -4) }}</span></div>
                <div class="{{ $rowClass }}"><span class="text-gray-600">IFSC</span><span class="text-gray-900 font-mono">{{ $bank['ifsc'] ?? '—' }}</span></div>
            </div>
            @else
            <p class="text-xs text-gray-700">Skipped — you can add bank details from your dashboard after registration.</p>
            @endif
        </div>

        {{-- Placement --}}
        <div class="{{ $sectionClass }}">
            <p class="{{ $sectionHeadClass }}">Sponsor &amp; placement</p>
            <div class="space-y-0.5">
                <div class="{{ $rowClass }}">
                    <span class="text-gray-600">Sponsor</span>
                    <span class="text-gray-900">
                        @if($sponsor_name){{ $sponsor_name }} @endif
                        @if($sponsor_adn)<span class="font-mono text-brand-700">({{ $sponsor_adn }})</span>@endif
                        @if(! $sponsor_name && ! $sponsor_adn) — @endif
                    </span>
                </div>
                <div class="{{ $rowClass }}">
                    <span class="text-gray-600">Placement ADN</span>
                    <span class="text-gray-900 font-mono text-brand-700">{{ $placement_adn ?? '—' }}</span>
                </div>
                @if($placement_side)
                <div class="{{ $rowClass }}">
                    <span class="text-gray-600">Side</span>
                    <span class="text-gray-900">{{ strtoupper($placement_side) === 'L' ? '← Left group' : '→ Right group' }}</span>
                </div>
                @endif
            </div>
        </div>

        {{-- Documents --}}
        @if(!empty($documents))
        <div class="{{ $sectionClass }}">
            <p class="{{ $sectionHeadClass }}">Uploaded documents</p>
            <ul class="text-xs text-gray-800 list-disc pl-5 space-y-0.5">
                @foreach($documents as $type => $meta)
                    <li>{{ ucwords(str_replace(['_', '-'], ' ', (string) $type)) }} — uploaded</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if($isCouple ?? false)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-[11px] uppercase tracking-wider font-semibold text-amber-800 mb-1">Couple registration</p>
            <p class="text-xs text-amber-800">
                You and <strong>{{ $spouse['spouse_full_name'] ?? 'your spouse' }}</strong>
                @if(!empty($spouse['spouse_email'])) ({{ $spouse['spouse_email'] }}) @endif
                will both be registered as co-distributors. We'll email your spouse a separate activation link so
                they can set their own password and view their account.
            </p>
        </div>
        @endif

        {{-- Cooling-off (statutory copy — keep verbatim) --}}
        <div class="rounded-lg bg-brand-50 border border-brand-500/50 p-4">
            <p class="text-xs text-brand-500 font-medium">30-Day Cooling-Off Period</p>
            <p class="text-xs text-brand-600 mt-1">
                After registration you have <strong>30 days</strong> to cancel with no penalty. Registration is free of
                charge — there is nothing to refund at registration; once paid memberships exist, any fees paid
                during the window will be returned in full. One-click cancellation is available from your dashboard.
            </p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs text-gray-600">
                Spot a mistake? Use <strong>Back</strong> below to return to the relevant step and correct it. Once
                you confirm we'll issue your ADN immediately — corrections after that need an admin request.
            </p>
        </div>
    </div>

    <form id="finalise-form" method="POST" action="{{ url('/register/complete') }}">
        @csrf
        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('register.documents') }}"
               class="inline-flex items-center px-5 py-4 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-base font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit" id="finalise-submit"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-bold py-4 text-base transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Confirm &amp; Issue My ADN →
            </button>
        </div>
    </form>

    <p class="mt-4 text-center text-xs text-gray-400">
        By confirming you agree to all previously accepted terms. Registration is free of charge.
    </p>
</div>

<script>
(function () {
    // Guard against a double-submit minting two ADNs on a slow connection.
    const form = document.getElementById('finalise-form');
    const btn  = document.getElementById('finalise-submit');
    if (!form || !btn) return;
    form.addEventListener('submit', () => {
        btn.disabled = true;
        btn.textContent = 'Submitting…';
    });
})();
</script>
@endsection
