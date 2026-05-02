@extends('layouts.shop')
@section('title', $product->name)

@section('content')

@php $variant = $product->primaryVariant(); @endphp

<div class="mb-4">
    <a href="{{ route('shop.index') }}" class="text-sm text-brand-600 hover:text-brand-700">← Back to shop</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
    <div class="bg-gradient-to-br from-brand-50 to-brand-100 rounded-2xl aspect-square flex items-center justify-center">
        @if($product->image_url)
        <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="w-full h-full object-cover rounded-2xl">
        @else
        <div class="text-center text-brand-400 p-8">
            <svg class="w-24 h-24 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75 7.41 11.59c.8-.8 2.1-.8 2.9 0l4.56 4.56m-1.5-1.5 1.66-1.66c.8-.8 2.1-.8 2.9 0l2.83 2.83M3 16.5V6.75A2.25 2.25 0 0 1 5.25 4.5h13.5A2.25 2.25 0 0 1 21 6.75v10.5m-18 0A2.25 2.25 0 0 0 5.25 18.75h13.5A2.25 2.25 0 0 0 21 16.5m-18 0L7 12.5" /></svg>
            <p class="font-semibold">{{ $product->name }}</p>
        </div>
        @endif
    </div>

    <div>
        @if($product->category)
        <span class="text-xs text-brand-600 uppercase tracking-wider font-semibold">{{ str_replace('-', ' ', $product->category) }}</span>
        @endif
        <h1 class="text-3xl font-bold text-gray-900 mt-2 mb-3">{{ $product->name }}</h1>

        @if($product->short_description)
        <p class="text-gray-600 mb-5">{{ $product->short_description }}</p>
        @endif

        @if($variant !== null)
        <div class="flex items-baseline gap-3 mb-6">
            <span class="text-3xl font-bold text-gray-900">{{ $variant->displayPrice() }}</span>
            @if($variant->hasDiscount())
            <span class="text-lg text-gray-400 line-through">{{ $variant->displayMrp() }}</span>
            <span class="text-sm font-semibold text-green-700 bg-green-50 px-2.5 py-1 rounded">{{ $variant->discountPercent() }}% off</span>
            @endif
        </div>
        <p class="text-xs text-gray-500 mb-6">Inclusive of all taxes. HSN: {{ $product->hsn_code }}</p>

        <form method="POST" action="{{ route('shop.cart.add') }}" class="mb-8">
            @csrf
            <input type="hidden" name="product_variant_id" value="{{ $variant->id }}">
            <div class="flex items-center gap-3">
                <div class="flex items-center border border-gray-300 rounded-lg">
                    <label class="text-xs text-gray-500 px-3">Qty</label>
                    <input name="qty" type="number" value="1" min="1" max="10"
                        class="w-16 bg-transparent py-2.5 text-sm text-center focus:outline-none">
                </div>
                <button type="submit"
                    class="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-full bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors shadow-md shadow-brand-500/20">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75"/></svg>
                    Add to Cart
                </button>
            </div>
        </form>
        @endif

        <div class="grid grid-cols-2 gap-3 text-xs">
            <div class="flex items-start gap-2 p-3 rounded-lg bg-gray-50 border border-gray-200">
                <svg class="w-5 h-5 text-brand-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <span><strong class="text-gray-900 block">30-day returns</strong><span class="text-gray-500">Cooling-off window on every order.</span></span>
            </div>
            <div class="flex items-start gap-2 p-3 rounded-lg bg-gray-50 border border-gray-200">
                <svg class="w-5 h-5 text-brand-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <span><strong class="text-gray-900 block">GST invoice</strong><span class="text-gray-500">Issued for every order.</span></span>
            </div>
        </div>

        @if($product->description)
        <div class="mt-8 pt-6 border-t border-gray-200">
            <h2 class="font-semibold text-gray-900 mb-2">Product details</h2>
            <p class="text-sm text-gray-600 leading-relaxed">{{ $product->description }}</p>
        </div>
        @endif
    </div>
</div>

@endsection
