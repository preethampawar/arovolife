@extends('layouts.app')
@section('title', 'My Orders')

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
    <h1 class="text-2xl font-bold text-gray-900 mb-6">My Orders</h1>

    @if($orders->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 mb-4">You haven't placed any orders yet.</p>
            <a href="{{ route('shop.index') }}" class="text-brand-600 hover:text-brand-700 font-medium">Browse products →</a>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600 w-12">S.No</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Order</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Total</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Status</th>
                        @if($showBv ?? false)
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">BV</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">BV status</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($orders as $order)
                    @php $bv = $order->personalBvStatus(); @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-500">{{ $orders->firstItem() + $loop->index }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('orders.show', $order->order_no) }}" class="font-mono text-brand-600 hover:text-brand-700">{{ $order->order_no }}</a>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $order->placed_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right font-medium text-gray-900">{{ $order->displayTotal() }}</td>
                        <td class="px-4 py-3">@include('partials.order-status-badge', ['status' => $order->status])</td>
                        @if($showBv ?? false)
                        <td class="px-4 py-3 text-right text-brand-700">{{ number_format($order->bvTotalPaise() / 100, 0) }} BV</td>
                        <td class="px-4 py-3">
                            @if($bv['state'] !== 'none')
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $bvBadge($bv['state']) }}">{{ $bv['label'] }}</span>
                            @else
                            <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</div>
@endsection
