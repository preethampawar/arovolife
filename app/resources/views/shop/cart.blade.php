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
        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-200 bg-red-50 hover:bg-red-100 text-sm font-medium text-red-700 transition-colors">
            <x-lucide-trash-2 class="w-3.5 h-3.5" />
            Clear all
        </button>
    </form>
    @endif
</div>

@if($cart->items->isEmpty())
<div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
    <p class="text-gray-600 mb-4">Your cart is empty.</p>
    <a href="{{ route('shop.index') }}" class="text-brand-700 hover:text-brand-800 font-medium">Continue shopping →</a>
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
                <x-lucide-image class="w-10 h-10 text-brand-400" />
                @endif
            </div>
            <div class="flex-1">
                <a href="{{ route('shop.product', $item->variant->product->slug) }}" class="font-semibold text-gray-900 hover:text-brand-800">{{ $item->variant->product->name }}</a>
                <p class="text-xs text-gray-600 mt-0.5 font-mono">SKU {{ $item->variant->variant_sku }}</p>
                <p class="text-sm font-semibold text-gray-900 mt-1">₹{{ \App\Modules\Shared\Support\IndianNumber::format($item->unit_price_paise / 100, 2) }}</p>
                {{-- Per-product BV under the price — distributor-only, a factual
                     point value, never an earnings figure (hard rule #3). --}}
                @auth
                    @if(auth()->user()->distributor && $item->bv_paise > 0)
                    <p class="text-xs font-semibold text-brand-700 mt-0.5" title="Business Volume — points used in the compensation plan">{{ \App\Modules\Shared\Support\IndianNumber::format($item->bv_paise / 100, 0) }} BV</p>
                    @endif
                @endauth
            </div>
            <div class="flex flex-col items-end gap-2">
                {{-- Delete control surfaced at the TOP of the line (partner
                     feedback) with a clear trash affordance, above the stepper. --}}
                <form method="POST" action="{{ route('shop.cart.remove', $item) }}">
                    @csrf @method('DELETE')
                    <button type="submit" aria-label="Remove this item" title="Remove from cart"
                        class="inline-flex items-center gap-1 text-xs font-medium text-gray-600 hover:text-red-600 transition-colors">
                        <x-lucide-trash-2 class="w-4 h-4" />
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
                <span class="font-bold" title="Business Volume — points used in the compensation plan">{{ \App\Modules\Shared\Support\IndianNumber::format($bvTotal / 100, 0) }} BV</span>
            </div>
            @endif
        @endauth
        <div class="space-y-2 text-sm mb-4 pb-4 border-b border-gray-200">
            <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span class="font-medium">₹{{ \App\Modules\Shared\Support\IndianNumber::format(($cart->subtotalPaise() - $cart->gstPaise()) / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">GST</span><span class="font-medium">₹{{ \App\Modules\Shared\Support\IndianNumber::format($cart->gstPaise() / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">Shipping</span>
                @if($shippingPaise > 0)<span class="font-medium">₹{{ \App\Modules\Shared\Support\IndianNumber::format($shippingPaise / 100, 2) }}</span>
                @else<span class="font-medium text-green-700">Free</span>@endif
            </div>
            @if($shippingPaise > 0 && $amountToFreeShippingPaise > 0)
            <p class="text-xs text-gray-600">Add ₹{{ \App\Modules\Shared\Support\IndianNumber::format($amountToFreeShippingPaise / 100, 2) }} more to get free shipping.</p>
            @endif
            @if($couponDiscount > 0)
            <div class="flex justify-between text-green-700"><span>Discount ({{ $cart->coupon->code }})</span><span class="font-medium">−₹{{ \App\Modules\Shared\Support\IndianNumber::format($couponDiscount / 100, 2) }}</span></div>
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
            <span class="font-bold text-lg text-gray-900">₹{{ \App\Modules\Shared\Support\IndianNumber::format($finalTotal / 100, 2) }}</span>
        </div>
        <a href="{{ route('shop.checkout') }}"
           class="block text-center w-full py-3 rounded-full bg-brand-700 hover:bg-brand-800 text-white font-semibold text-sm transition-colors">
            Proceed to Checkout
        </a>
        <p class="text-xs text-gray-600 mt-3 text-center">30-day return window on every order.</p>

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
                <p class="text-xs text-gray-600 mb-2">Send this to a customer. Purchases through it for the next 30 days are attributed to you (ADN {{ auth()->user()->distributor->adn }}).</p>
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
                        <x-lucide-share-2 class="w-4 h-4" />
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
