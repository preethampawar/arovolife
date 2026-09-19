@extends('admin.layouts.admin')
@section('title', 'Manual Controls')
@section('heading', 'Compensation — Manual Controls')

@section('content')

{{-- Warning banner --}}
<div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
    <strong>These controls affect real money and wallet balances.</strong>
    Every action is permanently audit-logged with your admin ID, a timestamp, the before/after state, and the reason you provide.
    There is no undo. Reach for <strong>Retry</strong> first — safe and idempotent, but only until the Nightly Run
    runs past that date; after that it refuses. That run's own clock is below, so the deadline is a time, not a rule to apply.
    <strong>Reverse</strong> is a one-way door: it walks a credit back, and nothing on this page puts it back again —
    so it now takes two admins. One requests it, a different one approves it.
</div>

{{-- The deadline every control on this page is measured against, as instants. --}}
<x-compensation.run-clock :clock="$nightlyClock" class="mb-5" />

{{-- Success flash --}}
{{-- Action selector grid --}}
<div class="grid grid-cols-3 gap-3 mb-6">
    @foreach([
        ['key' => 'retry',        'label' => 'Retry Daily Cut-off',       'desc' => 'Re-run 23:59 GSB calculation for one distributor + date. Idempotent if already credited.', 'danger' => false],
        ['key' => 'reverse',     'label' => 'Request GSB Reversal',      'desc' => 'Ask a second admin to approve a debit reversing a specific GSB credit. Nothing moves until they do.', 'danger' => true],
        ['key' => 'freeze',      'label' => 'Freeze / Unfreeze GSB',     'desc' => 'Block GSB credits without terminating account. GSB calculated but held.', 'danger' => true],
    ] as $card)
    <a href="{{ route('admin.compensation.manual-controls.index', array_filter(['adn' => $adn, 'action' => $card['key'], 'date' => $date ?? null])) }}"
       class="block rounded-xl border p-4 hover:border-brand-400 transition-colors
              {{ $action === $card['key']
                  ? 'border-brand-500 bg-brand-50'
                  : ($card['danger'] ? 'border-red-200 bg-white' : 'border-gray-200 bg-white') }}">
        <h4 class="text-sm font-semibold {{ $card['danger'] ? 'text-red-700' : 'text-gray-900' }} mb-1">{{ $card['label'] }}</h4>
        <p class="text-xs text-gray-600 leading-snug">{{ $card['desc'] }}</p>
    </a>
    @endforeach
</div>

{{-- Active form section --}}
@php
    $allowedActions = ['retry', 'reverse', 'freeze'];
    $safeAction = in_array($action, $allowedActions, true) ? $action : null;
@endphp
@if($safeAction)
<x-ui.card padding="p-5" class="mb-6">
    @include('admin.compensation.manual-controls._form-'.$safeAction)
</x-ui.card>
@else
<div class="bg-gray-50 rounded-xl border border-gray-200 p-6 text-center text-sm text-gray-600 mb-6">
    Select an action above to get started.
</div>
@endif

