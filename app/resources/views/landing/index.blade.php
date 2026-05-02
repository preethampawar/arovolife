<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>arovolife — Direct Selling, Done Right</title>
    <meta name="description" content="arovolife is a direct-selling company compliant with India's DSR 2021. Free to join, 30-day cooling-off, no income projections.">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    {{-- Hero banner --}}
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
                    'body' => 'Free to join. 30-day cooling-off with one-click cancellation. Fully compliant with India\'s Consumer Protection (Direct Selling) Rules, 2021.',
                    'cta_primary' => ['label' => 'Become a Direct Seller →', 'url' => route('contact.show')],
                    'cta_secondary' => ['label' => 'How It Works', 'url' => '#how-it-works'],
                    'note' => 'Joining is free. No payment required at registration.',
                ],
                [
                    'eyebrow' => 'arovolife Shopping Mall',
                    'title_plain' => 'Quality Essentials',
                    'title_accent' => 'Delivered to Your Door',
                    'body' => 'Browse our curated range of personal care, health and food products. Free shipping within India on orders above ₹499. GST invoice on every order.',
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

        {{-- Sliding track: horizontally translates between slides --}}
        <div class="relative overflow-hidden">
            <div class="hero-track flex transition-transform duration-700 ease-[cubic-bezier(0.4,0,0.2,1)] will-change-transform">
                @foreach($slides as $i => $s)
                <div data-slide="{{ $i }}"
                     role="group" aria-roledescription="slide" aria-label="Slide {{ $i + 1 }} of {{ count($slides) }}"
                     class="hero-slide shrink-0 w-full">

                    <div class="relative max-w-7xl mx-auto px-6 py-20 md:py-28 grid md:grid-cols-2 items-center gap-10">
                        <div class="hero-slide-content">
                            <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">{{ $s['eyebrow'] }}</p>
                            <h1 class="text-4xl md:text-5xl font-bold text-gray-900 leading-tight mb-5">
                                {{ $s['title_plain'] }}
                                @if($s['title_accent'])
                                <span class="text-brand-600">{{ $s['title_accent'] }}</span>
                                @endif
                            </h1>
                            <p class="text-lg text-gray-600 mb-8 max-w-lg">{{ $s['body'] }}</p>
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
                            <p class="text-xs text-gray-500 mt-5">{{ $s['note'] }}</p>
                        </div>

                        <div class="hero-slide-visual hidden md:flex justify-center">
                            <div class="hero-circle relative w-80 h-80">
                                {{-- Pulsing rings (emanate outward) --}}
                                <div class="hero-ring hero-ring-1 absolute inset-0 rounded-full border-2 border-brand-400/40"></div>
                                <div class="hero-ring hero-ring-2 absolute inset-0 rounded-full border-2 border-brand-400/40"></div>
                                <div class="hero-ring hero-ring-3 absolute inset-0 rounded-full border-2 border-brand-400/40"></div>

                                {{-- Outer gradient halo --}}
                                <div class="hero-halo absolute inset-0 bg-gradient-to-br from-brand-400 to-brand-600 rounded-full opacity-20"></div>

                                {{-- Glow backdrop --}}
                                <div class="hero-glow absolute inset-4 bg-brand-300/40 rounded-full blur-2xl"></div>

                                {{-- Inner white disc with the blue brand logo --}}
                                <div class="hero-logo-disc absolute inset-8 bg-white rounded-full shadow-2xl shadow-brand-500/30 flex items-center justify-center">
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
                </div>
                @endforeach
            </div>
        </div>

        <style>
            .hero-slide[data-active="true"] .hero-slide-content { animation: heroFadeUp 700ms cubic-bezier(0.4, 0, 0.2, 1) 100ms both; }
            .hero-slide[data-active="true"] .hero-slide-visual  { animation: heroZoomIn 700ms cubic-bezier(0.4, 0, 0.2, 1) 200ms both; }
            @keyframes heroFadeUp {
                0%   { opacity: 0; transform: translateY(20px); }
                100% { opacity: 1; transform: translateY(0); }
            }
            @keyframes heroZoomIn {
                0%   { opacity: 0; transform: scale(0.9); }
                100% { opacity: 1; transform: scale(1); }
            }

            /* Pulsing rings emanating outward */
            .hero-ring {
                animation: heroPulseRing 3s ease-out infinite;
                opacity: 0;
            }
            .hero-ring-1 { animation-delay: 0s; }
            .hero-ring-2 { animation-delay: 1s; }
            .hero-ring-3 { animation-delay: 2s; }
            @keyframes heroPulseRing {
                0%   { transform: scale(1);   opacity: 0.7; }
                80%  { opacity: 0.1; }
                100% { transform: scale(1.35); opacity: 0; }
            }

            /* Outer halo breathes */
            .hero-halo { animation: heroHaloBreathe 4s ease-in-out infinite; }
            @keyframes heroHaloBreathe {
                0%, 100% { opacity: 0.18; transform: scale(1); }
                50%      { opacity: 0.32; transform: scale(1.03); }
            }

            /* Behind-the-disc glow pulses */
            .hero-glow { animation: heroGlowPulse 3.5s ease-in-out infinite; }
            @keyframes heroGlowPulse {
                0%, 100% { opacity: 0.4; transform: scale(1); }
                50%      { opacity: 0.7; transform: scale(1.08); }
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
        <div class="absolute bottom-6 right-6 flex items-center gap-3 text-brand-700 text-xs font-medium z-10">
            <span data-slider-counter>1/{{ count($slides) }}</span>
            <div class="flex items-center gap-1.5" data-slider-dots>
                @foreach($slides as $i => $s)
                <button type="button" data-goto="{{ $i }}" aria-label="Go to slide {{ $i + 1 }}"
                        class="hero-dot h-1.5 rounded-full transition-all {{ $i === 0 ? 'w-8 bg-brand-500' : 'w-2 bg-brand-300 hover:bg-brand-400' }}"></button>
                @endforeach
            </div>
        </div>
    </section>

    <script>
    (function() {
        const root = document.getElementById('hero-slider');
        if (!root) return;
        const track = root.querySelector('.hero-track');
        const slides = root.querySelectorAll('.hero-slide');
        const dots = root.querySelectorAll('.hero-dot');
        const counter = root.querySelector('[data-slider-counter]');
        const total = slides.length;
        let idx = 0;
        let timer = null;

        // Initial state
        slides[0]?.setAttribute('data-active', 'true');

        function show(i) {
            const prev = idx;
            idx = ((i % total) + total) % total;

            // Translate the track
            if (track) track.style.transform = `translateX(-${idx * 100}%)`;

            // Restart content animations on the new slide
            slides.forEach((el, k) => {
                if (k === idx) {
                    el.removeAttribute('data-active');
                    // Force reflow so the animation restarts
                    void el.offsetWidth;
                    el.setAttribute('data-active', 'true');
                } else {
                    el.removeAttribute('data-active');
                }
            });

            dots.forEach((dot, k) => {
                const active = k === idx;
                dot.className = 'hero-dot h-1.5 rounded-full transition-all ' +
                    (active ? 'w-8 bg-brand-500' : 'w-2 bg-brand-300 hover:bg-brand-400');
            });
            if (counter) counter.textContent = (idx + 1) + '/' + total;
        }

        function next() { show(idx + 1); }
        function prev() { show(idx - 1); }

        function start() {
            stop();
            timer = setInterval(next, 6000);
        }
        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        root.querySelector('[data-slider-prev]')?.addEventListener('click', () => { prev(); start(); });
        root.querySelector('[data-slider-next]')?.addEventListener('click', () => { next(); start(); });
        dots.forEach(dot => dot.addEventListener('click', () => {
            show(Number(dot.getAttribute('data-goto')));
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

        // Basic touch/swipe support
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

        // Respect reduced-motion preference
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            if (track) track.style.transitionDuration = '0ms';
        }

        start();
    })();
    </script>

    {{-- Trust pillar icon row (replaces Atomy's product category row) --}}
    <section class="bg-white border-y border-gray-100 py-10">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-6">
                @php
                    $pillars = [
                        ['label' => 'Free to Join',       'tone' => 'brand',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />'],
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
                    <span class="text-xs text-gray-700 font-medium leading-tight">{{ $p['label'] }}</span>
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
                <p class="text-gray-600">Four promises, every transaction, every day.</p>
            </div>

            @php
                $whyCards = [
                    ['title' => 'Free Registration',           'body' => 'Zero joining fee. No payment required at signup — ever.',                       'icon' => '🎁', 'bg' => 'bg-brand-50',   'border' => 'border-brand-200',   'iconBg' => 'bg-brand-100 text-brand-700',     'titleClr' => 'text-brand-700'],
                    ['title' => 'Real Sales Earnings',         'body' => 'Commissions are paid on actual product sales, never on recruiting alone.',     'icon' => '🌱', 'bg' => 'bg-leaf-50',    'border' => 'border-leaf-200',    'iconBg' => 'bg-leaf-100 text-leaf-700',       'titleClr' => 'text-leaf-700'],
                    ['title' => '30-Day Cooling-Off',          'body' => 'One-click cancellation with full refund during the cooling-off period.',       'icon' => '☀',  'bg' => 'bg-sunrise-50', 'border' => 'border-sunrise-200', 'iconBg' => 'bg-sunrise-100 text-sunrise-700', 'titleClr' => 'text-sunrise-700'],
                    ['title' => 'Your Data, Protected',        'body' => 'PAN stored as hash. Raw Aadhaar never touches our database. Full audit log.',  'icon' => '🛡', 'bg' => 'bg-violet-50',  'border' => 'border-violet-200',  'iconBg' => 'bg-violet-100 text-violet-700',   'titleClr' => 'text-violet-700'],
                ];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                @foreach($whyCards as $card)
                <div class="rounded-2xl border-2 {{ $card['border'] }} {{ $card['bg'] }} p-6 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center {{ $card['iconBg'] }} text-2xl mb-4 shadow-sm">
                        {{ $card['icon'] }}
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
                <p class="text-gray-600">From referral link to live ADN — no surprises along the way.</p>
            </div>

            @php
                $steps = [
                    ['1', 'Create Account', 'Name, email, phone, password.', 'bg-brand-500',   'shadow-brand-500/30'],
                    ['2', 'Orientation',    'Watch the video, pass the quiz.', 'bg-leaf-500',   'shadow-leaf-500/30'],
                    ['3', 'KYC',            'PAN + Aadhaar (verified gateway).', 'bg-sunrise-500','shadow-sunrise-500/30'],
                    ['4', 'Placement',      'Confirm your sponsor + leg.', 'bg-violet-500', 'shadow-violet-500/30'],
                    ['5', 'Get Your ADN',   'Distributor Number issued instantly.', 'bg-brand-700', 'shadow-brand-700/30'],
                ];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                @foreach($steps as $step)
                <div class="relative bg-white rounded-2xl border border-gray-200 p-5 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                    <div class="w-10 h-10 rounded-full {{ $step[3] }} text-white flex items-center justify-center font-bold text-sm mb-3 shadow-lg {{ $step[4] }}">
                        {{ $step[0] }}
                    </div>
                    <h4 class="font-semibold text-gray-900 mb-1 text-sm">{{ $step[1] }}</h4>
                    <p class="text-xs text-gray-600 leading-relaxed">{{ $step[2] }}</p>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-10">
                <a href="{{ route('contact.show') }}"
                   class="inline-flex items-center gap-2 px-8 py-3 rounded-full bg-gradient-to-r from-brand-500 via-brand-600 to-brand-700 hover:from-brand-600 hover:to-brand-800 text-white text-sm font-semibold transition-all shadow-lg shadow-brand-500/40 hover:shadow-xl hover:shadow-brand-500/50">
                    Talk to our team →
                </a>
                <p class="mt-3 text-xs text-gray-500">
                    Joining is by personal referral only — leave your details and we'll connect you with a sponsor.
                </p>
            </div>
        </div>
    </section>

    {{-- Products preview — vibrant category teasers --}}
    <section class="relative py-16 overflow-hidden">
        <div class="absolute top-0 left-1/3 w-[400px] h-[400px] bg-leaf-100/50 rounded-full blur-3xl pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-6 relative">
            <div class="text-center mb-10 max-w-2xl mx-auto">
                <p class="text-sm font-medium text-leaf-600 uppercase tracking-wider mb-2">Our products</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">Best-in-class. <span class="text-leaf-600">Best for life.</span></h2>
                <p class="text-gray-600">A small range, deeply considered — wellness essentials and personal care that stand on their own quality.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @php
                    $categories = [
                        [
                            'title' => 'Nutraceuticals',
                            'subtitle' => 'Wellness, formulated with intent',
                            'body' => 'Daily-essential supplements, immunity blends, Ayurveda-inspired formulations. Every milligram on the label.',
                            'gradient' => 'from-leaf-500 via-leaf-600 to-leaf-700',
                            'glow' => 'shadow-leaf-500/30',
                            'icon' => '🌿',
                        ],
                        [
                            'title' => 'Personal Care',
                            'subtitle' => 'Skin, hair, body — done honestly',
                            'body' => 'Skin care, hair care, daily essentials — paraben-free where it matters, cruelty-free everywhere.',
                            'gradient' => 'from-sunrise-400 via-sunrise-500 to-sunrise-600',
                            'glow' => 'shadow-sunrise-500/30',
                            'icon' => '☀',
                        ],
                        [
                            'title' => 'Wellness Bundles',
                            'subtitle' => 'Curated for everyday life',
                            'body' => 'Hand-picked combinations of our most-loved products — a smarter starting point for the wellness-curious.',
                            'gradient' => 'from-brand-500 via-brand-600 to-brand-700',
                            'glow' => 'shadow-brand-500/30',
                            'icon' => '✨',
                        ],
                    ];
                @endphp
                @foreach($categories as $cat)
                <a href="{{ route('shop.index') }}" class="group relative rounded-3xl overflow-hidden bg-gradient-to-br {{ $cat['gradient'] }} p-7 shadow-xl {{ $cat['glow'] }} hover:shadow-2xl hover:-translate-y-1 transition-all duration-300 text-white block">
                    <div class="absolute -top-12 -right-12 w-40 h-40 bg-white/10 rounded-full blur-2xl group-hover:bg-white/20 transition-colors"></div>
                    <div class="relative">
                        <div class="text-4xl mb-3 inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-white/20 backdrop-blur">{{ $cat['icon'] }}</div>
                        <p class="text-[11px] uppercase tracking-wider text-white/80 font-semibold mb-1">{{ $cat['subtitle'] }}</p>
                        <h3 class="text-2xl font-bold mb-3 leading-tight">{{ $cat['title'] }}</h3>
                        <p class="text-sm text-white/90 leading-relaxed mb-5">{{ $cat['body'] }}</p>
                        <span class="inline-flex items-center gap-1.5 text-sm font-semibold group-hover:translate-x-1 transition-transform">
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
                    <p class="text-xs uppercase tracking-wider text-brand-100 mb-1 font-medium">Statute</p>
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
                    <p class="text-xs leading-relaxed">
                        Arovolife Private Limited — a direct-selling company incorporated in India.
                        CIN U46909TS2026PTC210896.
                    </p>
                </div>
                <div>
                    <h4 class="text-white text-sm font-semibold mb-3">Company</h4>
                    <ul class="space-y-2 text-xs">
                        <li><a href="{{ route('about') }}" class="hover:text-white">About arovolife</a></li>
                        <li><a href="{{ route('content.show', 'ethics') }}" class="hover:text-white">Code of Ethics</a></li>
                        <li><a href="#how-it-works" class="hover:text-white">How It Works</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white text-sm font-semibold mb-3">Legal</h4>
                    <ul class="space-y-2 text-xs">
                        <li><a href="{{ route('content.show', 'terms') }}" class="hover:text-white">Direct Seller Agreement</a></li>
                        <li><a href="{{ route('content.show', 'privacy') }}" class="hover:text-white">Privacy Policy</a></li>
                        <li><a href="{{ route('content.show', 'grievance') }}" class="hover:text-white">Grievance Redressal</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white text-sm font-semibold mb-3">Get Started</h4>
                    <ul class="space-y-2 text-xs">
                        <li><a href="{{ route('contact.show') }}" class="hover:text-white">Become a Direct Seller</a></li>
                        <li><a href="{{ route('login') }}" class="hover:text-white">Sign In</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 pt-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-3 text-xs">
                <p>&copy; {{ date('Y') }} Arovolife Private Limited. All rights reserved.</p>
                <p class="text-gray-500">
                    <strong class="text-gray-400">Joining is free.</strong> No payment required at registration.
                </p>
            </div>
        </div>
    </footer>

</body>
</html>
