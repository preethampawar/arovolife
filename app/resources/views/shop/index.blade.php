@extends('layouts.shop')
@section('title', 'Shop')

@section('content')

@if(($banners ?? collect())->isNotEmpty())
{{-- Shopping-mall carousel (admin-managed banners, recommended 1520×350). --}}
<section class="relative mb-8 rounded-3xl overflow-hidden shadow-sm" data-carousel>
    <div class="relative aspect-[1520/350] bg-gray-100">
        @foreach($banners as $i => $b)
        <a href="{{ $b->link_url ?: '' }}" @if(! $b->link_url) onclick="return false;" @endif
           data-slide="{{ $i }}"
           class="absolute inset-0 transition-opacity duration-700 {{ $i === 0 ? 'opacity-100' : 'opacity-0 pointer-events-none' }}">
            <img src="{{ $b->url() }}" alt="{{ $b->title }}" class="w-full h-full object-cover">
            @if($b->title || $b->caption)
            <div class="absolute inset-0 flex flex-col justify-center px-8 md:px-14 bg-gradient-to-r from-black/35 via-black/10 to-transparent">
                @if($b->title)<h2 class="text-2xl md:text-4xl font-bold text-white drop-shadow-md">{{ $b->title }}</h2>@endif
                @if($b->caption)<p class="mt-2 text-sm md:text-base text-white/90 max-w-md drop-shadow">{{ $b->caption }}</p>@endif
            </div>
            @endif
        </a>
        @endforeach
    </div>
    @if($banners->count() > 1)
    <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-2" data-dots>
        @foreach($banners as $i => $b)
        <button type="button" data-dot="{{ $i }}" aria-label="Show slide {{ $i + 1 }}"
            class="w-2.5 h-2.5 rounded-full transition-colors {{ $i === 0 ? 'bg-white' : 'bg-white/50 hover:bg-white/80' }}"></button>
        @endforeach
    </div>
    @endif
</section>
<script>
(function () {
    var root = document.querySelector('[data-carousel]');
    if (!root) return;
    var slides = root.querySelectorAll('[data-slide]');
    var dots = root.querySelectorAll('[data-dot]');
    if (slides.length < 2) return;
    var i = 0;
    function show(n) {
        i = (n + slides.length) % slides.length;
        slides.forEach(function (s, k) {
            var on = k === i;
            s.style.opacity = on ? '1' : '0';
            s.style.pointerEvents = on ? '' : 'none';
        });
        dots.forEach(function (d, k) { d.className = d.className.replace(/bg-white(\/\d+)?( hover:bg-white\/80)?/, '') + (k === i ? ' bg-white' : ' bg-white/50 hover:bg-white/80'); });
    }
    dots.forEach(function (d, k) { d.addEventListener('click', function () { show(k); reset(); }); });
    var timer = setInterval(function () { show(i + 1); }, 5000);
    function reset() { clearInterval(timer); timer = setInterval(function () { show(i + 1); }, 5000); }
})();
</script>
@else
{{-- Hero band — multi-tint gradient + floating accent blobs (shown when no banners) --}}
<section class="relative mb-8 rounded-3xl overflow-hidden p-8 md:p-12">
    <div class="absolute inset-0 bg-gradient-to-br from-brand-50 via-leaf-50 to-sunrise-50"></div>
    <div class="absolute -top-16 -right-12 w-72 h-72 bg-brand-200/50 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-16 -left-12 w-64 h-64 bg-leaf-200/50 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute top-1/2 left-1/3 w-48 h-48 bg-sunrise-200/40 rounded-full blur-3xl pointer-events-none"></div>

    <div class="relative grid md:grid-cols-[1fr_auto] items-center gap-6">
        <div>
            <p class="text-xs font-semibold text-brand-600 uppercase tracking-wider mb-2">arovolife shopping mall</p>
            <h1 class="text-3xl md:text-4xl font-bold text-gray-900 leading-tight mb-3">
                Quality essentials, <span class="text-leaf-600">delivered honestly</span>.
            </h1>
            <p class="text-gray-700 mb-5 max-w-xl">A small, curated range of nutraceuticals and personal care — every label transparent, every batch certified.</p>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white text-leaf-700 text-xs font-semibold border border-leaf-200 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    30-day returns
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white text-brand-700 text-xs font-semibold border border-brand-200 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    GST invoice
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white text-sunrise-700 text-xs font-semibold border border-sunrise-200 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    Free shipping ₹{{ number_format($freeShippingThresholdRupees ?? 4000) }}+
                </span>
            </div>
        </div>
        {{-- Hero stat card --}}
        <div class="hidden md:flex flex-col items-center justify-center bg-white rounded-2xl border border-gray-200 shadow-xl p-5 min-w-[180px]">
            <span class="text-5xl">🛍</span>
            <p class="mt-2 text-sm text-gray-700 font-semibold text-center">Direct from arovolife — no resellers</p>
        </div>
    </div>
