@extends('layouts.app')
@section('title', 'Order '.$order->order_no)

@section('content')
@php
    $showBv = $showBv ?? false; // BV is distributor-only (hard rule #3)
    $bv = $order->personalBvStatus();
    $bvBadge = match ($bv['state']) {
        'accumulated' => 'bg-green-50 text-green-700 border-green-200',
        'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
        'reversed' => 'bg-red-50 text-red-700 border-red-200',
        default => 'bg-gray-50 text-gray-500 border-gray-200',
    };
@endphp

<div class="max-w-3xl mx-auto px-4 py-8">
    <a href="{{ route('orders.index') }}" class="text-sm text-brand-600 hover:text-brand-700">← Back to my orders</a>

    <div class="flex items-center justify-between mt-3 mb-6 gap-3 flex-wrap">
        <h1 class="text-2xl font-bold text-gray-900">Order <span class="font-mono text-brand-600">{{ $order->order_no }}</span></h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('orders.invoice', $order->order_no) }}"
               class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                Order summary
            </a>
            @include('partials.order-status-badge', ['status' => $order->status])
        </div>
    </div>

    @if(session('status'))
    <div class="mb-5 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    @error('cancel')<div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $message }}</div>@enderror

    {{-- Items --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-4">Items</h2>
        <div class="space-y-3">
            @foreach($order->items as $item)
            <div class="flex justify-between text-sm">
                <span>
                    <strong class="text-gray-900">{{ $item->product_name_snapshot }}</strong> × {{ $item->qty }}
                    @if($showBv && $item->lineBvPaise() > 0)
                    <span class="ml-1 text-xs text-brand-700">({{ number_format($item->lineBvPaise() / 100, 0) }} BV)</span>
                    @endif
                </span>
                <span class="font-medium">₹{{ number_format($item->line_total_paise / 100, 2) }}</span>
            </div>
            @endforeach
        </div>
        <div class="mt-4 pt-4 border-t border-gray-200 space-y-1">
            <div class="flex justify-between text-sm"><span class="text-gray-600">Subtotal</span><span>₹{{ number_format(($order->subtotal_paise - $order->gst_paise) / 100, 2) }}</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-600">GST</span><span>₹{{ number_format($order->gst_paise / 100, 2) }}</span></div>
            @if($order->discount_paise > 0)
            <div class="flex justify-between text-sm text-green-700"><span>Discount</span><span>−₹{{ number_format($order->discount_paise / 100, 2) }}</span></div>
            @endif
            <div class="flex justify-between text-sm"><span class="text-gray-600">Shipping</span>
                @if($order->shipping_paise > 0)<span>₹{{ number_format($order->shipping_paise / 100, 2) }}</span>@else<span class="text-green-700">Free</span>@endif
            </div>
            <div class="flex justify-between font-semibold pt-2 border-t border-gray-100 mt-2"><span>Total</span><span>{{ $order->displayTotal() }}</span></div>
            @if($showBv && $order->bvTotalPaise() > 0)
            <div class="flex justify-between text-sm text-brand-700 pt-1"><span>Total BV</span><span class="font-semibold">{{ number_format($order->bvTotalPaise() / 100, 0) }} BV</span></div>
            @endif
        </div>
    </div>

    {{-- BV accumulation status (distributor-only) --}}
    @if($showBv && $bv['state'] !== 'none')
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-2">Business Volume</h2>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $bvBadge }}">{{ $bv['label'] }}</span>
            <span class="text-sm text-gray-600">{{ number_format($order->bvTotalPaise() / 100, 0) }} BV from this order</span>
        </div>
        @if($bv['state'] === 'pending')
        <p class="text-xs text-gray-500 mt-2">BV is counted toward your personal volume once payment is received. It is reversed if the order is refunded.</p>
        @endif
    </div>
    @endif

    {{-- Shipping --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-900 mb-3">Shipping to</h2>
        <p class="text-sm text-gray-700">
            {{ $order->ship_name }}<br>
            {{ $order->ship_phone_e164 }}<br>
            {{ $order->ship_line1 }}@if($order->ship_line2), {{ $order->ship_line2 }}@endif<br>
            {{ $order->ship_city }}, {{ $order->ship_state }} {{ $order->ship_pincode }}
        </p>
        @if($order->ship_carrier || $order->ship_tracking_no)
        <div class="mt-3 pt-3 border-t border-gray-100 text-sm text-gray-700">
            <span class="font-medium text-gray-900">Tracking:</span>
            {{ $order->ship_carrier ?: 'Courier' }}@if($order->ship_tracking_no) — <span class="font-mono">{{ $order->ship_tracking_no }}</span>@endif
        </div>
        @endif
    </div>

    {{-- Cancel (only before the order ships) --}}
    @if(in_array($order->status, ['placed', 'paid'], true))
    <div class="mt-6 text-right">
        <form method="POST" action="{{ route('orders.cancel', $order->order_no) }}" class="inline"
            data-confirm="Cancel this order?"
            data-confirm-title="Cancel your order"
            data-confirm-impact="Impact: this cancels your order before it ships. The items are released and the order can't be reinstated — you'd need to place a new order. Any payment already made is refunded by our team separately.">
            @csrf
            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Cancel this order</button>
        </form>
    </div>
    @endif
</div>
@endsection
