@extends('admin.layouts.admin')
@section('title', 'BV Ledger')
@section('heading', 'BV Ledger')

@section('content')

@php
    // Preserve the active tab + date range when toggling tabs / paginating.
    $baseQuery = array_filter(['from' => $from, 'to' => $to, 'q' => $q], fn ($v) => $v !== null && $v !== '');
@endphp

{{-- ── Headline cards (date-scoped) ─────────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Net BV</p>
        <p class="mt-1 text-lg font-bold text-brand-700 whitespace-nowrap">@bv($cards['net'])</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Accrued</p>
        <p class="mt-1 text-lg font-bold text-green-700 whitespace-nowrap">@bv($cards['accrued'])</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Reversed</p>
        <p class="mt-1 text-lg font-bold text-red-600 whitespace-nowrap">@bv(abs($cards['reversed']))</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Distributors</p>
        <p class="mt-1 text-lg font-bold text-gray-900 whitespace-nowrap">{{ number_format($cards['distributors']) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Entries</p>
        <p class="mt-1 text-lg font-bold text-gray-900 whitespace-nowrap">{{ number_format($cards['entries']) }}</p>
    </div>
</div>

{{-- ── Tabs + date filter + export ──────────────────────────────────── --}}
<div class="flex items-center gap-3 mb-4 flex-wrap">
    <div class="flex items-center gap-2">
        @foreach(['summary' => 'Summary', 'entries' => 'All entries'] as $key => $lbl)
        <a href="{{ route('admin.commerce.bv-ledger.index', array_merge($baseQuery, ['tab' => $key])) }}"
           class="px-3 py-1 rounded-full text-xs font-medium border {{ $tab === $key ? 'bg-brand-500 text-white border-brand-500' : 'bg-white text-gray-700 border-gray-200 hover:border-brand-500' }}">
            {{ $lbl }}
        </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.commerce.bv-ledger.index') }}" class="flex items-center gap-2 ml-auto flex-wrap">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if($tab === 'summary' && $q)<input type="hidden" name="q" value="{{ $q }}">@endif
        <label class="text-xs text-gray-500">From
            <input type="date" name="from" value="{{ $from }}" class="ml-1 rounded-lg border border-gray-300 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
        </label>
        <label class="text-xs text-gray-500">To
            <input type="date" name="to" value="{{ $to }}" class="ml-1 rounded-lg border border-gray-300 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
        </label>
        <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">Apply</button>
        @if($from || $to)
        <a href="{{ route('admin.commerce.bv-ledger.index', array_filter(['tab' => $tab, 'q' => $q])) }}" class="text-xs text-gray-600 hover:text-gray-900">✕ Clear dates</a>
        @endif
    </form>

    <a href="{{ route('admin.commerce.bv-ledger.export', array_merge($baseQuery, ['tab' => $tab])) }}"
       class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-medium transition-colors">
        ⬇ Export CSV
    </a>
</div>

@if($tab === 'summary')
{{-- Per-distributor search --}}
<form method="GET" action="{{ route('admin.commerce.bv-ledger.index') }}" class="mb-4 flex gap-3">
    <input type="hidden" name="tab" value="summary">
    @if($from)<input type="hidden" name="from" value="{{ $from }}">@endif
    @if($to)<input type="hidden" name="to" value="{{ $to }}">@endif
    <input name="q" type="text" value="{{ $q }}" placeholder="Search ADN or name…"
        class="flex-1 max-w-sm rounded-lg bg-white border border-gray-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">Search</button>
    @if($q)<a href="{{ route('admin.commerce.bv-ledger.index', array_filter(['tab' => 'summary', 'from' => $from, 'to' => $to])) }}" class="self-center text-xs text-gray-600 hover:text-gray-900">✕ Clear</a>@endif
</form>

<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider w-12">S.No</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">ADN</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Distributor</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Accrued</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Reversed</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Net BV</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Orders</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Last activity</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($summary as $row)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500">{{ $loop->iteration }}</td>
                    <td class="px-4 py-3 font-mono font-medium">
                        <a href="{{ route('admin.commerce.bv-ledger.show', $row->distributor_id) }}" class="text-brand-600 hover:text-brand-700 hover:underline">{{ $row->adn }}</a>
                    </td>
                    <td class="px-4 py-3 text-gray-700">{{ $row->full_name ?: '—' }}</td>
                    <td class="px-4 py-3 text-right text-green-700 whitespace-nowrap">@bv($row->accrued)</td>
                    <td class="px-4 py-3 text-right text-red-600 whitespace-nowrap">{{ ((int) $row->reversed) !== 0 ? '' : '—' }}@if((int) $row->reversed !== 0)@bv(abs($row->reversed))@endif</td>
                    <td class="px-4 py-3 text-right font-semibold text-brand-700 whitespace-nowrap">@bv($row->net)</td>
                    <td class="px-4 py-3 text-right text-gray-700">{{ $row->orders }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $row->last_at ? \Illuminate\Support\Carbon::parse($row->last_at)->format('d M Y H:i') : '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.commerce.bv-ledger.show', $row->distributor_id) }}" class="text-xs text-brand-600 hover:text-brand-700">View →</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No BV accumulated yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($summary->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">{{ $summary->links() }}</div>
    @endif
</div>

@else
{{-- ── All entries (raw chronological feed) ─────────────────────────── --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider w-12">S.No</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">When</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Distributor</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Order #</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">BV</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($entries as $e)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500">{{ $loop->iteration }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $e->effective_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.commerce.bv-ledger.show', $e->distributor_id) }}" class="font-mono text-brand-600 hover:text-brand-700 hover:underline">{{ $e->distributor?->adn ?? '#'.$e->distributor_id }}</a>
                        <span class="block text-xs text-gray-500">{{ $e->distributor?->user?->full_name }}</span>
                    </td>
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
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No BV entries in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($entries->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">{{ $entries->links() }}</div>
    @endif
</div>
@endif

@endsection
