@extends('layouts.shop')
@section('title', 'Your Cart')

@section('content')

<h1 class="text-2xl font-bold mb-6">Your Cart</h1>

@if($cart->items->isEmpty())
<div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
    <p class="text-gray-500 mb-4">Your cart is empty.</p>
    <a href="{{ route('shop.index') }}" class="text-brand-600 hover:text-brand-700 font-medium">Continue shopping →</a>
</div>
@else
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-3">
        @foreach($cart->items as $item)
        <div class="bg-white rounded-2xl border border-gray-200 p-4 flex items-start gap-4">
            <div class="w-20 h-20 rounded-lg bg-gradient-to-br from-brand-50 to-brand-100 flex items-center justify-center shrink-0">
                @if($item->variant->product->image_url)
                <img src="{{ $item->variant->product->image_url }}" class="w-full h-full object-cover rounded-lg">
                @else
                <svg class="w-10 h-10 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75 7.41 11.59c.8-.8 2.1-.8 2.9 0l4.56 4.56m-1.5-1.5 1.66-1.66c.8-.8 2.1-.8 2.9 0l2.83 2.83"/></svg>
                @endif
            </div>
            <div class="flex-1">
                <a href="{{ route('shop.product', $item->variant->product->slug) }}" class="font-semibold text-gray-900 hover:text-brand-600">{{ $item->variant->product->name }}</a>
                <p class="text-xs text-gray-500 mt-0.5 font-mono">SKU {{ $item->variant->variant_sku }}</p>
                <p class="text-sm font-semibold text-gray-900 mt-1">₹{{ number_format($item->unit_price_paise / 100, 2) }}</p>
            </div>
            <div class="flex flex-col items-end gap-2">
                {{-- Quantity stepper: each button is its own form-submit, so it
                     works without JavaScript. Decrease is disabled at 1 (use
                     Remove to delete the line); increase is capped at 10. --}}
                <div class="flex items-center border border-gray-300 rounded-lg overflow-hidden">
                    <form method="POST" action="{{ route('shop.cart.update', $item) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="qty" value="{{ $item->qty - 1 }}">
                        <button type="submit" @disabled($item->qty <= 1) aria-label="Decrease quantity"
                            class="px-3 py-1.5 text-base leading-none text-gray-600 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed">−</button>
                    </form>
                    <span class="w-10 text-center text-sm tabular-nums select-none" aria-live="polite">{{ $item->qty }}</span>
                    <form method="POST" action="{{ route('shop.cart.update', $item) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="qty" value="{{ $item->qty + 1 }}">
                        <button type="submit" @disabled($item->qty >= 10) aria-label="Increase quantity"
                            class="px-3 py-1.5 text-base leading-none text-gray-600 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed">+</button>
                    </form>
                </div>
                <form method="POST" action="{{ route('shop.cart.remove', $item) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-xs text-gray-500 hover:text-red-600">Remove</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6 h-fit sticky top-20">
        <h2 class="font-semibold text-gray-900 mb-4">Order Summary</h2>
        @php
            $couponDiscount = $couponDiscount ?? 0;
            $shippingPaise = $shippingPaise ?? 0;
            $amountToFreeShippingPaise = $amountToFreeShippingPaise ?? 0;
            $finalTotal = max(0, $cart->totalPaise() - $couponDiscount) + $shippingPaise;
        @endphp
        <div class="space-y-2 text-sm mb-4 pb-4 border-b border-gray-200">
            <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span class="font-medium">₹{{ number_format(($cart->subtotalPaise() - $cart->gstPaise()) / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">GST</span><span class="font-medium">₹{{ number_format($cart->gstPaise() / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">Shipping</span>
                @if($shippingPaise > 0)<span class="font-medium">₹{{ number_format($shippingPaise / 100, 2) }}</span>
                @else<span class="font-medium text-green-700">Free</span>@endif
            </div>
            @if($shippingPaise > 0 && $amountToFreeShippingPaise > 0)
            <p class="text-xs text-gray-500">Add ₹{{ number_format($amountToFreeShippingPaise / 100, 2) }} more to get free shipping.</p>
            @endif
            @if($couponDiscount > 0)
            <div class="flex justify-between text-green-700"><span>Discount ({{ $cart->coupon->code }})</span><span class="font-medium">−₹{{ number_format($couponDiscount / 100, 2) }}</span></div>
            @endif
            @auth
                @php $bvTotal = auth()->user()->distributor ? $cart->items->sum(fn ($i) => $i->bv_paise * $i->qty) : 0; @endphp
                @if($bvTotal > 0)
                {{-- BV total shown only to logged-in distributors — a factual point
                     total used by the compensation plan, never an earnings figure
                     (DSR Rule 5(1)(d) / hard rule #3). --}}
                <div class="flex justify-between text-brand-700"><span>Total BV</span><span class="font-semibold" title="Business Volume — points used in the compensation plan">{{ number_format($bvTotal / 100, 0) }} BV</span></div>
                @endif
            @endauth
        </div>

        {{-- Promo code --}}
        <div class="mb-4">
            @if($cart->coupon !== null && $couponDiscount > 0)
                <div class="flex items-center justify-between rounded-lg bg-green-50 border border-green-200 px-3 py-2 text-sm">
                    <span class="text-green-800 font-medium">{{ $cart->coupon->code }} applied</span>
                    <form method="POST" action="{{ route('shop.cart.coupon.remove') }}">@csrf @method('DELETE')<button type="submit" class="text-xs text-green-700 hover:text-red-600 underline">Remove</button></form>
                </div>
            @else
                <form method="POST" action="{{ route('shop.cart.coupon.apply') }}" class="flex gap-2">
                    @csrf
                    <input name="code" type="text" value="{{ old('code') }}" placeholder="Promo code"
                        class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <button type="submit" class="shrink-0 px-4 rounded-lg bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold">Apply</button>
                </form>
                @error('code')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            @endif
        </div>

        <div class="flex justify-between mb-5">
            <span class="font-semibold text-gray-900">Total</span>
            <span class="font-bold text-lg text-gray-900">₹{{ number_format($finalTotal / 100, 2) }}</span>
        </div>
        <a href="{{ route('shop.checkout') }}"
           class="block text-center w-full py-3 rounded-full bg-brand-500 hover:bg-brand-600 text-white font-semibold text-sm transition-colors">
            Proceed to Checkout
        </a>
        <p class="text-xs text-gray-500 mt-3 text-center">30-day return window on every order.</p>
    </div>
</div>
@endif

@endsection
