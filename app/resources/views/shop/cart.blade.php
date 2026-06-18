@extends('layouts.shop')
@section('title', 'Your Cart')

@section('content')

<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">Your Cart</h1>
    @if(! $cart->items->isEmpty())
    <form method="POST" action="{{ route('shop.cart.clear') }}"
          data-confirm="Remove all items from your cart?"
          data-confirm-title="Clear cart"
          data-confirm-impact="All {{ $cart->items->count() }} {{ $cart->items->count() === 1 ? 'item' : 'items' }} will be removed. This cannot be undone.">
        @csrf @method('DELETE')
        <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium transition-colors">
            Clear all items
        </button>
    </form>
    @endif
</div>

@if($cart->items->isEmpty())
<div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
    <p class="text-gray-500 mb-4">Your cart is empty.</p>
    <a href="{{ route('shop.index') }}" class="text-brand-600 hover:text-brand-700 font-medium">Continue shopping →</a>
</div>
@else
@php $addedVariantId = (int) session('added_variant_id'); @endphp
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-3">
        @foreach($cart->items as $item)
        @php $justAdded = $addedVariantId > 0 && (int) $item->product_variant_id === $addedVariantId; @endphp
        <div class="bg-white rounded-2xl border border-gray-200 p-4 flex items-start gap-4 scroll-mt-24 {{ $justAdded ? 'cart-line-added' : '' }}" @if($justAdded) data-cart-added @endif>
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
                {{-- Per-product BV under the price — distributor-only, a factual
                     point value, never an earnings figure (hard rule #3). --}}
                @auth
                    @if(auth()->user()->distributor && $item->bv_paise > 0)
                    <p class="text-xs font-semibold text-brand-700 mt-0.5" title="Business Volume — points used in the compensation plan">{{ number_format($item->bv_paise / 100, 0) }} BV</p>
                    @endif
                @endauth
            </div>
            <div class="flex flex-col items-end gap-2">
                {{-- Delete control surfaced at the TOP of the line (partner
                     feedback) with a clear trash affordance, above the stepper. --}}
                <form method="POST" action="{{ route('shop.cart.remove', $item) }}">
                    @csrf @method('DELETE')
                    <button type="submit" aria-label="Remove this item" title="Remove from cart"
                        class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-red-600 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                        Remove
                    </button>
                </form>
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
        @auth
            @php $bvTotal = auth()->user()->distributor ? $cart->bvTotalPaise() : 0; @endphp
            @if($bvTotal > 0)
            {{-- BV at the TOP of the Order Summary (distributor-only). A factual
                 point total for the compensation plan, never an earnings figure
                 (DSR Rule 5(1)(d) / hard rule #3). --}}
            <div class="flex justify-between text-sm mb-4 pb-4 border-b border-gray-200 text-brand-700">
                <span class="font-semibold">Total BV</span>
                <span class="font-bold" title="Business Volume — points used in the compensation plan">{{ number_format($bvTotal / 100, 0) }} BV</span>
            </div>
            @endif
        @endauth
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

        @if(auth()->user()?->distributor)
        {{-- Easy Purchase (multi-product): a distributor can share this whole
             cart with a customer. The link sets the 30-day attribution cookie
             so purchases through it are credited to them. No income is shown
             or implied here (hard rule #3). --}}
        <div class="mt-5 pt-5 border-t border-gray-200">
            @error('share')
                <p class="mb-2 text-xs text-red-600">{{ $message }}</p>
            @enderror
            @if(session('shared_cart_url'))
                <p class="text-sm font-semibold text-gray-800 mb-1">Easy Purchase link ready</p>
                <p class="text-xs text-gray-500 mb-2">Send this to a customer. Purchases through it for the next 30 days are attributed to you (ADN {{ auth()->user()->distributor->adn }}).</p>
                <div class="flex items-center gap-2">
                    <input type="text" readonly value="{{ session('shared_cart_url') }}" id="sharedCartInput"
                        class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-mono text-gray-600 focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <button type="button"
                        onclick="const b=this; navigator.clipboard.writeText(document.getElementById('sharedCartInput').value).then(function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy';},1500);})"
                        class="shrink-0 px-3 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold transition-colors">Copy</button>
                </div>
            @else
                <form method="POST" action="{{ route('shop.cart.share') }}">
                    @csrf
                    <button type="submit"
                        class="flex items-center justify-center gap-2 w-full py-2.5 rounded-full border border-brand-300 bg-brand-50/40 hover:bg-brand-50 text-brand-700 font-semibold text-sm transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
                        Share this cart (Easy Purchase)
                    </button>
                </form>
            @endif
        </div>
        @endif
    </div>
</div>
@endif

@if(session('added_variant_id'))
<script>
    // Bring the just-added cart line into view (in case the cart is long).
    // The brief highlight itself is a one-shot CSS animation (.cart-line-added).
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.querySelector('[data-cart-added]');
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
</script>
@endif

@endsection
