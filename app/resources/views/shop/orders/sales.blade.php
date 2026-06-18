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
    <h1 class="text-2xl font-bold text-gray-900 mb-4">My Orders</h1>

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
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
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
                        <td class="px-4 py-3 font-mono text-gray-800">{{ $order->order_no }}</td>
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

        <div class="mt-4">{{ $sales->links() }}</div>
    @endif
</div>
@endsection
