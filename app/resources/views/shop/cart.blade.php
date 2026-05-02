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
                <form method="POST" action="{{ route('shop.cart.update', $item) }}">
                    @csrf @method('PATCH')
                    <div class="flex items-center border border-gray-300 rounded-lg">
                        <input name="qty" type="number" value="{{ $item->qty }}" min="0" max="10"
                            onchange="this.form.submit()"
                            class="w-14 bg-transparent py-1.5 text-sm text-center focus:outline-none">
                    </div>
                </form>
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
        <div class="space-y-2 text-sm mb-4 pb-4 border-b border-gray-200">
            <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span class="font-medium">₹{{ number_format(($cart->subtotalPaise() - $cart->gstPaise()) / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">GST</span><span class="font-medium">₹{{ number_format($cart->gstPaise() / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">Shipping</span><span class="font-medium text-green-700">Free</span></div>
        </div>
        <div class="flex justify-between mb-5">
            <span class="font-semibold text-gray-900">Total</span>
            <span class="font-bold text-lg text-gray-900">₹{{ number_format($cart->totalPaise() / 100, 2) }}</span>
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
