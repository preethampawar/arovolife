<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>arovolife — Direct Selling, Done Right</title>
    @include('partials._favicons')
    <meta name="description" content="arovolife is a direct-selling company compliant with India's DSR 2021. Free to register, 30-day cooling-off, no income projections.">
    @vite(['resources/css/app.css'])
    @include('partials._theme-fouc')
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
                    'body' => 'Begin your journey with arovolife through a simple, transparent and responsible direct-selling platform designed around product knowledge, genuine customer service and long-term personal growth. Free to register. 30-day cooling-off with one-click cancellation. Built to comply with India\'s Consumer Protection (Direct Selling) Rules, 2021.',
                    'cta_primary' => ['label' => 'Become an arovolife Direct Seller →', 'url' => route('contact.show')],
                    'cta_secondary' => ['label' => 'How It Works', 'url' => '#how-it-works'],
                    'note' => 'Registration is free. No payment is required to sign up. Eligibility and participation are subject to applicable company policies and direct-selling guidelines.',
                ],
                [
                    'eyebrow' => 'arovolife',
                    'title_plain' => 'Quality Essentials',
                    'title_accent' => 'Delivered to Your Door',
                    'body' => 'Browse our curated range of personal care, health and food products — responsibly sourced, independently quality-checked, and backed by an invoice on every order.',
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
                    'note' => 'Complaints acknowledged within 48 hours, with root-cause analysis within 14 days.',
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
                    <p class="text-sm font-medium text-brand-700 uppercase tracking-wider mb-3">{{ $s['eyebrow'] }}</p>
                    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 leading-tight mb-5">
                        {{ $s['title_plain'] }}
                        @if($s['title_accent'])
                        <span class="text-brand-700">{{ $s['title_accent'] }}</span>
                        @endif
                    </h1>
                    <p class="text-lg text-gray-800 mb-8 max-w-lg">{{ $s['body'] }}</p>
                    <div class="flex flex-wrap items-center gap-4">
                        <a href="{{ $s['cta_primary']['url'] }}"
                           class="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors shadow-lg shadow-brand-500/30">
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

        {{-- Prev / Next arrows. Hidden on phones: vertically centred on the
             slide, they sat on top of the body copy and ate characters at both
             edges (QA F37). Touch users swipe, and the dots below still move
             between slides. --}}
        <button type="button" data-slider-prev aria-label="Previous slide"
                class="hidden md:flex absolute left-4 md:left-8 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-white/80 hover:bg-white border border-gray-200 shadow-md items-center justify-center text-brand-700 hover:text-brand-900 transition-colors">
            <x-lucide-chevron-left class="w-5 h-5" />
        </button>
        <button type="button" data-slider-next aria-label="Next slide"
                class="hidden md:flex absolute right-4 md:right-8 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-white/80 hover:bg-white border border-gray-200 shadow-md items-center justify-center text-brand-700 hover:text-brand-900 transition-colors">
            <x-lucide-chevron-right class="w-5 h-5" />
        </button>

        {{-- Indicator dots --}}
        <div class="absolute bottom-6 right-6 flex items-center gap-3 text-brand-700 text-sm font-medium z-10">
            <span data-slider-counter>1/{{ count($slides) }}</span>
            <div class="flex items-center gap-1.5" data-slider-dots>
                @foreach($slides as $i => $s)
                <button type="button" data-hero-dot data-goto="{{ $i }}"
                        data-active="{{ $i === 0 ? 'true' : 'false' }}"
                        aria-label="Go to slide {{ $i + 1 }}"
                        class="hero-dot h-1.5 rounded-full transition-all {{ $i === 0 ? 'w-8 bg-brand-700' : 'w-2 bg-brand-300 hover:bg-brand-400' }}"></button>
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
                    (active ? 'w-8 bg-brand-700' : 'w-2 bg-brand-300 hover:bg-brand-400');
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
                        ['label' => 'Free to Register',   'tone' => 'brand',  'icon' => 'trending-up'],
                        ['label' => '30-Day Cooling-Off', 'tone' => 'sky',    'icon' => 'clock'],
                        ['label' => 'Mandatory Orientation','tone'=>'violet','icon' => 'graduation-cap'],
                        ['label' => 'DPDP-Compliant',     'tone' => 'green',  'icon' => 'circle-check'],
                        ['label' => 'One PAN One ID',     'tone' => 'amber',  'icon' => 'id-card'],
                        ['label' => 'Audit-Logged',       'tone' => 'slate',  'icon' => 'file-text'],
                        ['label' => 'Grievance SLA',      'tone' => 'red',    'icon' => 'messages-square'],
                        ['label' => 'Direct Selling Only','tone' => 'brand',  'icon' => 'arrow-right-left'],
                    ];
                    $tones = [
                        'brand'  => 'bg-brand-50 text-brand-700',
                        'sky'    => 'bg-sky-50 text-sky-600',
                        'violet' => 'bg-violet-50 text-violet-600',
                        'green'  => 'bg-green-50 text-green-600',
                        'amber'  => 'bg-amber-50 text-amber-700',
                        'slate'  => 'bg-slate-100 text-slate-600',
                        'red'    => 'bg-red-50 text-red-600',
                    ];
                @endphp
                @foreach($pillars as $p)
                <div class="flex flex-col items-center text-center gap-2">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center {{ $tones[$p['tone']] }}">
                        {{ svg('lucide-'.$p['icon'], 'w-7 h-7') }}
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
                <p class="text-sm font-medium text-brand-700 uppercase tracking-wider mb-2">Why arovolife</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Compliant by design — <span class="text-brand-700">customer-first</span> by belief.</h2>
                <p class="text-lg text-gray-700 font-semibold mb-3">Purpose at Heart. People at the Centre. Progress with Integrity.</p>
                <div class="max-w-3xl mx-auto space-y-3 mb-3">
                    <p class="text-gray-800">arovolife was created with a simple belief: when people receive quality products, genuine value and the right opportunity to grow, better lives can be built step by step.</p>
                    <p class="text-gray-800">We bring together wellness, responsible entrepreneurship and personal development through a transparent, customer-focused platform designed for long-term relationships—not short-term promises.</p>
                </div>
                <p class="text-gray-800">Six promises, every transaction, every day.</p>
            </div>

            @php
                // Heroicons outline SVG paths. Stored as path-data only so
                // currentColor stroke picks up the iconBg's text colour.
                $iconGift     = 'gift';
                $iconShield   = 'shield-check';
                $iconClock    = 'clock';
                $iconRupee    = 'indian-rupee';
                $iconHandHeart = 'hand-heart';
                $iconGradCap  = 'graduation-cap';

                $whyCards = [
                    ['title' => 'Free Registration',    'body' => 'Zero joining fee. No payment required at signup — ever. We believe opportunity should start with accessibility, clarity and informed choice.', 'icon' => $iconGift,   'bg' => 'bg-brand-50',   'border' => 'border-brand-200',   'iconBg' => 'bg-brand-100 text-brand-700',     'titleClr' => 'text-brand-700'],
                    ['title' => 'Your Data, Protected', 'body' => 'PAN stored as hash. Raw Aadhaar never touches our database. Full audit log.', 'icon' => $iconShield, 'bg' => 'bg-violet-50',  'border' => 'border-violet-200',  'iconBg' => 'bg-violet-100 text-violet-700',   'titleClr' => 'text-violet-700'],
                    ['title' => '30-Day Cooling-Off',   'body' => 'One-click cancellation with full refund during the cooling-off period. A defined cooling-off period gives new direct sellers time to understand the opportunity and make an informed decision.', 'icon' => $iconClock,  'bg' => 'bg-sunrise-50', 'border' => 'border-sunrise-200', 'iconBg' => 'bg-sunrise-100 text-sunrise-700', 'titleClr' => 'text-sunrise-800'],
                    ['title' => 'Real Sales Earnings',  'body' => 'Commissions are paid on actual product sales, never on recruiting. Business rewards are connected to genuine product sales under the published compensation plan—never to recruitment.', 'icon' => $iconRupee,  'bg' => 'bg-leaf-50',    'border' => 'border-leaf-200',    'iconBg' => 'bg-leaf-100 text-leaf-700',       'titleClr' => 'text-leaf-700'],
                    ['title' => 'Customer-First Approach', 'body' => 'Every relationship begins with genuine product value, responsible recommendations and dependable service.', 'icon' => $iconHandHeart, 'bg' => 'bg-sky-50', 'border' => 'border-sky-200', 'iconBg' => 'bg-sky-100 text-sky-700', 'titleClr' => 'text-sky-700'],
                    ['title' => 'Learning & Leadership', 'body' => 'Product knowledge, business skills, mentoring and continuous development help individuals progress with greater confidence.', 'icon' => $iconGradCap, 'bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'iconBg' => 'bg-amber-100 text-amber-700', 'titleClr' => 'text-amber-700'],
                ];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($whyCards as $card)
                <div class="rounded-2xl border-2 {{ $card['border'] }} {{ $card['bg'] }} p-6 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center {{ $card['iconBg'] }} mb-4 shadow-sm">
                        {{ svg('lucide-'.$card['icon'], 'w-6 h-6') }}
                    </div>
                    <h3 class="font-bold {{ $card['titleClr'] }} mb-1.5">{{ $card['title'] }}</h3>
                    <p class="text-sm text-gray-700 leading-relaxed">{{ $card['body'] }}</p>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-10 max-w-3xl mx-auto">
                <p class="text-lg text-gray-700 font-semibold mb-2">The arovolife Promise</p>
                <p class="text-gray-800">To create meaningful value for customers, responsible opportunities for direct sellers, and a culture where health, integrity and shared progress come together.</p>
            </div>
        </div>
    </section>

    {{-- How it works — colour-cycled steps --}}
    <section id="how-it-works" class="relative py-16 overflow-hidden">
        <div class="absolute top-1/2 -left-32 -translate-y-1/2 w-[300px] h-[300px] bg-brand-100/60 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute top-1/2 -right-32 -translate-y-1/2 w-[300px] h-[300px] bg-leaf-100/60 rounded-full blur-3xl pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-6 relative">
            <div class="text-center mb-12">
                <p class="text-sm font-medium text-brand-700 uppercase tracking-wider mb-2">How to register</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Five quick steps. <span class="text-leaf-600">Fifteen minutes</span> start to finish.</h2>
                <p class="text-lg text-gray-700 font-semibold mb-3">Simple to Start. Clear at Every Step.</p>
                <p class="text-gray-800 max-w-3xl mx-auto mb-3">Joining arovolife is designed to be easy, transparent and convenient. With a simple referral-based registration process, new applicants can complete the essential steps, verify their details and begin their arovolife journey with confidence.</p>
                <p class="text-gray-800">From referral link to live ADN — no surprises along the way.</p>
            </div>

            @php
                // Heroicons outline SVG paths. Stroke = currentColor =
                // white (the colored circle has `text-white`).
                $iconUsers    = 'users';
                $iconUserPlus = 'user-plus';
                $iconPlay     = 'play';
                $iconId       = 'id-card';
                $iconBadge    = 'badge-check';

                $steps = [
                    ['n' => '1', 'title' => 'Placement',      'body' => 'Confirm your sponsor + group. Begin through an authorised arovolife referral link and confirm your sponsor and placement details before proceeding.', 'bg' => 'bg-brand-700',   'shadow' => 'shadow-brand-500/30',   'icon' => $iconUsers],
                    ['n' => '2', 'title' => 'Create Account', 'body' => 'Name, email, phone, password. Enter your basic information such as name, mobile number, email address and secure password to create your account.', 'bg' => 'bg-leaf-500',    'shadow' => 'shadow-leaf-500/30',    'icon' => $iconUserPlus],
                    ['n' => '3', 'title' => 'Orientation',    'body' => 'Watch the video, pass the quiz. Review the company information, products, policies and business model so you can make an informed and confident decision before continuing.',      'bg' => 'bg-sunrise-500', 'shadow' => 'shadow-sunrise-500/30', 'icon' => $iconPlay],
                    ['n' => '4', 'title' => 'KYC',            'body' => 'PAN + Aadhaar (verified gateway). Submit the required KYC information, including PAN and Aadhaar details, through the approved verification process.', 'bg' => 'bg-violet-500',  'shadow' => 'shadow-violet-500/30',  'icon' => $iconId],
                    ['n' => '5', 'title' => 'Get Your ADN',   'body' => 'After successful registration and applicable verification, your arovolife Direct Seller ID/ADN is issued, giving you access to your account and business platform.', 'bg' => 'bg-brand-700',   'shadow' => 'shadow-brand-700/30',   'icon' => $iconBadge],
                ];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                @foreach($steps as $step)
                <div class="relative bg-white rounded-2xl border border-gray-200 p-5 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                    {{-- Step-number badge sits in the top-right corner so
                         the sequence stays visible alongside the icon. --}}
                    <span class="absolute top-3 right-3 text-sm font-semibold text-gray-600">{{ $step['n'] }}</span>
                    <div class="w-10 h-10 rounded-full {{ $step['bg'] }} text-white flex items-center justify-center mb-3 shadow-lg {{ $step['shadow'] }}">
                        {{ svg('lucide-'.$step['icon'], 'w-5 h-5') }}
                    </div>
                    <h4 class="font-semibold text-gray-900 mb-1 text-sm">{{ $step['title'] }}</h4>
                    <p class="text-sm text-gray-800 leading-relaxed">{{ $step['body'] }}</p>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-10">
                <div class="max-w-3xl mx-auto mb-6">
                    <p class="text-lg text-gray-700 font-semibold mb-2">Your Journey Begins with Clarity</p>
                    <p class="text-gray-800 mb-2">Registration is the first step—not the finish line. Take time to understand the products, learn the system, follow responsible selling practices and build your business through genuine customer value.</p>
                    <p class="text-sm text-gray-700">Free Registration • Simple Process • Secure Verification • Transparent Onboarding</p>
                </div>
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
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2"><span class="text-brand-700">Best-in-class.</span> <span class="text-leaf-600">Best for life.</span></h2>
                <p class="text-gray-800 mb-3">A small range, deeply considered — wellness essentials and personal care that stand on their own quality.</p>
                <p class="text-gray-800 mb-3">At arovolife, our products are created with one clear purpose: to bring meaningful value to everyday life. We focus on categories that support health, self-care, cleaner living and better lifestyles—with quality, responsibility and trust at the centre of everything we offer.</p>
                <p class="text-gray-800 mb-3">Our approach is simple: develop and deliver products that are useful, reliable and thoughtfully chosen for modern families and everyday needs.</p>
                <p class="text-lg text-gray-700 font-semibold">A Thoughtful Product Range for Better Living</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @php
                    // Heroicons outline paths. Stroke = currentColor, which the
                    // icon chip sets per-category (a soft tinted icon on a light card).
                    $iconHeart    = 'heart';
                    $iconSparkles = 'sparkles';
                    $iconHome     = 'house';
                    $iconSun      = 'sun';
                    $iconLeaf     = 'leaf';
                    // Comb — reads as hair / beauty grooming.
                    $iconComb     = 'brush';

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
                            'body' => 'Wellness that begins from within. Our health care range is designed to support everyday well-being through carefully selected wellness and nutritional solutions. With a focus on quality, consistency and trust, these products are created to help people take a more confident step toward healthier living. Daily supplements, immunity blends, and Ayurveda-inspired formulations. Every milligram declared on the label, every batch independently tested.',
                            'card' => 'bg-leaf-50 border-leaf-100 hover:border-leaf-200',
                            'iconChip' => 'bg-leaf-100 text-leaf-600',
                            'accent' => 'text-leaf-700',
                            'icon' => $iconHeart,
                        ],
                        [
                            'title' => 'Skin and Beauty',
                            'subtitle' => 'Radiance, responsibly made',
                            'body' => 'Care that reflects confidence and comfort. Our skin and beauty range is designed to support personal care with products that promote freshness, care and daily confidence. We believe beauty begins with well-cared-for skin and a routine built on quality and consistency. Cleansers, serums, and treatments formulated for Indian skin. Paraben-free, cruelty-free, dermatologically reviewed before launch.',
                            'card' => 'bg-rose-50 border-rose-100 hover:border-rose-200',
                            'iconChip' => 'bg-rose-100 text-rose-600',
                            'accent' => 'text-rose-700',
                            'icon' => $iconSparkles,
                        ],
                        [
                            'title' => 'Personal Care',
                            'subtitle' => 'Daily essentials, done honestly',
                            'body' => 'Daily essentials made with purpose. From everyday hygiene to self-care basics, our personal care products are chosen to support comfort, cleanliness and confidence in daily life. Practical, dependable and thoughtfully developed, they are made to become part of a healthy lifestyle. Toothpaste, soaps, deodorants, and body wash — clean ingredient lists, no hidden fragrances, no surprise SLS.',
                            'card' => 'bg-brand-50 border-brand-100 hover:border-brand-200',
                            'iconChip' => 'bg-brand-100 text-brand-700',
                            'accent' => 'text-brand-700',
                            'icon' => $iconComb,
                        ],
                        [
                            'title' => 'Home Care',
                            'subtitle' => 'A home that breathes clean',
                            'body' => 'Clean living starts at home. A healthy lifestyle also depends on a clean and cared-for environment. Our home care range is intended to support everyday cleanliness and convenience, helping families maintain spaces that feel fresh, safe and comfortable. Plant-based dishwash, laundry, and surface cleaners. Tough on grime, gentle on hands, biodegradable at the drain.',
                            'card' => 'bg-teal-50 border-teal-100 hover:border-teal-200',
                            'iconChip' => 'bg-teal-100 text-teal-600',
                            'accent' => 'text-teal-700',
                            'icon' => $iconHome,
                        ],
                        [
                            'title' => 'Agri Care',
                            'subtitle' => 'Rooted in healthier soil',
                            'body' => 'Supporting better growth from the ground up. Our agri care range reflects our broader vision of well-being by recognising the importance of healthy cultivation and sustainable support systems. These products are aimed at contributing to better agricultural care and long-term value. Organic fertilisers, bio-pesticides, and soil conditioners for stronger crops. Plant nutrition that works with the land, not against it.',
                            'card' => 'bg-amber-50 border-amber-100 hover:border-amber-200',
                            'iconChip' => 'bg-amber-100 text-amber-700',
                            'accent' => 'text-amber-700',
                            'icon' => $iconLeaf,
                        ],
                        [
                            'title' => 'Lifestyle',
                            'subtitle' => 'Wellness, beyond the bottle',
                            'body' => 'Products that fit the way people live today. Our lifestyle range is built around everyday practicality, usefulness and enhancement of daily routines. It reflects our belief that better living is created not only through health care, but also through smart, purposeful lifestyle choices. Curated bundles, wellness journals, and lifestyle accessories — the everyday companions that turn a routine into a habit.',
                            'card' => 'bg-violet-50 border-violet-100 hover:border-violet-200',
                            'iconChip' => 'bg-violet-100 text-violet-600',
                            'accent' => 'text-violet-700',
                            'icon' => $iconSun,
                        ],
                    ];
                @endphp
                @foreach($categories as $cat)
                <a href="{{ route('shop.index') }}" class="group relative rounded-3xl overflow-hidden border {{ $cat['card'] }} p-7 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300 flex flex-col h-full">
                    <div class="relative flex flex-col flex-1">
                        <div class="mb-3 inline-flex items-center justify-center w-14 h-14 rounded-2xl {{ $cat['iconChip'] }}">
                            {{ svg('lucide-'.$cat['icon'], 'w-7 h-7') }}
                        </div>
                        <p class="text-[11px] uppercase tracking-wider {{ $cat['accent'] }} font-semibold mb-1">{{ $cat['subtitle'] }}</p>
                        <h3 class="text-2xl font-bold mb-3 leading-tight text-gray-900">{{ $cat['title'] }}</h3>
                        <p class="text-sm text-gray-600 leading-relaxed mb-5">{{ $cat['body'] }}</p>
                        <span class="mt-auto self-start inline-flex items-center gap-1.5 text-sm font-semibold {{ $cat['accent'] }} group-hover:translate-x-1 transition-transform">
                            Browse range
                            <x-lucide-arrow-right class="w-4 h-4" />
                        </span>
                    </div>
                </a>
                @endforeach
            </div>

            <div class="grid md:grid-cols-2 gap-6 mt-12">
                <div class="rounded-3xl bg-white border border-gray-100 shadow-sm p-7">
                    <p class="text-lg text-gray-700 font-semibold mb-4">What makes arovolife products different?</p>
                    <ul class="space-y-3">
                        @foreach([
                            'Quality-Focused'  => 'Developed with attention to usefulness, consistency and trust.',
                            'People-Centred'   => 'Chosen to meet real everyday needs.',
                            'Wellness-Oriented' => 'Designed to support a better, healthier and more balanced life.',
                            'Value-Driven'     => 'Built around long-term satisfaction, not short-term appeal.',
                            'Purposeful Range' => 'A thoughtful mix of products across essential life categories.',
                        ] as $title => $body)
                        <li class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-leaf-100 text-leaf-700 flex items-center justify-center text-xs font-bold">✓</span>
                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ $title }}</p>
                                <p class="text-sm text-gray-600">{{ $body }}</p>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
                <div class="rounded-3xl bg-white border border-gray-100 shadow-sm p-7">
                    <p class="text-lg text-gray-700 font-semibold mb-4">Our product philosophy</p>
                    <p class="text-sm text-gray-600 mb-5 leading-relaxed">At arovolife, we believe products should do more than fill shelves—they should add value to lives. That is why our product philosophy is rooted in:</p>
                    <ul class="flex flex-wrap gap-2.5">
                        @foreach(['Usefulness', 'Quality', 'Trust', 'Everyday relevance', 'Long-term customer value'] as $title)
                        <li class="inline-flex items-center gap-2 rounded-full bg-leaf-50 border border-leaf-100 pl-1.5 pr-4 py-1.5">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-leaf-100 text-leaf-700 flex items-center justify-center text-xs font-bold">✓</span>
                            <span class="text-sm font-semibold text-gray-900">{{ $title }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="text-center mt-12 max-w-3xl mx-auto">
                <p class="text-gray-800 mb-2">arovolife products are created to support healthier choices, better living and everyday progress—because true quality is not just about what a product is, but how meaningfully it serves people.</p>
                <p class="text-lg text-gray-700 font-semibold">arovolife — Best in Class. Best for Life.</p>
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
                <p class="text-lg text-white font-semibold mb-3">Integrity in Every Action. Transparency in Every Relationship.</p>
                <p class="text-brand-50 mb-3">Every promise is backed by code and audit.</p>
                <div class="max-w-3xl mx-auto space-y-3">
                    <p class="text-brand-50">At arovolife, compliance is more than a legal requirement—it is a fundamental part of how we build trust, protect people and create a responsible direct selling ecosystem.</p>
                    <p class="text-brand-50">We are committed to conducting our business with clarity, accountability and respect for applicable laws, regulations and ethical standards. From registration and product communication to data protection, contracts and business practices, our goal is to ensure that every interaction is responsible and transparent.</p>
                </div>
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

            <p class="text-lg text-white font-semibold text-center mt-12 mb-5">Our Commitment to Responsible Business</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @foreach([
                    ['Direct Selling Rules',              'We strive to operate in alignment with the applicable Direct Selling framework, with a clear focus on genuine product sales, fair practices and responsible representation.'],
                    ['Data & Privacy Protection',         'We respect personal information and aim to handle customer and direct seller data responsibly, securely and in accordance with applicable privacy requirements.'],
                    ['Clear Agreements & Policies',       'Our direct seller relationship is supported by documented terms, policies and procedures designed to create clarity around rights, responsibilities and business conduct.'],
                    ['Transparent Records & Audit Trail', 'We maintain structured records and processes to support accountability, traceability and responsible administration across our business operations.'],
                    ['Ethical Product Communication',     'We encourage accurate, responsible and compliant product communication without misleading claims, exaggerated promises or inappropriate representations.'],
                    ['Continuous Review',                 'Compliance is an ongoing responsibility. We aim to review and strengthen our processes as laws, standards and business requirements evolve.'],
                ] as $item)
                <div class="bg-white/10 backdrop-blur rounded-xl border border-white/20 p-5">
                    <p class="text-sm uppercase tracking-wider text-brand-100 mb-1 font-medium">Commitment</p>
                    <p class="font-bold text-lg">{{ $item[0] }}</p>
                    <p class="text-sm text-brand-50 mt-1">{{ $item[1] }}</p>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-10 max-w-3xl mx-auto">
                <p class="text-lg text-white font-semibold mb-2">Our Promise</p>
                <p class="text-brand-50 mb-4">To build arovolife on a foundation of trust—where customers are respected, direct sellers are informed, business practices are transparent and every relationship is guided by integrity.</p>
                <p class="font-semibold text-white">arovolife — Trust Built by Principle. Progress Built with Responsibility.</p>
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
                        <li class="text-gray-400 leading-relaxed">
                            {{ config('arovolife.support_hours') }}
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
                        {{-- Guarded on publication status: a footer link to an
                             unpublished page is a link to a 404. The guard
                             stays even though these are published, so a page
                             taken offline never becomes a dead link. --}}
                        @if(\App\Modules\Content\Models\ContentPage::isSlugPublished('returns'))
                        <li><a href="{{ route('content.show', 'returns') }}" class="hover:text-white">Returns &amp; warranty</a></li>
                        @endif
                        @if(\App\Modules\Content\Models\ContentPage::isSlugPublished('shipping'))
                        <li><a href="{{ route('content.show', 'shipping') }}" class="hover:text-white">Shipping &amp; delivery</a></li>
                        @endif
                        @if(\App\Modules\Content\Models\ContentPage::isSlugPublished('disclaimer'))
                        <li><a href="{{ route('content.show', 'disclaimer') }}" class="hover:text-white">Disclaimer</a></li>
                        @endif
                        @if(\App\Modules\Content\Models\ContentPage::isSlugPublished('social-media'))
                        <li><a href="{{ route('content.show', 'social-media') }}" class="hover:text-white">Social media policy</a></li>
                        @endif
                        @if(\App\Modules\Content\Models\ContentPage::isSlugPublished('policies'))
                        <li><a href="{{ route('content.show', 'policies') }}" class="hover:text-white">Website terms of use</a></li>
                        @endif
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
                <p class="text-gray-400">
                    <strong class="text-gray-400">Registration is free.</strong> No payment required at signup.
                </p>
            </div>
        </div>
    </footer>

</body>
</html>
