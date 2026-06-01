@extends('layouts.shop')
@section('title', $product->name)

@section('content')

@php
    $variant = $product->primaryVariant();
    $gallery = $product->galleryImages;
    $images = $gallery->map(fn ($g) => $g->url());
    if ($images->isEmpty() && $product->image_url) {
        $images = collect([$product->image_url]);
    }
    $mainImage = $images->first();
    $catLabel = $product->productCategory?->name ?? ($product->category ? str_replace('-', ' ', $product->category) : null);
    // After-login pricing: the distributor tier + BV are shown only to a
    // logged-in distributor (hard rule #3 — factual catalogue values, never
    // an earnings projection; public sees MRP/sale only).
    $me = auth()->user();
    $distributor = $me?->distributor;
    $myAdn = $distributor?->adn;
@endphp

<div class="mb-4">
    <a href="{{ route('shop.index') }}" class="text-sm text-brand-600 hover:text-brand-700">← Back to shop</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
    {{-- ── Gallery ──────────────────────────────────────────────────────── --}}
    <div>
        <div class="bg-gradient-to-br from-brand-50 to-brand-100 rounded-2xl aspect-square flex items-center justify-center overflow-hidden">
            @if($mainImage)
                <img id="productMainImage" src="{{ $mainImage }}" alt="{{ $product->name }}" class="w-full h-full object-cover rounded-2xl">
            @else
                <div class="text-center text-brand-400 p-8">
                    <svg class="w-24 h-24 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75 7.41 11.59c.8-.8 2.1-.8 2.9 0l4.56 4.56m-1.5-1.5 1.66-1.66c.8-.8 2.1-.8 2.9 0l2.83 2.83M3 16.5V6.75A2.25 2.25 0 0 1 5.25 4.5h13.5A2.25 2.25 0 0 1 21 6.75v10.5m-18 0A2.25 2.25 0 0 0 5.25 18.75h13.5A2.25 2.25 0 0 0 21 16.5m-18 0L7 12.5" /></svg>
                    <p class="font-semibold">{{ $product->name }}</p>
                </div>
            @endif
        </div>
        @if($images->count() > 1)
        <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
            @foreach($images as $img)
            <button type="button" onclick="document.getElementById('productMainImage').src='{{ $img }}'"
                class="shrink-0 w-20 h-20 rounded-lg overflow-hidden border-2 border-gray-200 hover:border-brand-400 transition-colors focus:outline-none focus:border-brand-500">
                <img src="{{ $img }}" alt="" class="w-full h-full object-cover">
            </button>
            @endforeach
        </div>
        @endif
    </div>

    {{-- ── Details ──────────────────────────────────────────────────────── --}}
    <div>
        @if($catLabel)
        <a href="{{ route('shop.index', ['category' => $product->productCategory?->slug ?? $product->category]) }}"
           class="text-xs text-brand-600 uppercase tracking-wider font-semibold hover:text-brand-700">{{ $catLabel }}</a>
        @endif
        <h1 class="text-3xl font-bold text-gray-900 mt-2 mb-3">{{ $product->name }}</h1>

        @if($product->short_description)
        <p class="text-gray-600 mb-5">{{ $product->short_description }}</p>
        @endif

        @if($variant !== null)
        <div class="flex items-baseline flex-wrap gap-3 mb-3">
            <span class="text-3xl font-bold text-gray-900">{{ $variant->displayPrice() }}</span>
            @if($variant->hasDiscount())
            <span class="text-lg text-gray-400 line-through">{{ $variant->displayMrp() }}</span>
            <span class="text-sm font-semibold text-green-700 bg-green-50 px-2.5 py-1 rounded">{{ $variant->discountPercent() }}% off</span>
            @endif
            @if($variant->bv_paise > 0 && $distributor)
            {{-- BV shown ONLY to logged-in distributors — a factual point value
                 used by the compensation plan, never an earnings projection.
                 Hidden from anonymous/customer visitors to avoid implying income
                 from a purchase (DSR Rule 5(1)(d) / hard rule #3). --}}
            <span class="text-sm font-semibold text-brand-700 bg-brand-50 border border-brand-200 px-2.5 py-1 rounded" title="Business Volume — points used in the compensation plan">
                {{ number_format($variant->bv_paise / 100, 0) }} BV
            </span>
            @endif
        </div>

        @if($distributor && $variant->hasDistributorPrice())
        {{-- After-login distributor price tier — a factual catalogue price for
             distributors, shown only once authenticated. Not an earnings figure. --}}
        <div class="flex items-baseline gap-2 mb-3 -mt-1">
            <span class="text-xs font-semibold text-emerald-700 uppercase tracking-wide">Distributor price</span>
            <span class="text-xl font-bold text-emerald-700">{{ $variant->displayDistributorPrice() }}</span>
        </div>
        @endif

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

        @if($myAdn)
        {{-- Easy Purchase: a distributor can share this product with a customer.
             The ?ref link sets the 30-day attribution cookie (CaptureAttribution
             middleware) so the customer's purchase is credited to this
             distributor. No income is shown or implied here. --}}
        @php $shareUrl = route('shop.product', ['slug' => $product->slug]).'?ref='.$myAdn; @endphp
        <div class="mb-8 p-4 rounded-xl border border-dashed border-brand-300 bg-brand-50/40">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
                <span class="text-sm font-semibold text-gray-800">Easy Purchase — share with a customer</span>
            </div>
            <p class="text-xs text-gray-500 mb-3">Send this link. Purchases made through it for the next 30 days are attributed to you (ADN {{ $myAdn }}).</p>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ $shareUrl }}" id="shareUrlInput"
                    class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-mono text-gray-600 focus:outline-none focus:ring-2 focus:ring-brand-500">
                <button type="button"
                    onclick="const b=this; navigator.clipboard.writeText(document.getElementById('shareUrlInput').value).then(function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy link';},1500);})"
                    class="shrink-0 px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold transition-colors">Copy link</button>
            </div>
        </div>
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

        {{-- ── Product facts ─────────────────────────────────────────────── --}}
        <div class="mt-8 pt-6 border-t border-gray-200">
            <h2 class="font-semibold text-gray-900 mb-3">Product facts</h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @if($product->manufacturer)
                    <tr><td class="py-2 pr-4 text-gray-500 font-medium">Manufacturer</td><td class="py-2 text-gray-900">{{ $product->manufacturer }}</td></tr>
                    @endif
                    @if($product->country_of_origin)
                    <tr><td class="py-2 pr-4 text-gray-500 font-medium">Country of origin</td><td class="py-2 text-gray-900">{{ $product->country_of_origin }}</td></tr>
                    @endif
                    @if($variant && $variant->weight_g > 0)
                    <tr><td class="py-2 pr-4 text-gray-500 font-medium">Net weight</td><td class="py-2 text-gray-900">{{ $variant->weight_g }} g</td></tr>
                    @endif
                    <tr><td class="py-2 pr-4 text-gray-500 font-medium">HSN code</td><td class="py-2 text-gray-900 font-mono">{{ $product->hsn_code }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── Product information sections (rich, sorted attributes) ───────────── --}}
