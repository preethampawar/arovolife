@extends('layouts.app')
@section('title', 'My Sales')

@section('content')
@php
    $bvBadge = fn (string $state) => match ($state) {
        'accumulated' => 'bg-green-50 text-green-700 border-green-200',
        'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
        'reversed' => 'bg-red-50 text-red-700 border-red-200',
        default => 'bg-gray-50 text-gray-500 border-gray-200',
    };
@endphp

<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-4">My Sales</h1>

    {{-- Tab bar --}}
    <div class="flex gap-1 mb-6 border-b border-gray-200">
        <a href="{{ route('orders.index') }}"
           class="px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 -mb-px">
            My Purchases
        </a>
        <a href="{{ route('orders.sales') }}"
           class="px-4 py-2 text-sm font-medium border-b-2 border-brand-600 text-brand-700 -mb-px">
            My Sales
        </a>
    </div>

    <p class="text-sm text-gray-500 mb-4">
        Orders placed by customers via your shared product links. BV from these sales is credited to your account.
    </p>

    @if($sales->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 mb-2">No customer sales yet.</p>
            <p class="text-sm text-gray-400">Share a product link or Easy Purchase cart with a customer to get started.</p>
        </div>
    @else
        {{-- Desktop table (sm+) --}}
        <div class="hidden sm:block bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[680px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600 w-12">S.No</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Order</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Customer</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Total</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Status</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">BV</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">BV status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($sales as $order)
                    @php $bv = $order->salesBvStatus(); @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-500">{{ $sales->firstItem() + $loop->index }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('orders.sales.show', $order->order_no) }}" class="font-mono text-brand-600 hover:text-brand-700">{{ $order->order_no }}</a>
                        </td>
                        <td class="px-4 py-3 text-gray-700">{{ $order->customer?->display_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $order->placed_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right font-medium text-gray-900">{{ $order->displayTotal() }}</td>
                        <td class="px-4 py-3">@include('partials.order-status-badge', ['status' => $order->status])</td>
                        <td class="px-4 py-3 text-right text-brand-700">{{ number_format($order->bvTotalPaise() / 100, 0) }} BV</td>
                        <td class="px-4 py-3">
                            @if($bv['state'] !== 'none')
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $bvBadge($bv['state']) }}">{{ $bv['label'] }}</span>
                            @else
                            <span class="text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards (xs only) --}}
        <div class="sm:hidden space-y-3">
            @foreach($sales as $order)
            @php $bv = $order->salesBvStatus(); @endphp
            <a href="{{ route('orders.sales.show', $order->order_no) }}"
               class="block bg-white rounded-2xl border border-gray-200 p-4 hover:border-brand-300 transition-colors">
                <div class="flex items-start justify-between gap-2 mb-1">
                    <span class="font-mono text-brand-600 font-medium text-sm">{{ $order->order_no }}</span>
                    @include('partials.order-status-badge', ['status' => $order->status])
                </div>
                <p class="text-xs text-gray-500 mb-2">{{ $order->customer?->display_name ?? 'Customer' }}</p>
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-500">{{ $order->placed_at?->format('d M Y') }}</span>
                    <span class="font-semibold text-gray-900">{{ $order->displayTotal() }}</span>
                </div>
                @if($order->bvTotalPaise() > 0)
                <div class="mt-2 pt-2 border-t border-gray-100 flex items-center justify-between">
                    <span class="text-xs text-brand-700 font-semibold">{{ number_format($order->bvTotalPaise() / 100, 0) }} BV</span>
                    @if($bv['state'] !== 'none')
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $bvBadge($bv['state']) }}">{{ $bv['label'] }}</span>
                    @endif
                </div>
                @endif
            </a>
            @endforeach
        </div>

        <div class="mt-4">{{ $sales->links() }}</div>
    @endif
</div>
@endsection
