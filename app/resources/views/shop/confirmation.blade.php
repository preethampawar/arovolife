@extends('layouts.shop')
@section('title', 'Order Confirmed')

@section('content')

<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-2xl border border-green-200 p-8 mb-6 text-center">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-green-50 flex items-center justify-center">
            <x-lucide-check class="w-10 h-10 text-green-600" />
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Order Confirmed!</h1>
        <p class="text-gray-600">Thank you for your order, {{ $order->customer->display_name }}.</p>
        <p class="text-sm text-gray-600 mt-1">Order number: <strong class="text-gray-900 font-mono">{{ $order->order_no }}</strong></p>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-4">Order Details</h2>
        @php $showBv = $showBv ?? false; @endphp
        <div class="space-y-3">
            @foreach($order->items as $item)
            <div class="flex justify-between text-sm">
                <span>
                    <strong class="text-gray-900">{{ $item->product_name_snapshot }}</strong>
                    × {{ $item->qty }}
                    @if($showBv && $item->lineBvPaise() > 0)
                    <span class="ml-1 text-xs text-brand-700" title="Business Volume for this line">({{ \App\Modules\Shared\Support\IndianNumber::format($item->lineBvPaise() / 100, 0) }} BV)</span>
                    @endif
                </span>
                <span class="font-medium">₹{{ \App\Modules\Shared\Support\IndianNumber::format($item->line_total_paise / 100, 2) }}</span>
            </div>
            @endforeach
        </div>
        <div class="mt-4 pt-4 border-t border-gray-200 space-y-1">
            @if($showBv && $order->bvTotalPaise() > 0)
            {{-- BV at the TOP of the totals, mirroring the cart's Order Summary. --}}
            <div class="flex justify-between text-brand-700 pb-2 mb-2 border-b border-gray-100">
                <span class="font-semibold">Total BV</span>
                <span class="font-bold" title="Business Volume — points used in the compensation plan">{{ \App\Modules\Shared\Support\IndianNumber::format($order->bvTotalPaise() / 100, 0) }} BV</span>
            </div>
            @endif
            <div class="flex justify-between text-sm"><span class="text-gray-600">Subtotal</span><span>₹{{ \App\Modules\Shared\Support\IndianNumber::format(($order->subtotal_paise - $order->gst_paise) / 100, 2) }}</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-600">GST</span><span>₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->gst_paise / 100, 2) }}</span></div>
            @if($order->discount_paise > 0)
            <div class="flex justify-between text-sm text-green-700"><span>Discount</span><span>−₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->discount_paise / 100, 2) }}</span></div>
            @endif
            @if(($repurchaseCreditApplied ?? 0) > 0)
            <div class="flex justify-between text-sm text-green-700"><span>Repurchase Credit</span><span>−₹{{ \App\Modules\Shared\Support\IndianNumber::format($repurchaseCreditApplied / 100, 2) }}</span></div>
            @endif
            <div class="flex justify-between text-sm"><span class="text-gray-600">Shipping</span>
                @if($order->shipping_paise > 0)<span>₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->shipping_paise / 100, 2) }}</span>
                @else<span class="text-green-700">Free</span>@endif
            </div>
            <div class="flex justify-between font-semibold pt-2 border-t border-gray-100 mt-2">
                <span>Total</span><span>{{ $order->displayTotal() }}</span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-3">Shipping to</h2>
        <p class="text-sm text-gray-700">
            {{ $order->ship_name }}<br>
            {{ $order->ship_phone_e164 }}<br>
            {{ $order->ship_line1 }}@if($order->ship_line2), {{ $order->ship_line2 }}@endif<br>
            {{ $order->ship_city }}, {{ $order->ship_state }} {{ $order->ship_pincode }}
        </p>
    </div>

    <div class="bg-brand-50 border border-brand-200 rounded-xl p-5 text-sm text-brand-800 mb-6">
        <p class="font-semibold mb-1">Your 30-day return window</p>
        <p class="text-brand-700">Your cooling-off clock begins when the order is delivered. You'll receive reminders before it closes.</p>
    </div>

    <div class="flex justify-center gap-3">
        <a href="{{ route('shop.index') }}" class="px-6 py-2.5 rounded-full border border-gray-300 text-sm text-gray-700 hover:bg-gray-50 font-medium">Continue Shopping</a>
    </div>
</div>

@endsection