</section>
@endif

{{-- Selected-category banner (Atomy-style, recommended 1280×290). --}}
@if(($activeCategory ?? null) && $activeCategory->bannerUrl())
<section class="mb-8 rounded-2xl overflow-hidden border border-gray-200 shadow-sm">
    <img src="{{ $activeCategory->bannerUrl() }}" alt="{{ $activeCategory->name }}" class="w-full aspect-[1280/290] object-cover">
</section>
@endif

{{-- Category pill row — colour-cycled --}}
@php
    // Tone cycle for category pills + product card accents.
    $catTones = [
        ['active' => 'bg-leaf-500 text-white border-leaf-500',       'idle' => 'bg-leaf-50 text-leaf-700 border-leaf-200 hover:bg-leaf-100'],
        ['active' => 'bg-brand-500 text-white border-brand-500',     'idle' => 'bg-brand-50 text-brand-700 border-brand-200 hover:bg-brand-100'],
        ['active' => 'bg-sunrise-500 text-white border-sunrise-500', 'idle' => 'bg-sunrise-50 text-sunrise-700 border-sunrise-200 hover:bg-sunrise-100'],
        ['active' => 'bg-violet-500 text-white border-violet-500',   'idle' => 'bg-violet-50 text-violet-700 border-violet-200 hover:bg-violet-100'],
    ];
@endphp
@if($categories->isNotEmpty())
{{-- Mobile & tablet (< lg): a single dropdown — no more sideways scrolling to
     switch category. Navigates on change. --}}
<div class="lg:hidden mb-6">
    <label for="categorySelect" class="sr-only">Choose a category</label>
    <select id="categorySelect" onchange="if(this.value){window.location.href=this.value;}"
        class="w-full rounded-full border-2 border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-500">
        <option value="{{ route('shop.index') }}" @selected(($activeSlug ?? null) === null)>All products</option>
        @foreach($categories as $cat)
            <option value="{{ route('shop.index', ['category' => $cat->slug]) }}" @selected(($activeSlug ?? null) === $cat->slug)>{{ $cat->name }}</option>
        @endforeach
    </select>
</div>

{{-- lg and up: the colour-cycled pill row. --}}
<div class="hidden lg:flex items-center gap-2 mb-8 overflow-x-auto pb-1">
    <a href="{{ route('shop.index') }}"
       class="shrink-0 px-4 py-2 rounded-full text-sm font-semibold border-2 transition-colors
       {{ ($activeSlug ?? null) === null ? 'bg-gradient-to-r from-brand-500 to-brand-600 text-white border-brand-600 shadow-md shadow-brand-500/30' : 'bg-white border-gray-200 text-gray-700 hover:border-brand-500' }}">
        All products
    </a>
    @foreach($categories as $i => $cat)
        @php $tone = $catTones[$i % count($catTones)]; @endphp
        <a href="{{ route('shop.index', ['category' => $cat->slug]) }}"
           class="shrink-0 px-4 py-2 rounded-full text-sm font-semibold border-2 transition-colors
           {{ ($activeSlug ?? null) === $cat->slug ? $tone['active'].' shadow-md' : $tone['idle'] }}">
            {{ $cat->name }}
        </a>
    @endforeach
</div>
@endif

{{-- Product grid — each card cycles through brand/leaf/sunrise/violet tints --}}
@php
    $cardTones = [
        ['gradient' => 'from-leaf-100 to-leaf-50',       'iconColor' => 'text-leaf-500',    'badgeBg' => 'bg-leaf-100',    'badgeTxt' => 'text-leaf-700',    'borderHover' => 'hover:border-leaf-400',    'shadow' => 'hover:shadow-leaf-500/20'],
        ['gradient' => 'from-brand-100 to-brand-50',     'iconColor' => 'text-brand-500',   'badgeBg' => 'bg-brand-100',   'badgeTxt' => 'text-brand-700',   'borderHover' => 'hover:border-brand-400',   'shadow' => 'hover:shadow-brand-500/20'],
        ['gradient' => 'from-sunrise-100 to-sunrise-50', 'iconColor' => 'text-sunrise-500', 'badgeBg' => 'bg-sunrise-100', 'badgeTxt' => 'text-sunrise-700', 'borderHover' => 'hover:border-sunrise-400', 'shadow' => 'hover:shadow-sunrise-500/20'],
        ['gradient' => 'from-violet-100 to-violet-50',   'iconColor' => 'text-violet-500',  'badgeBg' => 'bg-violet-100',  'badgeTxt' => 'text-violet-700',  'borderHover' => 'hover:border-violet-400',  'shadow' => 'hover:shadow-violet-500/20'],
    ];
    $shownIndex = 0;
