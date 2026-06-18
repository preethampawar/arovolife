@extends('layouts.app')
@section('title', 'My BV Ledger')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My BV Ledger</h1>
    <p class="text-sm text-gray-500 mb-6">Business Volume accrued and reversed on your account. BV accumulates from product sales only — personal purchases and attributed customer sales.</p>

    {{-- Summary cards --}}
    <div class="grid grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-2xl border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-500 mb-1">Accrued</p>
            <p class="text-xl font-bold text-green-700">{{ number_format($breakdown->accruedPaise / 100, 0) }}</p>
            <p class="text-xs text-gray-400">BV</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-500 mb-1">Reversed</p>
            <p class="text-xl font-bold text-red-600">{{ number_format(abs($breakdown->reversedPaise) / 100, 0) }}</p>
            <p class="text-xs text-gray-400">BV</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-500 mb-1">Net BV</p>
            <p class="text-xl font-bold text-brand-700">{{ number_format($breakdown->netPaise / 100, 0) }}</p>
            <p class="text-xs text-gray-400">BV</p>
        </div>
    </div>

    @if($entries->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500">No BV transactions yet.</p>
            <p class="text-sm text-gray-400 mt-1">BV accrues when you purchase products or when customers place orders via your shared links.</p>
        </div>
    @else
        {{-- Desktop table (sm+) --}}
        <div class="hidden sm:block bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[540px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Order</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Type</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">BV</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Running total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @php $running = $openingBalance; @endphp
                    @foreach($entries as $entry)
                    @php
                        $running += $entry->bv_paise;
                        $isCustomerSale = $entry->order !== null && ! $entry->order->self_consumption;
                        $isReversal = $entry->type === 'reversal';
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-600">{{ $entry->effective_at->format('d M Y') }}</td>
                        <td class="px-4 py-3">
                            @if($entry->order !== null)
                                @if($isCustomerSale)
                                <a href="{{ route('orders.sales.show', $entry->order->order_no) }}" class="font-mono text-brand-600 hover:text-brand-700">{{ $entry->order->order_no }}</a>
                                @else
                                <a href="{{ route('orders.show', $entry->order->order_no) }}" class="font-mono text-brand-600 hover:text-brand-700">{{ $entry->order->order_no }}</a>
                                @endif
                            @else
                            <span class="text-gray-400 font-mono">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5">
                                @if($isReversal)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border bg-red-50 text-red-700 border-red-200">Reversal</span>
                                @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border bg-green-50 text-green-700 border-green-200">Accrual</span>
                                @endif
                                @if($isCustomerSale && ! $isReversal)
                                {{-- Customer-sale badge: this BV came from an attributed order, not self-purchase. --}}
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border bg-brand-50 text-brand-700 border-brand-200" title="BV from a customer sale via your shared link">Customer sale</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold {{ $isReversal ? 'text-red-600' : 'text-green-700' }}">
                            {{ $isReversal ? '−' : '+' }}{{ number_format(abs($entry->bv_paise) / 100, 0) }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-brand-700">
                            {{ number_format($running / 100, 0) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards (xs only) --}}
        <div class="sm:hidden space-y-3">
            @php $running = $openingBalance; @endphp
            @foreach($entries as $entry)
            @php
                $running += $entry->bv_paise;
                $isCustomerSale = $entry->order !== null && ! $entry->order->self_consumption;
                $isReversal = $entry->type === 'reversal';
            @endphp
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @if($isReversal)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border bg-red-50 text-red-700 border-red-200">Reversal</span>
                        @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border bg-green-50 text-green-700 border-green-200">Accrual</span>
                        @endif
                        @if($isCustomerSale && ! $isReversal)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border bg-brand-50 text-brand-700 border-brand-200">Customer sale</span>
                        @endif
                    </div>
                    <span class="text-lg font-bold {{ $isReversal ? 'text-red-600' : 'text-green-700' }}">
                        {{ $isReversal ? '−' : '+' }}{{ number_format(abs($entry->bv_paise) / 100, 0) }} BV
                    </span>
                </div>
                <div class="flex justify-between text-xs text-gray-500">
                    <span>{{ $entry->effective_at->format('d M Y') }}</span>
                    @if($entry->order !== null)
                        @if($isCustomerSale)
                        <a href="{{ route('orders.sales.show', $entry->order->order_no) }}" class="font-mono text-brand-600">{{ $entry->order->order_no }}</a>
                        @else
                        <a href="{{ route('orders.show', $entry->order->order_no) }}" class="font-mono text-brand-600">{{ $entry->order->order_no }}</a>
                        @endif
                    @endif
                </div>
                <div class="mt-2 pt-2 border-t border-gray-100 flex justify-between text-xs">
                    <span class="text-gray-400">Running total</span>
                    <span class="font-semibold text-brand-700">{{ number_format($running / 100, 0) }} BV</span>
                </div>
            </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $entries->links() }}</div>
    @endif
</div>
@endsection
