@extends('layouts.shop')
@section('title', 'Shop')

{{-- Full-bleed banner: rendered in the layout's `banner` slot so it spans the
     whole viewport width and sits flush under the header (no side gutters, no
     gap). The hero fallback stays centered in the normal content container. --}}
@section('banner')
@if(($activeCategory ?? null))
{{-- Category page: show this category's banners (sliding); the shopping-mall
     carousel is hidden. Falls back to the legacy single category banner. --}}
@if(($categoryBanners ?? collect())->isNotEmpty())
    @include('partials._banner-carousel', ['slides' => $categoryBanners, 'aspectClass' => 'aspect-[1520/350]', 'wrapperClass' => ''])
@elseif($activeCategory->bannerUrl())
<section class="relative overflow-hidden">
    <img src="{{ $activeCategory->bannerUrl() }}" alt="{{ $activeCategory->name }}" class="w-full aspect-[1520/350] object-cover bg-gray-100">
</section>
@endif
@elseif(($banners ?? collect())->isNotEmpty())
{{-- Shopping-mall carousel (admin-managed banners, recommended 1520×350). --}}
@include('partials._banner-carousel', ['slides' => $banners, 'aspectClass' => 'aspect-[1520/350]', 'wrapperClass' => ''])
@else
{{-- Hero band — multi-tint gradient + floating accent blobs (shown when no
     banners). Kept centered in the normal content container (no full bleed). --}}
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 sm:pt-8">
<section class="relative rounded-3xl overflow-hidden p-8 md:p-12">
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
</div>
@endif
@endsection

@section('content')

@php
    // Whether THIS page renders a sliding carousel (mall on home, or the
    // category's banners on a category page) — drives the slider script below.
    $hasCarousel = ($activeCategory ?? null)
        ? ($categoryBanners ?? collect())->isNotEmpty()
        : ($banners ?? collect())->isNotEmpty();
@endphp

{{-- Atomy-style slider driver — initialises every banner carousel on the page
     (mall or category). Right-to-left auto-advance, seamless loop, pause-on-hover. --}}
@if($hasCarousel)
<script>
(function () {
    document.querySelectorAll('[data-carousel]').forEach(function (root) {
        var track = root.querySelector('[data-track]');
        var slides = track ? Array.prototype.slice.call(track.querySelectorAll('[data-slide]')) : [];
        var dots = root.querySelectorAll('[data-dot]');
        var n = slides.length;
        if (!track || n < 2) return;

        var SPEED = 700, INTERVAL = 4000;
        // Clone the first slide onto the end for a seamless right-to-left loop.
        var clone = slides[0].cloneNode(true);
        clone.removeAttribute('data-slide');
        track.appendChild(clone);

        var i = 0, animating = false;
        function setDot(active) {
            dots.forEach(function (d, k) {
                d.className = 'w-2.5 h-2.5 rounded-full transition-colors ' + (k === active ? 'bg-white' : 'bg-white/50 hover:bg-white/80');
            });
        }
        function go(to) {
            i = to;
            track.style.transition = 'transform ' + SPEED + 'ms ease-out';
            track.style.transform = 'translateX(' + (-i * 100) + '%)';
            setDot(i % n);
        }
        track.addEventListener('transitionend', function () {
            if (i === n) { track.style.transition = 'none'; i = 0; track.style.transform = 'translateX(0)'; void track.offsetWidth; }
            animating = false;
        });
        function next() { if (!animating) { animating = true; go(i + 1); } }
        var timer = setInterval(next, INTERVAL);
        function reset() { clearInterval(timer); timer = setInterval(next, INTERVAL); }
        dots.forEach(function (d, k) { d.addEventListener('click', function () { if (!animating) { animating = true; go(k); reset(); } }); });
        root.addEventListener('mouseenter', function () { clearInterval(timer); });
        root.addEventListener('mouseleave', reset);
    });
})();
</script>
@endif

{{-- Category filter pills --}}
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

{{-- lg and up: a clean, consistent filter-pill row. One brand-filled active
     pill; the rest are neutral outlined pills that tint on hover. --}}
@php
    $pillBase   = 'shrink-0 px-4 py-2 rounded-full text-sm font-medium border transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-brand-500/40';
    $pillActive = 'bg-brand-500 text-white border-brand-500 shadow-sm shadow-brand-500/30';
    $pillIdle   = 'bg-white text-gray-600 border-gray-200 hover:border-brand-300 hover:text-brand-700 hover:bg-brand-50';
@endphp
<div class="hidden lg:flex items-center gap-2 mb-8 overflow-x-auto pb-1">
    <a href="{{ route('shop.index') }}"
       class="{{ $pillBase }} {{ ($activeSlug ?? null) === null ? $pillActive : $pillIdle }}">
        All products
    </a>
    @foreach($categories as $cat)
        <a href="{{ route('shop.index', ['category' => $cat->slug]) }}"
           class="{{ $pillBase }} {{ ($activeSlug ?? null) === $cat->slug ? $pillActive : $pillIdle }}">
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
    $toneFor = fn (int $i) => $cardTones[$i % count($cardTones)];
@endphp
@if(($activeCategory ?? null) === null && ($productsByCategory ?? collect())->isNotEmpty())
    {{-- "All products" view: products grouped into per-category sections, up to
         5 each, with a "View all" link to the full category page. --}}
    @php $cardIdx = 0; @endphp
    @foreach($productsByCategory as $group)
        <section class="mb-10">
            <div class="flex items-end justify-between gap-3 mb-4">
                <h2 class="text-lg font-bold text-gray-900">{{ $group['category']->name }}</h2>
                <a href="{{ route('shop.index', ['category' => $group['category']->slug]) }}"
                   class="text-sm font-medium text-brand-600 hover:text-brand-700 whitespace-nowrap">View all →</a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                @foreach($group['products'] as $product)
                    @include('partials._product-card', ['product' => $product, 'tone' => $toneFor($cardIdx)])
                    @php $cardIdx++; @endphp
                @endforeach
            </div>
        </section>
    @endforeach
@else
    {{-- Single-category page (or fallback): a flat grid of all matching products. --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
        @foreach($products as $i => $product)
            @include('partials._product-card', ['product' => $product, 'tone' => $toneFor($i)])
        @endforeach
    </div>

    @if($products->isEmpty())
    <div class="bg-white rounded-2xl border-2 border-dashed border-gray-300 p-12 text-center text-gray-500">
        <span class="text-5xl block mb-3 opacity-50">🛒</span>
        No products available yet — check back soon.
    </div>
    @endif
@endif

{{-- Add to cart from a listing card without leaving the page: AJAX add →
     toast + cart-badge bump. Falls back to a normal submit if JS is off. --}}
<script>
(function () {
    document.querySelectorAll('form[data-add-to-cart]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (data) {
                if (window.showToast) { window.showToast(data.message || 'Product successfully added to cart.', 'success'); }
                document.querySelectorAll('[data-cart-count]').forEach(function (el) {
                    var c = data.count || 0;
                    el.textContent = c > 99 ? '99+' : c;
                    el.classList.toggle('hidden', c <= 0);
                });
            })
            .catch(function () {
                if (window.showToast) { window.showToast('Could not add to cart. Please try again.', 'error'); }
                else { form.submit(); }
            })
            .finally(function () { if (btn) { btn.disabled = false; } });
        });
    });
})();
</script>

@endsection
