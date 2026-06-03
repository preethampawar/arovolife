@extends('admin.layouts.admin')
@section('title', 'BV Ledger — '.$distributor->adn)
@section('heading', 'BV Ledger')

@section('content')

@php
    $dateQuery = array_filter(['from' => $from, 'to' => $to], fn ($v) => $v !== null && $v !== '');
    $ranged = $from || $to;
@endphp

<div class="mb-6 flex items-center justify-between gap-3 flex-wrap">
    <a href="{{ route('admin.commerce.bv-ledger.index') }}" class="text-sm text-gray-700 hover:text-gray-900">← Back to BV Ledger</a>
    <a href="{{ route('admin.distributors.show', $distributor->id) }}"
       class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-brand-300 bg-white hover:bg-brand-50 text-brand-700 text-xs font-semibold transition-colors">
        View distributor →
    </a>
</div>

{{-- Distributor header --}}
<div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm mb-6">
    <p class="text-2xl font-mono font-bold text-brand-600">{{ $distributor->adn }}</p>
    <p class="text-sm text-gray-800 mt-1">{{ $distributor->user->full_name ?: 'No name recorded' }}</p>
</div>

{{-- Summary cards --}}
<div class="grid grid-cols-3 gap-3 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Lifetime Net BV</p>
        <p class="mt-1 text-lg font-bold text-brand-700 whitespace-nowrap">@bv($lifetimeNet)</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Accrued{{ $ranged ? ' (range)' : '' }}</p>
        <p class="mt-1 text-lg font-bold text-green-700 whitespace-nowrap">@bv($breakdown->accruedPaise)</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Reversed{{ $ranged ? ' (range)' : '' }}</p>
        <p class="mt-1 text-lg font-bold text-red-600 whitespace-nowrap">@bv(abs($breakdown->reversedPaise))</p>
    </div>
</div>

{{-- Date filter + export --}}
<div class="flex items-center gap-3 mb-4 flex-wrap">
    <form method="GET" action="{{ route('admin.commerce.bv-ledger.show', $distributor->id) }}" class="flex items-center gap-2 flex-wrap">
        <label class="text-xs text-gray-500">From
            <input type="date" name="from" value="{{ $from }}" class="ml-1 rounded-lg border border-gray-300 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
        </label>
        <label class="text-xs text-gray-500">To
            <input type="date" name="to" value="{{ $to }}" class="ml-1 rounded-lg border border-gray-300 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
        </label>
        <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">Apply</button>
        @if($ranged)<a href="{{ route('admin.commerce.bv-ledger.show', $distributor->id) }}" class="text-xs text-gray-600 hover:text-gray-900">✕ Clear</a>@endif
    </form>
    <a href="{{ route('admin.commerce.bv-ledger.show.export', array_merge($dateQuery, ['distributor' => $distributor->id])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-medium transition-colors">
        ⬇ Export CSV
    </a>
</div>

<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">When</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Order #</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">BV</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Running balance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @php $running = $openingBalance; @endphp
                @forelse($entries as $e)
                @php $running += $e->bv_paise; @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $e->effective_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 font-mono text-xs">
                        @if($e->order)
                        <a href="{{ route('admin.commerce.orders.show', $e->order) }}" class="text-brand-600 hover:text-brand-700 hover:underline">{{ $e->order->order_no }}</a>
                        @else <span class="text-gray-400">—</span> @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($e->type === \App\Modules\Commerce\Models\BvLedgerEntry::TYPE_ACCRUAL)
                        <span class="text-xs px-2 py-0.5 rounded-full border bg-green-50 text-green-700 border-green-200">Accrual</span>
                        @else
                        <span class="text-xs px-2 py-0.5 rounded-full border bg-red-50 text-red-700 border-red-200">Reversal</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right font-semibold whitespace-nowrap {{ $e->bv_paise < 0 ? 'text-red-600' : 'text-brand-700' }}">
                        {{ $e->bv_paise < 0 ? '−' : '' }}@bv(abs($e->bv_paise))
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-gray-900 whitespace-nowrap">@bv($running)</td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No BV entries for this distributor{{ $ranged ? ' in this range' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($entries->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">{{ $entries->links() }}</div>
    @endif
</div>

@endsection