{{-- Reversals waiting for a second pair of eyes (R-92) --}}
@if($pendingReversals->isNotEmpty())
<x-ui.card flush class="mb-6">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-2">
        <span class="text-sm font-semibold text-red-700">Reversals awaiting approval</span>
        <span class="inline-flex px-2 py-0.5 rounded bg-red-100 text-red-700 text-xs font-medium">{{ $pendingReversals->count() }}</span>
    </div>
    <div class="divide-y divide-gray-50">
        @foreach($pendingReversals as $pending)
        @php $isOwnRequest = $pending->requested_by === auth()->id(); @endphp
        <div class="px-5 py-4">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 mb-1">
                <strong class="text-sm text-gray-900">{{ $pending->distributor?->adn ?? '—' }}</strong>
                <span class="text-sm text-red-700 font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($pending->net_gsb_paise / 100, 2) }}</span>
                <span class="text-xs text-gray-500">cut-off {{ $pending->cutoff_date->format('d M Y') }}</span>
                <span class="text-xs text-gray-500">requested {{ $pending->created_at->format('d M H:i') }} by {{ $pending->requester?->email ?? 'unknown' }}</span>
            </div>
            <p class="text-xs text-gray-600 mb-3">"{{ $pending->reason }}"</p>

            @if($pending->repurchase_deduction_paise > 0)
            <p class="text-xs text-gray-500 mb-3">
                ₹{{ \App\Modules\Shared\Support\IndianNumber::format($pending->repurchase_deduction_paise / 100, 2) }}
                of this was withheld to the repurchase wallet and comes back out of it too. Anything the distributor has
                already spent there is not clawed back — that part is the company's loss on the reversal.
            </p>
            @endif

            @if($pending->gsb_cutoff_result_id === null)
            <p class="text-xs text-amber-900 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
                The credit this request names no longer exists &mdash; it was removed by a platform-team rebuild. It can
                no longer be approved. Reject it to close it.
            </p>
            @endif

            <div class="flex flex-wrap gap-2">
                @can('compensation.reversal.approve')
                @if($pending->gsb_cutoff_result_id !== null)
                    @if($isOwnRequest)
                    <span class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        You raised this one, so you cannot approve it. It needs a different admin.
                    </span>
                    @else
                    <form method="POST" action="{{ route('admin.compensation.manual-controls.reversals.approve', $pending->id) }}"
                          data-confirm="This debits ₹{{ \App\Modules\Shared\Support\IndianNumber::format($pending->net_gsb_paise / 100, 2) }} back out of {{ $pending->distributor?->adn }}'s wallet."
                          data-confirm-title="Confirm: Approve GSB Reversal"
                          data-confirm-impact="The credit is permanently settled as reversed. Nothing in the admin panel can restore it — not Retry, not a re-run. If the weekly payout batch has already swept this credit towards a bank, the platform refuses this approval rather than debiting a wallet for money that has gone — recovering a paid credit is a finance decision, not this button.">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs font-medium hover:bg-red-700">
                            Approve reversal
                        </button>
                    </form>
                    @endif
                    @endif
                @endcan
                @can('compliance.discipline')
                <form method="POST" action="{{ route('admin.compensation.manual-controls.reversals.reject', $pending->id) }}"
                      class="flex flex-wrap items-center gap-2"
                      data-confirm="This turns the reversal request down. The credit stands."
                      data-confirm-title="Confirm: Reject reversal request"
                      data-confirm-impact="No money moves. The request is closed and recorded with your note; a new request has to be raised if it is needed later.">
                    @csrf
                    <input type="text" name="decision_note" required minlength="10" maxlength="500"
                           placeholder="{{ $isOwnRequest ? 'Why you are withdrawing it (min 10 chars)' : 'Why you are rejecting it (min 10 chars)' }}"
                           class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs focus:ring-2 focus:ring-brand-400 focus:outline-none w-72 max-w-full">
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium hover:bg-gray-50">
                        {{ $isOwnRequest ? 'Withdraw' : 'Reject' }}
                    </button>
                </form>
                @endcan
            </div>
        </div>
        @endforeach
    </div>
</x-ui.card>
@endif

{{-- Recent actions audit feed --}}
<x-ui.card flush>
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Recent manual actions</span>
    </div>
    @if($recentActions->isEmpty())
    <x-ui.empty-state title="No manual actions recorded yet." />
    @else
    <div class="divide-y divide-gray-50">
        @foreach($recentActions as $log)
        @php
            $badgeColor = match(true) {
                // Before the retry arm: 'manual_retry_refused' contains 'retry'
                // and a refusal is not a success.
                str_contains($log->action, 'refused') => 'bg-amber-100 text-amber-700',
                // Before the 'reversed' arm only for readability — neither
                // 'reversal_requested' nor 'reversal_rejected' contains it.
                str_contains($log->action, 'reversal_requested') => 'bg-orange-100 text-orange-700',
                str_contains($log->action, 'reversal_rejected') => 'bg-gray-100 text-gray-700',
                str_contains($log->action, 'reversed') => 'bg-red-100 text-red-700',
                str_contains($log->action, 'frozen') || str_contains($log->action, 'unfrozen') => 'bg-blue-100 text-blue-700',
                str_contains($log->action, 'retry') || str_contains($log->action, 'recalc') => 'bg-green-100 text-green-700',
                default => 'bg-amber-100 text-amber-700',
            };
        @endphp
        <div class="px-5 py-3 text-xs text-gray-600 flex items-start gap-3">
            <span class="inline-flex px-2 py-0.5 rounded font-medium {{ $badgeColor }} shrink-0 whitespace-nowrap">{{ str_replace('compensation.', '', $log->action) }}</span>
            <span>
                <strong>{{ $log->details['adn'] ?? '—' }}</strong> ·
                {{ $log->created_at->format('d M H:i') }} ·
                by {{ $log->actor?->email ?? 'system' }}
                @if(isset($log->details['reason'])) · "{{ Str::limit($log->details['reason'], 60) }}" @endif
            </span>
        </div>
        @endforeach
    </div>
    @endif
</x-ui.card>

@endsection
