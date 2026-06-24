@extends('admin.layouts.admin')
@section('title', 'Manual Controls')
@section('heading', 'Compensation — Manual Controls')

@section('content')

{{-- Warning banner --}}
<div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
    <strong>These controls affect real money and wallet balances.</strong>
    Every action is permanently audit-logged with your admin ID, a timestamp, the before/after state, and the reason you provide.
    There is no undo — use Reverse if a credit needs to be walked back.
    When in doubt, use <strong>Retry</strong> (which is safe and idempotent) before using <strong>Manual Credit</strong>.
</div>

{{-- Success flash --}}
@if(session('status'))
<div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 font-medium">
    {{ session('status') }}
</div>
@endif

{{-- Action selector grid --}}
<div class="grid grid-cols-3 gap-3 mb-6">
    @foreach([
        ['key' => 'retry',        'label' => 'Retry Daily Cut-off',       'desc' => 'Re-run 23:59 GSB calculation for one distributor + date. Idempotent if already credited.', 'danger' => false],
        ['key' => 'recalc-cf',   'label' => 'Recalculate Carry-forward', 'desc' => 'Recompute slab-1 weaker CF and power-side CF from full GSB history.', 'danger' => false],
        ['key' => 'credit',      'label' => 'Manual GSB Credit',         'desc' => 'Credit a custom amount to wallet. Requires amount + reason. Use only when Retry fails.', 'danger' => false],
        ['key' => 'reverse',     'label' => 'Reverse GSB Credit',        'desc' => 'Write a debit reversing a specific GSB credit. Affects next payout.', 'danger' => true],
        ['key' => 'force-payout','label' => 'Force Weekly Payout',       'desc' => 'Trigger payout for one distributor immediately. Only if automated batch failed.', 'danger' => false],
        ['key' => 'freeze',      'label' => 'Freeze / Unfreeze GSB',     'desc' => 'Block GSB credits without terminating account. GSB calculated but held.', 'danger' => true],
    ] as $card)
    <a href="{{ route('admin.compensation.manual-controls.index', array_filter(['adn' => $adn, 'action' => $card['key'], 'date' => $date ?? null])) }}"
       class="block rounded-xl border p-4 hover:border-brand-400 transition-colors
              {{ $action === $card['key']
                  ? 'border-brand-500 bg-brand-50'
                  : ($card['danger'] ? 'border-red-200 bg-white' : 'border-gray-200 bg-white') }}">
        <h4 class="text-sm font-semibold {{ $card['danger'] ? 'text-red-700' : 'text-gray-900' }} mb-1">{{ $card['label'] }}</h4>
        <p class="text-xs text-gray-500 leading-snug">{{ $card['desc'] }}</p>
    </a>
    @endforeach
</div>

{{-- Active form section --}}
@php
    $allowedActions = ['retry', 'recalc-cf', 'credit', 'reverse', 'force-payout', 'freeze'];
    $safeAction = in_array($action, $allowedActions, true) ? $action : null;
@endphp
@if($safeAction)
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">
    @include('admin.compensation.manual-controls._form-'.$safeAction)
</div>
@else
<div class="bg-gray-50 rounded-xl border border-gray-200 p-6 text-center text-sm text-gray-500 mb-6">
    Select an action above to get started.
</div>
@endif

{{-- Recent actions audit feed --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Recent manual actions</span>
    </div>
    @if($recentActions->isEmpty())
    <p class="px-5 py-6 text-sm text-gray-400 text-center">No manual actions recorded yet.</p>
    @else
    <div class="divide-y divide-gray-50">
        @foreach($recentActions as $log)
        @php
            $badgeColor = match(true) {
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
</div>

@endsection