@endphp
<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5">
    @foreach($products as $product)
        @php $variant = $product->primaryVariant(); @endphp
        @if($variant === null) @continue @endif
        @php
            $tone = $cardTones[$shownIndex % count($cardTones)]; $shownIndex++;
            $cardImage = $product->galleryImages->first()?->url() ?? $product->image_url;
            $catLabel = $product->productCategory?->name ?? ($product->category ? str_replace('-', ' ', $product->category) : null);
        @endphp
        <a href="{{ route('shop.product', $product->slug) }}"
           class="bg-white rounded-2xl border-2 border-gray-200 {{ $tone['borderHover'] }} overflow-hidden shadow-sm hover:shadow-xl {{ $tone['shadow'] }} hover:-translate-y-1 transition-all duration-300 group flex flex-col">
            <div class="relative aspect-square bg-gradient-to-br {{ $tone['gradient'] }} flex items-center justify-center overflow-hidden">
                @if($cardImage)
                    <img src="{{ $cardImage }}" alt="{{ $product->name }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
                @else
                    <div class="text-center {{ $tone['iconColor'] }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-20 h-20 mx-auto mb-2 opacity-70" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75 7.41 11.59c.8-.8 2.1-.8 2.9 0l4.56 4.56m-1.5-1.5 1.66-1.66c.8-.8 2.1-.8 2.9 0l2.83 2.83M3 16.5V6.75A2.25 2.25 0 0 1 5.25 4.5h13.5A2.25 2.25 0 0 1 21 6.75v10.5m-18 0A2.25 2.25 0 0 0 5.25 18.75h13.5A2.25 2.25 0 0 0 21 16.5m-18 0L7 12.5"/>
                        </svg>
                    </div>
                @endif
                @if($variant->hasDiscount())
                    <span class="absolute top-3 left-3 inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider bg-red-500 text-white shadow-md">
                        −{{ $variant->discountPercent() }}%
                    </span>
                @endif
                @if($catLabel)
                    <span class="absolute top-3 right-3 inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold uppercase tracking-wider {{ $tone['badgeBg'] }} {{ $tone['badgeTxt'] }} backdrop-blur-sm shadow-sm">
                        {{ $catLabel }}
                    </span>
                @endif
            </div>
            <div class="p-4 flex-1 flex flex-col">
                <h3 class="font-semibold text-gray-900 group-hover:text-brand-700 transition-colors leading-snug flex items-start gap-1.5">
                    <x-veg-mark :type="$product->food_type" size="sm" class="mt-0.5" />
                    <span>{{ $product->name }}</span>
                </h3>
                @if($product->short_description)
                    <p class="text-xs text-gray-500 mt-1 line-clamp-2">{{ $product->short_description }}</p>
                @endif
                <div class="flex items-baseline gap-2 mt-3 pt-3 border-t border-gray-100 mt-auto">
                    <span class="text-lg font-bold text-gray-900">{{ $variant->displayPrice() }}</span>
                    @if($variant->hasDiscount())
                        <span class="text-sm text-gray-400 line-through">{{ $variant->displayMrp() }}</span>
                    @endif
                </div>
                {{-- BV shown only to logged-in distributors — a factual point value
                     for the compensation plan, never an earnings figure
                     (DSR Rule 5(1)(d) / hard rule #3). Mirrors the product page. --}}
                @auth
                    @if(auth()->user()->distributor && $variant && $variant->bv_paise > 0)
                    <div class="mt-2">
                        <span class="inline-block text-xs font-semibold text-brand-700 bg-brand-50 border border-brand-200 px-2 py-0.5 rounded" title="Business Volume — points used in the compensation plan">{{ number_format($variant->bv_paise / 100, 0) }} BV</span>
                    </div>
                    @endif
                @endauth
            </div>
        </a>
    @endforeach
</div>

@if($products->isEmpty())
<div class="bg-white rounded-2xl border-2 border-dashed border-gray-300 p-12 text-center text-gray-500">
    <span class="text-5xl block mb-3 opacity-50">🛒</span>
    No products available yet — check back soon.
</div>
@endif

@endsection