@if($product->productAttributes->isNotEmpty())
<div class="mt-12 pt-8 border-t border-gray-200">
    <h2 class="text-xl font-bold text-gray-900 mb-6">Product information</h2>
    <div class="space-y-8 max-w-3xl">
        @foreach($product->productAttributes as $attr)
        <div>
            @if(trim((string) $attr->label) !== '')
            <h3 class="text-base font-semibold text-gray-900 mb-2">{{ $attr->label }}</h3>
            @endif
            {{-- value_html was HTMLPurifier-sanitised (the `products` profile)
                 at write time, so it is safe to render verbatim (may include
                 a nutritional-facts table or inline image). --}}
            <div class="prose prose-sm max-w-none text-gray-700 leading-relaxed
                        prose-table:w-full prose-table:text-sm prose-th:bg-gray-50 prose-th:text-left
                        prose-th:px-3 prose-th:py-2 prose-td:px-3 prose-td:py-2 prose-td:border prose-th:border prose-td:border-gray-200 prose-th:border-gray-200">{!! $attr->value_html !!}</div>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ── Full-width WYSIWYG description ────────────────────────────────────── --}}
@if($product->description_html || $product->description)
<div class="mt-12 pt-8 border-t border-gray-200">
    <h2 class="text-xl font-bold text-gray-900 mb-4">Product details</h2>
    @if($product->description_html)
        {{-- Stored already HTMLPurifier-sanitized (the `products` profile) at
             write time, so it is safe to render. --}}
        <div class="prose prose-sm max-w-none text-gray-700 leading-relaxed">{!! $product->description_html !!}</div>
    @else
        <p class="text-sm text-gray-600 leading-relaxed">{{ $product->description }}</p>
    @endif
</div>
@endif

@endsection
