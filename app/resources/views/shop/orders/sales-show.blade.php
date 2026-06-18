@extends('layouts.app')
@section('title', 'Sale '.$order->order_no)

@section('content')
@php
    $bv = $order->salesBvStatus();
    $bvBadge = match ($bv['state']) {
        'accumulated' => 'bg-green-50 text-green-700 border-green-200',
        'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
        'reversed' => 'bg-red-50 text-red-700 border-red-200',
        default => 'bg-gray-50 text-gray-500 border-gray-200',
    };
@endphp

<div class="max-w-3xl mx-auto px-4 py-8">
    <a href="{{ route('orders.sales') }}" class="text-sm text-brand-600 hover:text-brand-700">← Back to my sales</a>

    <div class="flex items-center justify-between mt-3 mb-6 gap-3 flex-wrap">
        <h1 class="text-2xl font-bold text-gray-900">Sale <span class="font-mono text-brand-600">{{ $order->order_no }}</span></h1>
        @include('partials.order-status-badge', ['status' => $order->status])
    </div>

    {{-- Customer --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-5 mb-6">
        <h2 class="font-semibold text-gray-900 mb-2">Customer</h2>
        <p class="text-sm text-gray-700">{{ $order->customer?->display_name ?? '—' }}</p>
        <p class="text-xs text-gray-400 mt-0.5">Customer identity details are not disclosed — contact via your referral network.</p>
    </div>

    {{-- Items --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-4">Items</h2>
        <div class="space-y-3">
            @foreach($order->items as $item)
            <div class="flex justify-between text-sm">
                <span>
                    <strong class="text-gray-900">{{ $item->product_name_snapshot }}</strong> × {{ $item->qty }}
                    @if($item->lineBvPaise() > 0)
                    <span class="ml-1 text-xs text-brand-700">({{ number_format($item->lineBvPaise() / 100, 0) }} BV)</span>
                    @endif
                </span>
                <span class="font-medium">₹{{ number_format($item->line_total_paise / 100, 2) }}</span>
            </div>
            @endforeach
        </div>
        <div class="mt-4 pt-4 border-t border-gray-200 space-y-1">
            @if($order->bvTotalPaise() > 0)
            <div class="flex justify-between text-brand-700 pb-2 mb-2 border-b border-gray-100">
                <span class="font-semibold">Total BV</span>
                <span class="font-bold" title="Business Volume credited to you from this customer sale">{{ number_format($order->bvTotalPaise() / 100, 0) }} BV</span>
            </div>
            @endif
            <div class="flex justify-between text-sm"><span class="text-gray-600">Subtotal</span><span>₹{{ number_format(($order->subtotal_paise - $order->gst_paise) / 100, 2) }}</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-600">GST</span><span>₹{{ number_format($order->gst_paise / 100, 2) }}</span></div>
            @if($order->discount_paise > 0)
            <div class="flex justify-between text-sm text-green-700"><span>Discount</span><span>−₹{{ number_format($order->discount_paise / 100, 2) }}</span></div>
            @endif
            <div class="flex justify-between text-sm"><span class="text-gray-600">Shipping</span>
                @if($order->shipping_paise > 0)<span>₹{{ number_format($order->shipping_paise / 100, 2) }}</span>@else<span class="text-green-700">Free</span>@endif
            </div>
            <div class="flex justify-between font-semibold pt-2 border-t border-gray-100 mt-2"><span>Total</span><span>{{ $order->displayTotal() }}</span></div>
        </div>
    </div>

    {{-- BV status for this customer sale --}}
    @if($bv['state'] !== 'none')
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-2">Business Volume (your credit)</h2>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $bvBadge }}">{{ $bv['label'] }}</span>
            <span class="text-sm text-gray-600">{{ number_format($order->bvTotalPaise() / 100, 0) }} BV credited to your account from this sale</span>
        </div>
        @if($bv['state'] === 'pending')
        <p class="text-xs text-gray-500 mt-2">BV is counted once payment clears. It is reversed if the customer returns the order.</p>
        @endif
    </div>
    @endif

    {{-- Shipping destination (city/state only — no PII exposure) --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-900 mb-3">Shipped to</h2>
        <p class="text-sm text-gray-700">
            {{ $order->ship_city }}@if($order->ship_state), {{ $order->ship_state }}@endif
            @if($order->ship_pincode) — {{ $order->ship_pincode }}@endif
        </p>
        @if($order->ship_carrier || $order->ship_tracking_no)
        <div class="mt-3 pt-3 border-t border-gray-100 text-sm text-gray-700">
            <span class="font-medium text-gray-900">Tracking:</span>
            {{ $order->ship_carrier ?: 'Courier' }}@if($order->ship_tracking_no) — <span class="font-mono">{{ $order->ship_tracking_no }}</span>@endif
        </div>
        @endif
    </div>
</div>
@endsection
