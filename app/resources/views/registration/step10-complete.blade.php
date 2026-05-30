@extends('layouts.wizard')
@section('title', 'Step 10 — Confirm & Complete')
@php $currentStep = 10; @endphp

@section('content')
<div class="max-w-xl mx-auto">
    <h2 class="text-2xl font-bold mb-2">Review & Finalise</h2>
    <p class="text-gray-600 text-sm mb-8">
        Please review your details. Once confirmed, your arovolife Distributor Number (ADN) will be issued.
    </p>

    <div class="bg-white rounded-2xl border border-gray-200 p-8 mb-6 space-y-4">
        @if($personal)
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-gray-500 text-xs uppercase tracking-wider mb-0.5">Date of Birth</p>
                <p class="text-gray-800">{{ $personal['date_of_birth'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-gray-500 text-xs uppercase tracking-wider mb-0.5">State</p>
                <p class="text-gray-800">{{ $personal['state'] ?? '—' }}</p>
            </div>
        </div>
        @endif

        @if($pan)
        <div class="text-sm">
            <p class="text-gray-500 text-xs uppercase tracking-wider mb-0.5">PAN (last 4)</p>
            <p class="text-gray-800">XXXXXX{{ substr($pan['pan_number'] ?? '????', -4) }}</p>
        </div>
        @endif

        @if($isCouple ?? false)
        <div class="rounded-lg bg-amber-50 border border-amber-200 p-4">
            <p class="text-xs font-medium text-amber-800">Couple registration</p>
            <p class="text-xs text-amber-800 mt-1">
                You and <strong>{{ $spouse['spouse_full_name'] ?? 'your spouse' }}</strong> will both be registered as
                co-distributors. We'll email <strong>{{ $spouse['spouse_email'] ?? '' }}</strong> a separate activation
                link so they can set their own password and view their account.
            </p>
        </div>
        @endif

        <div class="rounded-lg bg-brand-50 border border-brand-500/50 p-4">
            <p class="text-xs text-brand-500 font-medium">30-Day Cooling-Off Period</p>
            <p class="text-xs text-brand-600 mt-1">
                After registration you have <strong>30 days</strong> to cancel with no penalty. Registration is free of
                charge — there is nothing to refund at registration; once paid memberships exist, any fees paid
                during the window will be returned in full. One-click cancellation is available from your dashboard.
            </p>
        </div>

        <div class="rounded-lg bg-leaf-50 border border-leaf-200 p-4">
            <p class="text-xs font-semibold text-leaf-700 mb-1">One last check before we issue your ADN</p>
            <p class="text-xs text-leaf-700/90">
                When you click <strong>Confirm &amp; Issue My ADN</strong> below we'll show you a full preview of
                everything you've entered — account, KYC, bank, placement and uploaded documents. Nothing is saved
                until you confirm again on that preview screen.
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
            <button type="button" id="open-finalise-preview"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-bold py-4 text-base transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Confirm &amp; Issue My ADN →
            </button>
        </div>
    </form>

    <p class="mt-4 text-center text-xs text-gray-400">
        By confirming you agree to all previously accepted terms. Registration is free of charge.
    </p>
</div>

{{-- ── Final preview modal ──────────────────────────────────────────────────
     Shown when the distributor clicks "Confirm & Issue My ADN" — surfaces
     every field they've entered (account, personal, PAN/Aadhaar last-4,
     bank, placement, uploaded docs) so they can catch typos BEFORE the
     ADN is minted. Confirming inside the modal submits the wrapping form. --}}
<style>
    dialog#finalise-preview-modal::backdrop { background: rgba(15, 23, 42, 0.6); }
    dialog#finalise-preview-modal {
        padding: 0;
        border: 0;
        background: transparent;
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: 100%;
        margin: 0;
        inset: 0;
    }
    dialog#finalise-preview-modal[open] {
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>
<dialog id="finalise-preview-modal" class="m-auto">
    <div class="bg-white rounded-2xl w-[calc(100vw-2rem)] sm:w-full max-w-2xl flex flex-col shadow-2xl overflow-hidden" style="max-height: calc(100vh - 4rem);">
        <div class="flex items-start justify-between gap-4 px-6 py-4 border-b border-gray-200 shrink-0 bg-white">
            <div>
                <p class="text-base font-bold text-gray-900">Preview your details</p>
                <p class="text-xs text-gray-700 mt-0.5">
                    This is what will be saved against your ADN. PAN, Aadhaar and bank account are masked
                    for security — only the last 4 digits are shown.
                </p>
            </div>
            <button type="button" id="finalise-preview-close"
                class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-600"
                aria-label="Close preview">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto px-6 py-4 space-y-5 text-sm bg-white">
            @php
                $rowClass = 'grid grid-cols-[140px_1fr] gap-3 py-1 text-xs';
                $sectionClass = 'rounded-lg border border-gray-200 bg-gray-50/60 p-4';
                $sectionHeadClass = 'text-[11px] uppercase tracking-wider font-semibold text-gray-700 mb-2';
            @endphp

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
                    Spouse: <strong>{{ $spouse['spouse_full_name'] ?? '—' }}</strong>
                    @if(!empty($spouse['spouse_email'])) ({{ $spouse['spouse_email'] }}) @endif
                </p>
            </div>
            @endif

            <div class="rounded-lg border border-brand-200 bg-brand-50 p-4">
                <p class="text-xs text-brand-700">
                    Spot a mistake? Use <strong>Go back &amp; edit</strong> below to return to the relevant step.
                    Once you confirm we'll issue your ADN immediately — corrections after that need an admin
                    request.
                </p>
            </div>
        </div>

        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between gap-3">
            <button type="button" id="finalise-preview-cancel"
                class="px-4 py-2.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Go back &amp; edit
            </button>
            <button type="button" id="finalise-preview-confirm"
                class="flex-1 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-bold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Yes, looks good — issue my ADN →
            </button>
        </div>
    </div>
</dialog>

<script>
(function () {
    const modal      = document.getElementById('finalise-preview-modal');
    const openBtn    = document.getElementById('open-finalise-preview');
    const closeBtn   = document.getElementById('finalise-preview-close');
    const cancelBtn  = document.getElementById('finalise-preview-cancel');
    const confirmBtn = document.getElementById('finalise-preview-confirm');
    const form       = document.getElementById('finalise-form');

    if (!modal || !openBtn || !form) return;

    openBtn.addEventListener('click', () => modal.showModal());
    closeBtn?.addEventListener('click', () => modal.close());
    cancelBtn?.addEventListener('click', () => modal.close());
    // Click outside the inner card (i.e. directly on the dialog element)
    // dismisses; Escape key is handled natively by <dialog>.
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });
    confirmBtn?.addEventListener('click', () => {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Submitting…';
        form.submit();
    });
})();
</script>
@endsection
