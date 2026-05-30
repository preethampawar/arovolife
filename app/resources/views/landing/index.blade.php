<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>arovolife — Direct Selling, Done Right</title>
    <meta name="description" content="arovolife is a direct-selling company compliant with India's DSR 2021. Free to register, 30-day cooling-off, no income projections.">
    @vite(['resources/css/app.css'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    {{-- Hero banner --}}
    {{--
        Layout: a single 2-column grid. The LEFT column is a stack of text
        slides that cross-fade (opacity-only) between rotations. The RIGHT
        column hosts the persistent orbit animation; only the column's
        background gradient changes per slide so the graphic itself never
        translates or rebuilds.
    --}}
    <section id="hero-slider" class="relative overflow-hidden" aria-roledescription="carousel" aria-label="arovolife hero">
        {{-- Soft brand-tinted blobs sit on top of the body's wizard-stage
             grid pattern. The opaque gradient layer that used to cover the
             hero was hiding the grid; removed so the body backdrop shows. --}}
        <div class="absolute -top-20 -right-20 w-[500px] h-[500px] bg-brand-200/40 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-20 -left-20 w-[400px] h-[400px] bg-sunrise-100/40 rounded-full blur-3xl pointer-events-none"></div>

        {{-- Slides --}}
        @php
            $slides = [
                [
                    'eyebrow' => 'Direct Selling, Done Right',
                    'title_plain' => 'Start Your Direct Selling Journey with',
                    'title_accent' => 'arovolife',
                    'body' => 'Free to register. 30-day cooling-off with one-click cancellation. Fully compliant with India\'s Consumer Protection (Direct Selling) Rules, 2021.',
                    'cta_primary' => ['label' => 'Become a Direct Seller →', 'url' => route('contact.show')],
                    'cta_secondary' => ['label' => 'How It Works', 'url' => '#how-it-works'],
                    'note' => 'Registration is free. No payment required to sign up.',
                ],
                [
                    'eyebrow' => 'arovolife Shopping Mall',
                    'title_plain' => 'Quality Essentials',
                    'title_accent' => 'Delivered to Your Door',
                    'body' => 'Browse our curated range of personal care, health and food products — responsibly sourced, independently quality-checked, and backed by a GST invoice on every order.',
                    'cta_primary' => ['label' => 'Shop Now →', 'url' => route('shop.index')],
                    'cta_secondary' => ['label' => 'Browse Categories', 'url' => route('shop.index')],
                    'note' => '30-day return window on every order.',
                ],
                [
                    'eyebrow' => 'Compliance-First',
                    'title_plain' => 'Your Trust,',
                    'title_accent' => 'Our Guarantee',
                    'body' => 'DSR 2021 compliant. DPDP 2023 data protection. Audit-logged transactions. Raw Aadhaar never stored. Every commission tied to a real product sale.',
                    'cta_primary' => ['label' => 'Read Our Commitment →', 'url' => route('content.show', 'ethics')],
                    'cta_secondary' => ['label' => 'Privacy Policy', 'url' => route('content.show', 'privacy')],
                    'note' => 'Complaint SLA: 24h acknowledgement, 7-day resolution.',
                ],
            ];
        @endphp

        {{-- Two-column hero. LEFT: rotating text stack. RIGHT: fixed orbit
             with per-slide tinted backdrop. --}}
        <div class="relative max-w-7xl mx-auto px-6 py-20 md:py-28 grid md:grid-cols-2 items-center gap-10">

            {{-- LEFT: stacked text slides, only one visible at a time. --}}
            <div class="hero-text-stack relative" data-hero-stack
                 aria-live="polite" aria-atomic="true">
                @foreach($slides as $i => $s)
                <div data-slide-index="{{ $i }}"
                     data-active="{{ $i === 0 ? 'true' : 'false' }}"
                     role="group" aria-roledescription="slide" aria-label="Slide {{ $i + 1 }} of {{ count($slides) }}"
                     @if($i !== 0) inert @endif
                     class="hero-slide-text">
                    <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">{{ $s['eyebrow'] }}</p>
                    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 leading-tight mb-5">
                        {{ $s['title_plain'] }}
                        @if($s['title_accent'])
                        <span class="text-brand-600">{{ $s['title_accent'] }}</span>
                        @endif
                    </h1>
                    <p class="text-lg text-gray-800 mb-8 max-w-lg">{{ $s['body'] }}</p>
                    <div class="flex flex-wrap items-center gap-4">
                        <a href="{{ $s['cta_primary']['url'] }}"
                           class="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors shadow-lg shadow-brand-500/30">
                            {{ $s['cta_primary']['label'] }}
                        </a>
                        <a href="{{ $s['cta_secondary']['url'] }}"
                           class="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-white border border-gray-300 hover:border-brand-500 text-gray-700 hover:text-brand-800 text-sm font-semibold transition-colors">
                            {{ $s['cta_secondary']['label'] }}
                        </a>
                    </div>
                    <p class="text-sm text-gray-700 mt-5">{{ $s['note'] }}</p>
                </div>
                @endforeach
            </div>

            {{-- RIGHT: persistent animation. The dark brand-600 colour
                 is applied to the circular orbit itself (.hero-circle),
                 not the surrounding rectangle, so the panel reads as a
                 disc against the page rather than as a card. Ring /
                 halo / glow stay light so they read on the dark blue. --}}
            <div class="hero-animation hidden md:flex justify-center items-center"
                 aria-hidden="true">
                <div class="hero-circle relative w-80 h-80">
                    {{-- Soft blue halo (largest blur, sits behind everything) —
                         creates the diffuse blue ring around the white disc
                         that you see in the reference image. --}}
                    <div class="hero-glow absolute -inset-4 bg-brand-400/60 rounded-full blur-3xl"></div>

                    {{-- Tighter blue halo gradient — concentrates the colour
                         right at the rim of the white disc. --}}
                    <div class="hero-halo absolute inset-2 bg-gradient-to-br from-brand-300 to-brand-500 rounded-full opacity-50 blur-md"></div>

                    {{-- Outer light-brand-blue disc — sits inside the ripple
                         zone (inset-4 = 288px). brand-300 (#5fd2f8) at
                         85% alpha so the soft halo behind bleeds through
                         subtly. The animated rings emanate outward from
                         its edge; the inner white disc nests inside it. --}}
                    <div class="absolute inset-4 bg-brand-300/85 rounded-full shadow-xl shadow-brand-700/25"></div>

                    {{-- Ripple — 3 rings × 4s cycle × 1.33s stagger.
                         Exactly 3 visible at any moment (each ring's
                         full lifetime spans 3 stagger windows), and the
                         opacity fades linearly across the full lifetime
                         so rings don't linger as ghost outlines.
                         Colours: gold + violet + brand-blue (deep navy
                         was too dark against the light brand-300 disc;
                         brand-500 #00b6ef ties back to the platform
                         primary). --}}
                    <div class="hero-ring hero-ring-1 absolute inset-0 rounded-full border-2" style="border-color: rgba(212, 160, 23, 0.5);"></div>
                    <div class="hero-ring hero-ring-2 absolute inset-0 rounded-full border-2" style="border-color: rgba(124, 94, 189, 0.5);"></div>
                    <div class="hero-ring hero-ring-3 absolute inset-0 rounded-full border-2" style="border-color: rgba(0, 182, 239, 0.5);"></div>

                    {{-- Inner WHITE disc with the blue arovolife logo, nested
                         inside the blue outer disc. inset-10 = 240px container,
                         logo at w-56 = 224px → 8px breathing room. --}}
                    <div class="hero-logo-disc absolute inset-10 bg-white rounded-full shadow-2xl shadow-brand-900/40 flex items-center justify-center">
                        <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife" class="w-56 h-auto">
                    </div>

                    {{-- 5 floating dots around the ring — curcuma gold → deep blue gradient --}}
                    <span class="hero-spark hero-spark-1 absolute w-3 h-3 rounded-full"  style="background:#d4a017;color:#d4a017;"></span>
                    <span class="hero-spark hero-spark-2 absolute w-2.5 h-2.5 rounded-full" style="background:#e88e1a;color:#e88e1a;"></span>
                    <span class="hero-spark hero-spark-3 absolute w-2 h-2 rounded-full" style="background:#7c5ebd;color:#7c5ebd;"></span>
                    <span class="hero-spark hero-spark-4 absolute w-2.5 h-2.5 rounded-full" style="background:#1c80e3;color:#1c80e3;"></span>
                    <span class="hero-spark hero-spark-5 absolute w-3 h-3 rounded-full" style="background:#0b427a;color:#0b427a;"></span>
                </div>
            </div>
        </div>

        <style>
            /* Cross-fade stack: every text slide is absolutely positioned on
               top of the first one (which anchors the stack's height); only
               the active slide is opaque. */
            .hero-text-stack { min-height: 1px; }
            .hero-text-stack > .hero-slide-text {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 600ms ease-in-out, visibility 0s linear 600ms;
            }
            .hero-text-stack > .hero-slide-text[data-active="true"] {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
                transition: opacity 600ms ease-in-out, visibility 0s linear 0s;
                position: relative; /* the active slide defines stack height */
            }
            .hero-text-stack > .hero-slide-text:not([data-active="true"]) {
                position: absolute;
                inset: 0;
            }

            /* Animation panel — transparent rectangle; the dark brand-600
               (#008cc7) colour now lives on the circular orbit itself
               (.hero-circle) rather than the surrounding card. Padding
               reserves clearance around the 320px orbit so the
               5 spark dots don't clip at the panel edge. */
            .hero-animation {
                padding: 1.5rem;
            }

            /* Ripple rings — 3 rings × 4s cycle ÷ 1.33s stagger = 1 new
               ripple emitted every 1.33s, with exactly 3 rings alive at
               any moment (4s ÷ 1.33s ≈ 3 staggered slices). Opacity
               fades linearly from full to 0 across the full lifetime so
               rings exit cleanly rather than lingering as faint ghosts. */
            .hero-ring {
                animation: heroPulseRing 4s ease-out infinite;
                opacity: 0;
            }
            .hero-ring-1 { animation-delay: 0s;    }
            .hero-ring-2 { animation-delay: 1.33s; }
            .hero-ring-3 { animation-delay: 2.66s; }
            @keyframes heroPulseRing {
                0%   { transform: scale(1);    opacity: 0.9; }
                100% { transform: scale(1.45); opacity: 0;   }
            }

            /* Outer halo breathes */
            .hero-halo { animation: heroHaloBreathe 4s ease-in-out infinite; }
            @keyframes heroHaloBreathe {
                0%, 100% { opacity: 0.35; transform: scale(1); }
                50%      { opacity: 0.55; transform: scale(1.03); }
            }

            /* Behind-the-disc glow pulses */
            .hero-glow { animation: heroGlowPulse 3.5s ease-in-out infinite; }
            @keyframes heroGlowPulse {
                0%, 100% { opacity: 0.6; transform: scale(1); }
                50%      { opacity: 0.9; transform: scale(1.08); }
            }

            /* Inner white disc gently floats */
            .hero-logo-disc { animation: heroFloat 5s ease-in-out infinite; }
            @keyframes heroFloat {
                0%, 100% { transform: translateY(0); }
                50%      { transform: translateY(-8px); }
            }

            /* 5 sparks orbiting the ring — curcuma gold → deep blue, staggered 72° apart */
            .hero-spark {
                top: 50%;
                left: 50%;
                box-shadow: 0 0 14px currentColor;
                opacity: 0.9;
                animation: heroOrbit 16s linear infinite;
                transform-origin: center center;
            }
            .hero-spark-1 { animation-delay:   0s;   }  /*   0° — curcuma gold (#d4a017) */
            .hero-spark-2 { animation-delay:  -6.4s; }  /* 144° — amber (#e88e1a) — opposite curcuma */
            .hero-spark-3 { animation-delay:  -3.2s; }  /*  72° — violet bridge */
            .hero-spark-4 { animation-delay:  -9.6s; }  /* 216° — brand azure */
            .hero-spark-5 { animation-delay: -12.8s; }  /* 288° — deep blue */

            @keyframes heroOrbit {
                from { transform: translate(-50%, -50%) rotate(0deg)   translateX(160px); }
                to   { transform: translate(-50%, -50%) rotate(360deg) translateX(160px); }
            }

            /* Gently pop on hover */
            .hero-circle { transition: transform 400ms cubic-bezier(0.4, 0, 0.2, 1); }
            .hero-circle:hover { transform: scale(1.03); }

            @media (prefers-reduced-motion: reduce) {
                .hero-ring, .hero-halo, .hero-glow, .hero-logo-disc, .hero-spark { animation: none !important; }
                .hero-text-stack > .hero-slide-text { transition: none !important; }
            }
        </style>

        {{-- Prev / Next arrows --}}
        <button type="button" data-slider-prev aria-label="Previous slide"
                class="absolute left-4 md:left-8 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-white/80 hover:bg-white border border-gray-200 shadow-md flex items-center justify-center text-brand-700 hover:text-brand-900 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        </button>
        <button type="button" data-slider-next aria-label="Next slide"
                class="absolute right-4 md:right-8 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-white/80 hover:bg-white border border-gray-200 shadow-md flex items-center justify-center text-brand-700 hover:text-brand-900 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </button>

        {{-- Indicator dots --}}
        <div class="absolute bottom-6 right-6 flex items-center gap-3 text-brand-700 text-sm font-medium z-10">
            <span data-slider-counter>1/{{ count($slides) }}</span>
            <div class="flex items-center gap-1.5" data-slider-dots>
                @foreach($slides as $i => $s)
                <button type="button" data-hero-dot data-goto="{{ $i }}"
                        data-active="{{ $i === 0 ? 'true' : 'false' }}"
                        aria-label="Go to slide {{ $i + 1 }}"
                        class="hero-dot h-1.5 rounded-full transition-all {{ $i === 0 ? 'w-8 bg-brand-500' : 'w-2 bg-brand-300 hover:bg-brand-400' }}"></button>
                @endforeach
            </div>
        </div>
    </section>

    <script>
    (function() {
        const root = document.getElementById('hero-slider');
        if (!root) return;
        const stack = root.querySelector('[data-hero-stack]');
        const slides = stack ? stack.querySelectorAll('[data-slide-index]') : [];
        const dots = root.querySelectorAll('[data-hero-dot]');
        const counter = root.querySelector('[data-slider-counter]');
        const total = slides.length;
        if (!total) return;

        const TICK_MS = 5000;
        let idx = 0;
        let timer = null;

        function apply(next) {
            idx = ((next % total) + total) % total;

            slides.forEach((el, k) => {
                const active = k === idx;
                el.setAttribute('data-active', active ? 'true' : 'false');
                // `inert` keeps inactive (invisible) slides out of the tab
                // order and away from screen readers.
                if (active) {
                    el.removeAttribute('inert');
                } else {
                    el.setAttribute('inert', '');
                }
            });

            dots.forEach((dot, k) => {
                const active = k === idx;
                dot.setAttribute('data-active', active ? 'true' : 'false');
                dot.className = 'hero-dot h-1.5 rounded-full transition-all ' +
                    (active ? 'w-8 bg-brand-500' : 'w-2 bg-brand-300 hover:bg-brand-400');
            });

            if (counter) counter.textContent = (idx + 1) + '/' + total;
        }

        function next() { apply(idx + 1); }
        function prev() { apply(idx - 1); }

        function start() {
            stop();
            timer = setInterval(next, TICK_MS);
        }
        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        root.querySelector('[data-slider-prev]')?.addEventListener('click', () => { prev(); start(); });
        root.querySelector('[data-slider-next]')?.addEventListener('click', () => { next(); start(); });
        dots.forEach(dot => dot.addEventListener('click', () => {
            apply(Number(dot.getAttribute('data-goto')));
            start();
        }));

        // Pause autoplay on hover, resume on leave
        root.addEventListener('mouseenter', stop);
        root.addEventListener('mouseleave', start);

        // Keyboard arrows when section focused
        root.setAttribute('tabindex', '-1');
        root.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft')  { prev(); start(); }
            if (e.key === 'ArrowRight') { next(); start(); }
        });

        // Basic touch/swipe support (kept for parity with the previous UX
        // even though the visual no longer slides horizontally).
        let touchStartX = null;
        root.addEventListener('touchstart', (e) => {
            touchStartX = e.touches[0].clientX;
            stop();
        }, { passive: true });
        root.addEventListener('touchend', (e) => {
            if (touchStartX === null) return;
            const dx = e.changedTouches[0].clientX - touchStartX;
            if (Math.abs(dx) > 50) { dx < 0 ? next() : prev(); }
            touchStartX = null;
            start();
        }, { passive: true });

        start();
    })();
    </script>

    {{-- Trust pillar icon row (replaces Atomy's product category row) --}}
    <section class="bg-white border-y border-gray-100 py-10">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-6">
                @php
                    $pillars = [
                        ['label' => 'Free to Register',   'tone' => 'brand',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />'],
                        ['label' => '30-Day Cooling-Off', 'tone' => 'sky',    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />'],
                        ['label' => 'Mandatory Orientation','tone'=>'violet','icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />'],
                        ['label' => 'DPDP-Compliant',     'tone' => 'green',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />'],
                        ['label' => 'One PAN One ID',     'tone' => 'amber',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" />'],
                        ['label' => 'Audit-Logged',       'tone' => 'slate',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />'],
                        ['label' => 'Grievance SLA',      'tone' => 'red',    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />'],
                        ['label' => 'Direct Selling Only','tone' => 'brand',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 5.25 9m-3 3 3 3m-3-3h19.5m-3-3 3 3m-3 3 3-3" />'],
                    ];
                    $tones = [
                        'brand'  => 'bg-brand-50 text-brand-600',
                        'sky'    => 'bg-sky-50 text-sky-600',
                        'violet' => 'bg-violet-50 text-violet-600',
                        'green'  => 'bg-green-50 text-green-600',
                        'amber'  => 'bg-amber-50 text-amber-600',
                        'slate'  => 'bg-slate-100 text-slate-600',
                        'red'    => 'bg-red-50 text-red-600',
                    ];
                @endphp
                @foreach($pillars as $p)
                <div class="flex flex-col items-center text-center gap-2">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center {{ $tones[$p['tone']] }}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="w-7 h-7">
                            {!! $p['icon'] !!}
                        </svg>
                    </div>
                    <span class="text-sm text-gray-700 font-medium leading-tight">{{ $p['label'] }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Why arovolife — colour-cycled cards with gradient backdrop --}}
    <section class="relative py-16 overflow-hidden">
        <div class="absolute -top-24 -left-24 w-[400px] h-[400px] bg-leaf-200/40 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -right-24 w-[400px] h-[400px] bg-sunrise-200/40 rounded-full blur-3xl pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-6 relative">
            <div class="text-center mb-10">
                <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-2">Why arovolife</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Compliant by design — <span class="text-brand-600">customer-first</span> by belief.</h2>
                <p class="text-gray-800">Four promises, every transaction, every day.</p>
            </div>

            @php
                // Heroicons outline SVG paths. Stored as path-data only so
                // currentColor stroke picks up the iconBg's text colour.
                $iconGift     = '<path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>';
                $iconShield   = '<path stroke-linecap="round" stroke-linejoin="round" d="m9 12.75 2.25 2.25 6-6m-3.75 12c4.142 0 7.5-3.358 7.5-7.5 0-2.343-1.07-4.44-2.756-5.812L13.5 2.25 6.506 7.688A7.46 7.46 0 0 0 3.75 13.5c0 4.142 3.358 7.5 7.5 7.5Z"/>';
                $iconClock    = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>';
                $iconRupee    = '<path stroke-linecap="round" stroke-linejoin="round" d="M15 8.25H9m6 3H9m3 6-3-3h1.5a3 3 0 1 0 0-6M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>';

                $whyCards = [
                    ['title' => 'Free Registration',    'body' => 'Zero joining fee. No payment required at signup — ever.',                      'icon' => $iconGift,   'bg' => 'bg-brand-50',   'border' => 'border-brand-200',   'iconBg' => 'bg-brand-100 text-brand-700',     'titleClr' => 'text-brand-700'],
                    ['title' => 'Your Data, Protected', 'body' => 'PAN stored as hash. Raw Aadhaar never touches our database. Full audit log.', 'icon' => $iconShield, 'bg' => 'bg-violet-50',  'border' => 'border-violet-200',  'iconBg' => 'bg-violet-100 text-violet-700',   'titleClr' => 'text-violet-700'],
                    ['title' => '30-Day Cooling-Off',   'body' => 'One-click cancellation with full refund during the cooling-off period.',      'icon' => $iconClock,  'bg' => 'bg-sunrise-50', 'border' => 'border-sunrise-200', 'iconBg' => 'bg-sunrise-100 text-sunrise-700', 'titleClr' => 'text-sunrise-700'],
                    ['title' => 'Real Sales Earnings',  'body' => 'Commissions are paid on actual product sales, never on recruiting alone.',    'icon' => $iconRupee,  'bg' => 'bg-leaf-50',    'border' => 'border-leaf-200',    'iconBg' => 'bg-leaf-100 text-leaf-700',       'titleClr' => 'text-leaf-700'],
                ];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach($whyCards as $card)
                <div class="rounded-2xl border-2 {{ $card['border'] }} {{ $card['bg'] }} p-6 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center {{ $card['iconBg'] }} mb-4 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6" aria-hidden="true">
                            {!! $card['icon'] !!}
                        </svg>
                    </div>
                    <h3 class="font-bold {{ $card['titleClr'] }} mb-1.5">{{ $card['title'] }}</h3>
                    <p class="text-sm text-gray-700 leading-relaxed">{{ $card['body'] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- How it works — colour-cycled steps --}}
    <section id="how-it-works" class="relative py-16 overflow-hidden">
        <div class="absolute top-1/2 -left-32 -translate-y-1/2 w-[300px] h-[300px] bg-brand-100/60 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute top-1/2 -right-32 -translate-y-1/2 w-[300px] h-[300px] bg-leaf-100/60 rounded-full blur-3xl pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-6 relative">
            <div class="text-center mb-12">
                <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-2">How to register</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Five quick steps. <span class="text-leaf-600">Fifteen minutes</span> start to finish.</h2>
                <p class="text-gray-800">From referral link to live ADN — no surprises along the way.</p>
            </div>

            @php
                // Heroicons outline SVG paths. Stroke = currentColor =
                // white (the colored circle has `text-white`).
                $iconUsers    = '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>';
                $iconUserPlus = '<path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/>';
                $iconPlay     = '<path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>';
                $iconId       = '<path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z"/>';
                $iconBadge    = '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>';

                $steps = [
                    ['n' => '1', 'title' => 'Placement',      'body' => 'Confirm your sponsor + group.',        'bg' => 'bg-brand-500',   'shadow' => 'shadow-brand-500/30',   'icon' => $iconUsers],
                    ['n' => '2', 'title' => 'Create Account', 'body' => 'Name, email, phone, password.',        'bg' => 'bg-leaf-500',    'shadow' => 'shadow-leaf-500/30',    'icon' => $iconUserPlus],
                    ['n' => '3', 'title' => 'Orientation',    'body' => 'Watch the video, pass the quiz.',      'bg' => 'bg-sunrise-500', 'shadow' => 'shadow-sunrise-500/30', 'icon' => $iconPlay],
                    ['n' => '4', 'title' => 'KYC',            'body' => 'PAN + Aadhaar (verified gateway).',    'bg' => 'bg-violet-500',  'shadow' => 'shadow-violet-500/30',  'icon' => $iconId],
                    ['n' => '5', 'title' => 'Get Your ADN',   'body' => 'Distributor Number issued instantly.', 'bg' => 'bg-brand-700',   'shadow' => 'shadow-brand-700/30',   'icon' => $iconBadge],
                ];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                @foreach($steps as $step)
                <div class="relative bg-white rounded-2xl border border-gray-200 p-5 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                    {{-- Step-number badge sits in the top-right corner so
                         the sequence stays visible alongside the icon. --}}
                    <span class="absolute top-3 right-3 text-sm font-semibold text-gray-400">{{ $step['n'] }}</span>
                    <div class="w-10 h-10 rounded-full {{ $step['bg'] }} text-white flex items-center justify-center mb-3 shadow-lg {{ $step['shadow'] }}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5" aria-hidden="true">
                            {!! $step['icon'] !!}
                        </svg>
                    </div>
                    <h4 class="font-semibold text-gray-900 mb-1 text-sm">{{ $step['title'] }}</h4>
                    <p class="text-sm text-gray-800 leading-relaxed">{{ $step['body'] }}</p>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-10">
                <a href="{{ route('contact.show') }}"
                   class="inline-flex items-center gap-2 px-8 py-3 rounded-full bg-gradient-to-r from-brand-500 via-brand-600 to-brand-700 hover:from-brand-600 hover:to-brand-800 text-white text-sm font-semibold transition-all shadow-lg shadow-brand-500/40 hover:shadow-xl hover:shadow-brand-500/50">
                    Talk to our team →
                </a>
                <p class="mt-3 text-sm text-gray-700">
                    Registration is by personal referral only — leave your details and we'll connect you with a sponsor.
                </p>
            </div>
        </div>
    </section>

    {{-- Products preview — subtle, lightly-tinted category teasers --}}
    <section class="relative py-16 overflow-hidden">
        <div class="absolute top-0 left-1/3 w-[400px] h-[400px] bg-leaf-100/50 rounded-full blur-3xl pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-6 relative">
            <div class="text-center mb-10 max-w-2xl mx-auto">
                <p class="text-sm font-medium text-leaf-600 uppercase tracking-wider mb-2">Our products</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Best-in-class. <span class="text-leaf-600">Best for life.</span></h2>
                <p class="text-gray-800">A small range, deeply considered — wellness essentials and personal care that stand on their own quality.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @php
                    // Heroicons outline paths. Stroke = currentColor, which the
                    // icon chip sets per-category (a soft tinted icon on a light card).
                    $iconHeart    = '<path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.099 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>';
                    $iconSparkles = '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/>';
                    $iconHome     = '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>';
                    $iconSun      = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/>';
                    $iconLeaf     = '<path stroke-linecap="round" stroke-linejoin="round" d="M21 3s-6.75-.9-11.25 3.6S4.5 19.5 4.5 19.5m0 0s9 1.05 13.5-3.45S21 3 21 3zM4.5 19.5 12 12"/>';
                    // Comb — reads as hair / beauty grooming.
                    $iconComb     = '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 7.5h16.5a0 0 0 0 1 0 0v3.75a5.25 5.25 0 0 1-5.25 5.25h-6A5.25 5.25 0 0 1 3.75 11.25V7.5zM7.5 16.5v3.75M12 16.5v3.75M16.5 16.5v3.75"/>';

                    // Six categories, each with a distinct hue across the
                    // brand + Tailwind palette so no two cards share a colour
                    // family. Each card is a SUBTLE light tint (bg-*-50 with a
                    // *-100 border) rather than a saturated gradient — the hue
                    // shows through the icon chip, eyebrow and link instead.
                    // Explicit class strings (no interpolation) so Tailwind's
                    // JIT compiler can see and emit every utility.
                    $categories = [
                        [
                            'title' => 'Health Care',
                            'subtitle' => 'Evidence-led wellness',
                            'body' => 'Daily supplements, immunity blends, and Ayurveda-inspired formulations. Every milligram declared on the label, every batch independently tested.',
                            'card' => 'bg-leaf-50 border-leaf-100 hover:border-leaf-200',
                            'iconChip' => 'bg-leaf-100 text-leaf-600',
                            'accent' => 'text-leaf-700',
                            'icon' => $iconHeart,
                        ],
                        [
                            'title' => 'Skin and Beauty',
                            'subtitle' => 'Radiance, responsibly made',
                            'body' => 'Cleansers, serums, and treatments formulated for Indian skin. Paraben-free, cruelty-free, dermatologically reviewed before launch.',
                            'card' => 'bg-rose-50 border-rose-100 hover:border-rose-200',
                            'iconChip' => 'bg-rose-100 text-rose-600',
                            'accent' => 'text-rose-700',
                            'icon' => $iconSparkles,
                        ],
                        [
                            'title' => 'Personal Care',
                            'subtitle' => 'Daily essentials, done honestly',
                            'body' => 'Toothpaste, soaps, deodorants, and body wash — clean ingredient lists, no hidden fragrances, no surprise SLS.',
                            'card' => 'bg-brand-50 border-brand-100 hover:border-brand-200',
                            'iconChip' => 'bg-brand-100 text-brand-600',
                            'accent' => 'text-brand-700',
                            'icon' => $iconComb,
                        ],
                        [
                            'title' => 'Home Care',
                            'subtitle' => 'A home that breathes clean',
                            'body' => 'Plant-based dishwash, laundry, and surface cleaners. Tough on grime, gentle on hands, biodegradable at the drain.',
                            'card' => 'bg-teal-50 border-teal-100 hover:border-teal-200',
                            'iconChip' => 'bg-teal-100 text-teal-600',
                            'accent' => 'text-teal-700',
                            'icon' => $iconHome,
                        ],
                        [
                            'title' => 'Agri Care',
                            'subtitle' => 'Rooted in healthier soil',
                            'body' => 'Organic fertilisers, bio-pesticides, and soil conditioners for stronger crops. Plant nutrition that works with the land, not against it.',
                            'card' => 'bg-amber-50 border-amber-100 hover:border-amber-200',
                            'iconChip' => 'bg-amber-100 text-amber-600',
                            'accent' => 'text-amber-700',
                            'icon' => $iconLeaf,
                        ],
                        [
                            'title' => 'Lifestyle',
                            'subtitle' => 'Wellness, beyond the bottle',
                            'body' => 'Curated bundles, wellness journals, and lifestyle accessories — the everyday companions that turn a routine into a habit.',
                            'card' => 'bg-violet-50 border-violet-100 hover:border-violet-200',
                            'iconChip' => 'bg-violet-100 text-violet-600',
                            'accent' => 'text-violet-700',
                            'icon' => $iconSun,
                        ],
                    ];
                @endphp
                @foreach($categories as $cat)
                <a href="{{ route('shop.index') }}" class="group relative rounded-3xl overflow-hidden border {{ $cat['card'] }} p-7 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300 block">
                    <div class="relative">
                        <div class="mb-3 inline-flex items-center justify-center w-14 h-14 rounded-2xl {{ $cat['iconChip'] }}">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-7 h-7" aria-hidden="true">
                                {!! $cat['icon'] !!}
                            </svg>
                        </div>
                        <p class="text-[11px] uppercase tracking-wider {{ $cat['accent'] }} font-semibold mb-1">{{ $cat['subtitle'] }}</p>
                        <h3 class="text-2xl font-bold mb-3 leading-tight text-gray-900">{{ $cat['title'] }}</h3>
                        <p class="text-sm text-gray-600 leading-relaxed mb-5">{{ $cat['body'] }}</p>
                        <span class="inline-flex items-center gap-1.5 text-sm font-semibold {{ $cat['accent'] }} group-hover:translate-x-1 transition-transform">
                            Browse range
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                        </span>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Compliance commitment banner (replaces "Absolute Skincare set") --}}
    <section class="relative bg-gradient-to-br from-brand-600 via-brand-500 to-brand-700 text-white overflow-hidden">
        <div class="absolute -top-20 -right-20 w-[400px] h-[400px] bg-white/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-20 -left-20 w-[350px] h-[350px] bg-sunrise-400/20 rounded-full blur-3xl"></div>

        <div class="relative max-w-7xl mx-auto px-6 py-16 md:py-20">
            <div class="text-center mb-10">
                <h2 class="text-3xl md:text-4xl font-bold mb-2">Our Compliance Commitment</h2>
                <p class="text-brand-50">Every promise is backed by code and audit.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
                @foreach([
                    ['DSR 2021',     'Direct Selling Rules compliant'],
                    ['DPDP 2023',    'Digital Personal Data Protection Act'],
                    ['IT Act §10A',  'Electronic contracts as binding'],
                    ['Audit Trail',  'Every admin action logged'],
                ] as $item)
                <div class="bg-white/10 backdrop-blur rounded-xl border border-white/20 p-5">
                    <p class="text-sm uppercase tracking-wider text-brand-100 mb-1 font-medium">Statute</p>
                    <p class="font-bold text-lg">{{ $item[0] }}</p>
                    <p class="text-sm text-brand-50 mt-1">{{ $item[1] }}</p>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-10">
                <a href="{{ route('content.show', 'terms') }}"
                   class="inline-flex items-center gap-2 px-6 py-2.5 rounded-full bg-white text-brand-700 hover:bg-brand-50 text-sm font-semibold transition-colors">
                    Read the Direct Seller Agreement →
                </a>
            </div>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="bg-gray-900 text-gray-400 py-12">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-10">
                <div>
                    <img src="{{ asset('assets/arovolife-logos/arovolife-white-logo.png') }}" alt="arovolife" class="h-12 w-auto mb-3">
                    <p class="text-sm leading-relaxed mb-4">
                        Arovolife Private Limited — a direct-selling company incorporated in India.
                        CIN U46909TS2026PTC210896.
                    </p>
                    <h4 class="text-white text-sm font-semibold mb-2">Customer Care</h4>
                    <ul class="space-y-1.5 text-sm">
                        <li>
                            <a href="tel:+918886662949" class="hover:text-white">+91 88866 62949</a>
                        </li>
                        <li>
                            <a href="mailto:support@arovolife.com" class="hover:text-white">support@arovolife.com</a>
                        </li>
                        <li class="text-gray-500 leading-relaxed">
                            9:30 am – 5:30 pm, every day<br>
                            except Sundays &amp; public holidays
                        </li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white text-sm font-semibold mb-3">Company</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="{{ route('about') }}" class="hover:text-white">About arovolife</a></li>
                        <li><a href="{{ route('content.show', 'ethics') }}" class="hover:text-white">Code of Ethics</a></li>
                        <li><a href="#how-it-works" class="hover:text-white">How It Works</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white text-sm font-semibold mb-3">Legal</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="{{ route('content.show', 'terms') }}" class="hover:text-white">Direct Seller Agreement</a></li>
                        <li><a href="{{ route('content.show', 'privacy') }}" class="hover:text-white">Privacy Policy</a></li>
                        <li><a href="{{ route('content.show', 'grievance') }}" class="hover:text-white">Grievance Redressal</a></li>
                        <li><a href="{{ route('compliance-documents.index') }}" class="hover:text-white">Compliance Documents</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white text-sm font-semibold mb-3">Get Started</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="{{ route('contact.show') }}" class="hover:text-white">Become a Direct Seller</a></li>
                        <li><a href="{{ route('login') }}" class="hover:text-white">Sign In</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 pt-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-3 text-sm">
                <p>&copy; {{ date('Y') }} Arovolife Private Limited. All rights reserved.</p>
                <p class="text-gray-500">
                    <strong class="text-gray-400">Registration is free.</strong> No payment required at signup.
                </p>
            </div>
        </div>
    </footer>

</body>
</html>
