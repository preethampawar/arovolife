<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>About arovolife — Direct Selling, Done Right</title>
    <meta name="description" content="Born in India, 2026 — arovolife is a customer-first direct selling company offering best-in-class nutraceutical and personal-care products with industry-leading distributor growth pathways.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Reveal-on-scroll baseline. Elements with data-reveal start hidden
           and slide in once they enter the viewport (added via tiny IO below). */
        [data-reveal] { opacity: 0; transform: translateY(24px); transition: opacity 700ms ease-out, transform 700ms cubic-bezier(0.2, 0.8, 0.2, 1); }
        [data-reveal].is-visible { opacity: 1; transform: translateY(0); }
        [data-reveal-delay="100"] { transition-delay: 100ms; }
        [data-reveal-delay="200"] { transition-delay: 200ms; }
        [data-reveal-delay="300"] { transition-delay: 300ms; }
        [data-reveal-delay="400"] { transition-delay: 400ms; }
        [data-reveal-delay="500"] { transition-delay: 500ms; }

        /* A subtle floating animation for hero icon orbs */
        @keyframes ar-float-slow {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(-12px); }
        }
        .ar-float-slow { animation: ar-float-slow 6s ease-in-out infinite; }

        /* Counter wrap */
        .ar-stat-num { font-variant-numeric: tabular-nums; font-feature-settings: "tnum"; }
    </style>
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    {{-- ── 1. HERO ───────────────────────────────────────────────────────── --}}
    <section class="relative overflow-hidden">
        <div class="absolute -top-32 -right-24 w-[460px] h-[460px] bg-brand-200/40 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-[360px] h-[360px] bg-leaf-100/50 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-6 py-20 md:py-28 grid md:grid-cols-2 items-center gap-12 relative">
            <div data-reveal>
                <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">About arovolife</p>
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-gray-900 leading-[1.05] mb-6">
                    A new chapter in <span class="text-brand-600">Indian direct selling</span>.
                </h1>
                <p class="text-lg text-gray-600 mb-8 max-w-xl leading-relaxed">
                    arovolife is an India-incorporated direct-selling company, born in the first half of 2026
                    with one resolute belief — that doing right by the customer and doing right by the
                    distributor are the same job, every single day.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ route('contact.show') }}" class="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold shadow-lg shadow-brand-500/30 transition-colors">
                        Become a Direct Seller →
                    </a>
                    <a href="{{ route('shop.index') }}" class="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-white border border-gray-300 hover:border-brand-500 text-gray-700 hover:text-brand-700 text-sm font-semibold transition-colors">
                        Explore products
                    </a>
                </div>
                <p class="text-xs text-gray-500 mt-5">Free to join · 30-day cooling-off · Compliant with India's DSR 2021.</p>
            </div>

            <div class="relative" data-reveal data-reveal-delay="200">
                <div class="aspect-square max-w-md mx-auto rounded-3xl overflow-hidden shadow-2xl shadow-brand-500/20 bg-white">
                    <img src="https://images.unsplash.com/photo-1521737604893-d14cc237f11d?w=800&q=80&auto=format&fit=crop" alt="A team of distributors meeting" class="w-full h-full object-cover" loading="lazy">
                </div>
                <div class="ar-float-slow absolute -top-6 -left-6 bg-white rounded-2xl shadow-xl border border-gray-100 px-4 py-3 flex items-center gap-3">
                    <span class="w-9 h-9 rounded-full bg-leaf-50 flex items-center justify-center text-leaf-600 font-bold text-sm">99%</span>
                    <div class="text-left">
                        <p class="text-[11px] uppercase tracking-wider text-gray-500 font-semibold">Customer satisfaction</p>
                        <p class="text-sm text-gray-800 font-semibold">our north star</p>
                    </div>
                </div>
                <div class="ar-float-slow absolute -bottom-6 -right-2 bg-white rounded-2xl shadow-xl border border-gray-100 px-4 py-3 flex items-center gap-3" style="animation-delay: -3s;">
                    <span class="w-9 h-9 rounded-full bg-sunrise-50 flex items-center justify-center text-sunrise-600 font-bold text-sm">24h</span>
                    <div class="text-left">
                        <p class="text-[11px] uppercase tracking-wider text-gray-500 font-semibold">Grievance SLA</p>
                        <p class="text-sm text-gray-800 font-semibold">we respond fast</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 2. OUR STORY ─────────────────────────────────────────────────── --}}
    <section class="bg-white border-y border-gray-100 py-20">
        <div class="max-w-6xl mx-auto px-6 grid md:grid-cols-5 gap-12 items-center">
            <div class="md:col-span-2 rounded-2xl overflow-hidden shadow-xl" data-reveal>
                <img src="https://images.unsplash.com/photo-1521737852567-6949f3f9f2b5?w=900&q=80&auto=format&fit=crop" alt="A team building together" class="w-full h-full object-cover" loading="lazy">
            </div>
            <div class="md:col-span-3" data-reveal data-reveal-delay="200">
                <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Our story</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-5 leading-tight">
                    Built from day one for the decade ahead.
                </h2>
                <p class="text-base text-gray-600 mb-4 leading-relaxed">
                    arovolife was founded in the first half of 2026 — at a moment when Indian direct selling
                    is finally on solid statutory ground. We didn't bolt compliance on as an afterthought; we
                    built the business on it. Every line of our software, every clause of our agreements, and
                    every conversation with a distributor is shaped by the
                    <strong class="text-gray-900 font-semibold">Consumer Protection (Direct Selling) Rules, 2021</strong>
                    and the <strong class="text-gray-900 font-semibold">DPDP Act, 2023</strong>.
                </p>
                <p class="text-base text-gray-600 leading-relaxed">
                    Our promise is simple: customers receive products they would buy on quality alone, and
                    distributors earn from real product sales — never from recruiting alone. In a market full
                    of shortcuts, we chose the long road. We're here for the decade ahead, not the next quarter.
                </p>
            </div>
        </div>
    </section>

    {{-- ── 3. CUSTOMER-FIRST PHILOSOPHY ─────────────────────────────────── --}}
    <section class="py-20">
        <div class="max-w-6xl mx-auto px-6">
            <div class="text-center mb-12 max-w-2xl mx-auto" data-reveal>
                <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Our philosophy</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3 leading-tight">
                    Customer first. Always.
                </h2>
                <p class="text-base text-gray-600">
                    Three commitments we make on every product, every interaction, every transaction.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @php
                    $pillars = [
                        ['icon' => '🌿', 'title' => 'Quality you can trust', 'body' => 'Products formulated by qualified scientists, manufactured in FSSAI-licensed and ISO-certified facilities. Every batch carries a Certificate of Analysis on request.'],
                        ['icon' => '💎', 'title' => 'Value that returns daily', 'body' => 'Honest pricing — no MRP-padding, no markup-laundering. The price you pay is the price the product is worth, audited and visible inside your account.'],
                        ['icon' => '🤝', 'title' => 'Service that listens', 'body' => '24-hour grievance acknowledgement, 7-day resolution SLA, and a dedicated compliance team that takes consumer concerns as seriously as the law requires us to.'],
                    ];
                @endphp
                @foreach($pillars as $i => $p)
                <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all" data-reveal data-reveal-delay="{{ ($i + 1) * 100 }}">
                    <div class="w-12 h-12 rounded-xl bg-brand-50 flex items-center justify-center text-2xl mb-4">{{ $p['icon'] }}</div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">{{ $p['title'] }}</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $p['body'] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── 4. OUR PRODUCTS ──────────────────────────────────────────────── --}}
    <section class="bg-[#f4f7f6] py-20">
        <div class="max-w-6xl mx-auto px-6">
            <div class="text-center mb-12 max-w-2xl mx-auto" data-reveal>
                <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Our products</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3 leading-tight">
                    Best-in-class nutraceuticals & personal care.
                </h2>
                <p class="text-base text-gray-600">
                    A small range, deeply considered. We'd rather make ten products you love than a hundred you forget.
                </p>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div class="group relative rounded-3xl overflow-hidden shadow-xl bg-white" data-reveal>
                    <div class="aspect-[4/3] overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1556228720-195a672e8a03?w=900&q=80&auto=format&fit=crop" alt="Nutraceuticals" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" loading="lazy">
                    </div>
                    <div class="p-6">
                        <p class="text-[11px] uppercase tracking-wider text-leaf-700 font-semibold mb-2">Nutraceuticals</p>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Wellness, formulated with intent.</h3>
                        <p class="text-sm text-gray-600 leading-relaxed">
                            Daily-essential supplements, immunity blends, and Ayurveda-inspired formulations —
                            every label transparently lists every milligram. No proprietary blends, no hidden fillers.
                        </p>
                    </div>
                </div>

                <div class="group relative rounded-3xl overflow-hidden shadow-xl bg-white" data-reveal data-reveal-delay="200">
                    <div class="aspect-[4/3] overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1556228841-a3c527ebefe5?w=900&q=80&auto=format&fit=crop" alt="Personal care" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" loading="lazy">
                    </div>
                    <div class="p-6">
                        <p class="text-[11px] uppercase tracking-wider text-sunrise-700 font-semibold mb-2">Personal care</p>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Care that respects skin and ingredients.</h3>
                        <p class="text-sm text-gray-600 leading-relaxed">
                            Skin care, hair care and daily-use essentials — paraben-free where it matters,
                            cruelty-free everywhere, and tested against the highest Indian and international standards.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 5. COMPLIANCE & TRUST ────────────────────────────────────────── --}}
    <section class="py-20">
        <div class="max-w-6xl mx-auto px-6 grid md:grid-cols-2 gap-12 items-center">
            <div data-reveal>
                <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Compliance & trust</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-5 leading-tight">
                    The law isn't a checklist for us. It's the floor we stand on.
                </h2>
                <p class="text-base text-gray-600 mb-5 leading-relaxed">
                    arovolife operates fully within the framework of India's Central and State laws governing
                    direct selling. Compliance isn't a department — it's how we engineer the platform end-to-end.
                </p>
                <ul class="space-y-3">
                    @foreach([
                        'Consumer Protection (Direct Selling) Rules, 2021' => 'Every commission paid only on real product sales — never on recruiting.',
                        'Digital Personal Data Protection Act, 2023'      => 'Personal data encrypted at rest, audit-logged on access, never sold.',
                        'GST, FSSAI, drug & cosmetics standards'          => 'Every invoice GST-compliant; every nutraceutical food-safety certified.',
                        'State-level direct selling registrations'        => 'Where states require additional registration, we register — proactively, before a single sale.',
                        'Independent grievance redressal'                 => 'A dedicated grievance officer; 24-hour acknowledgement, 7-day resolution.',
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
            <div class="rounded-3xl overflow-hidden shadow-xl" data-reveal data-reveal-delay="200">
                <img src="https://images.unsplash.com/photo-1450101499163-c8848c66ca85?w=900&q=80&auto=format&fit=crop" alt="Compliance commitment" class="w-full h-full object-cover" loading="lazy">
            </div>
        </div>
    </section>

    {{-- ── 6. DISTRIBUTOR OPPORTUNITY ───────────────────────────────────── --}}
    <section class="relative bg-gradient-to-br from-brand-600 via-brand-500 to-brand-700 text-white py-20 overflow-hidden">
        <div class="absolute -top-24 -right-20 w-[400px] h-[400px] bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-20 w-[400px] h-[400px] bg-leaf-300/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-6xl mx-auto px-6 relative">
            <div class="text-center mb-12 max-w-3xl mx-auto" data-reveal>
                <p class="text-sm font-medium text-brand-100 uppercase tracking-wider mb-3">For aspiring distributors</p>
                <h2 class="text-3xl md:text-4xl font-bold mb-4 leading-tight">
                    The fairest direct selling opportunity in India.
                </h2>
                <p class="text-base text-brand-50 leading-relaxed">
                    No registration fee. No minimum stocking. No pressure. Build at your pace, on terms that
                    are written down — and protected by law.
                </p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach([
                    ['title' => 'Free to join', 'body' => 'Statutory — joining is always free. No SKU, no kit fee.'],
                    ['title' => '30-day cooling-off', 'body' => 'One-click cancellation, full refund, no questions asked.'],
                    ['title' => 'Sale-only earnings', 'body' => 'Every rupee tied to a real product sale. Transparent. Auditable.'],
                    ['title' => 'No upline tax', 'body' => 'You keep what you earn. We don\'t skim, we don\'t hide.'],
                ] as $i => $p)
                <div class="bg-white/10 backdrop-blur rounded-2xl border border-white/20 p-5" data-reveal data-reveal-delay="{{ ($i + 1) * 100 }}">
                    <p class="font-semibold text-white mb-1.5">{{ $p['title'] }}</p>
                    <p class="text-sm text-brand-50/80 leading-snug">{{ $p['body'] }}</p>
                </div>
                @endforeach
            </div>

            <div class="text-center mt-12" data-reveal data-reveal-delay="500">
                <a href="{{ route('contact.show') }}" class="inline-flex items-center gap-2 px-7 py-3 rounded-full bg-white text-brand-700 hover:bg-brand-50 text-sm font-semibold transition-colors shadow-lg">
                    Talk to our team →
                </a>
            </div>
        </div>
    </section>

    {{-- ── 7. GROWTH PATHWAY ────────────────────────────────────────────── --}}
    <section class="py-20">
        <div class="max-w-6xl mx-auto px-6">
            <div class="grid md:grid-cols-2 gap-12 items-center">
                <div class="rounded-3xl overflow-hidden shadow-xl" data-reveal>
                    <img src="https://images.unsplash.com/photo-1573164574572-cb89e39749b4?w=900&q=80&auto=format&fit=crop" alt="Growth pathway" class="w-full h-full object-cover" loading="lazy">
                </div>
                <div data-reveal data-reveal-delay="200">
                    <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Growth pathway</p>
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-5 leading-tight">
                        From your first sale to leadership — we walk it with you.
                    </h2>
                    <p class="text-base text-gray-600 mb-6 leading-relaxed">
                        Our compensation plan rewards what's measurable: your team's product sales. The pathway
                        is published, the math is transparent, and your progress is visible to you alone in your
                        secure dashboard.
                    </p>
                    <div class="space-y-4">
                        @foreach([
                            ['n' => '01', 'title' => 'Onboarding & orientation', 'body' => 'Mandatory product training, statutory orientation, micro-quiz. We make sure you can stand behind every claim.'],
                            ['n' => '02', 'title' => 'Mentorship that\'s real', 'body' => 'Weekly mentor calls, written FAQ libraries, 1:1 escalation paths to compliance and product teams.'],
                            ['n' => '03', 'title' => 'Ranks and rewards', 'body' => 'Six published ranks with clear product-volume thresholds. No "secret sauce", no moving goalposts.'],
                        ] as $s)
                        <div class="flex gap-4">
                            <span class="shrink-0 w-10 h-10 rounded-full bg-brand-50 text-brand-700 flex items-center justify-center font-bold text-sm">{{ $s['n'] }}</span>
                            <div>
                                <p class="font-semibold text-gray-900 mb-0.5">{{ $s['title'] }}</p>
                                <p class="text-sm text-gray-600 leading-snug">{{ $s['body'] }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── 8. VALUES ────────────────────────────────────────────────────── --}}
    <section class="bg-white border-y border-gray-100 py-20">
        <div class="max-w-6xl mx-auto px-6">
            <div class="text-center mb-12 max-w-2xl mx-auto" data-reveal>
                <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Our values</p>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3 leading-tight">
                    What we won't compromise on.
                </h2>
                <p class="text-base text-gray-600">
                    Six lines we draw and honour — every quarter, every conversation, every contract.
                </p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 gap-5">
                @foreach([
                    ['icon' => '⚖', 'title' => 'Ethics', 'body' => 'Right is right, even when nobody is watching. Especially then.'],
                    ['icon' => '🔍', 'title' => 'Transparency', 'body' => 'Costs published. Commissions auditable. No fine print that bites later.'],
                    ['icon' => '🤲', 'title' => 'Trust', 'body' => 'We earn it once, then we work every day to keep it.'],
                    ['icon' => '💚', 'title' => 'Service', 'body' => 'Customers and distributors are family. We treat them like it.'],
                    ['icon' => '🔬', 'title' => 'Excellence', 'body' => 'Best-in-class isn\'t a slogan — it\'s a manufacturing standard.'],
                    ['icon' => '🌏', 'title' => 'Community', 'body' => 'Indian roots, international standards, decade-long horizon.'],
                ] as $i => $v)
                <div class="bg-gray-50/50 border border-gray-200 rounded-2xl p-5 hover:border-brand-300 hover:bg-brand-50/30 transition-colors" data-reveal data-reveal-delay="{{ (($i % 3) + 1) * 100 }}">
                    <div class="w-10 h-10 rounded-lg bg-white border border-gray-200 flex items-center justify-center text-xl mb-3 shadow-sm">{{ $v['icon'] }}</div>
                    <h3 class="font-bold text-gray-900 mb-1">{{ $v['title'] }}</h3>
                    <p class="text-sm text-gray-600 leading-snug">{{ $v['body'] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── 9. WHY NOW / STATS ───────────────────────────────────────────── --}}
    <section class="py-20">
        <div class="max-w-6xl mx-auto px-6">
            <div class="grid md:grid-cols-2 gap-12 items-center">
                <div data-reveal>
                    <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Why now, why arovolife</p>
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-5 leading-tight">
                        India's direct selling market is just getting started.
                    </h2>
                    <p class="text-base text-gray-600 leading-relaxed mb-4">
                        India's wellness and personal-care market is among the fastest-growing in the world,
                        powered by a young population, rising disposable income, and a generation that asks
                        better questions about what's in the bottle.
                    </p>
                    <p class="text-base text-gray-600 leading-relaxed">
                        We started in 2026 because the legal foundation, the consumer maturity, and the digital
                        rails — UPI, Aadhaar e-KYC, GST, ONDC — finally line up. There's never been a better
                        decade to build a direct selling company in India. We intend to lead it.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-4" data-reveal data-reveal-delay="200">
                    @foreach([
                        ['stat' => '2026', 'label' => 'Founded in India', 'sub' => 'Q1 launch, decade-long vision'],
                        ['stat' => '100%', 'label' => 'DSR-2021 compliant', 'sub' => 'From the first user, every sale'],
                        ['stat' => '0₹',   'label' => 'Joining fee',      'sub' => 'Statutory, always'],
                        ['stat' => '24h',  'label' => 'Grievance SLA',    'sub' => 'Acknowledged, never queued'],
                    ] as $s)
                    <div class="rounded-2xl bg-gradient-to-br from-brand-50 to-white border border-brand-100 p-5">
                        <p class="ar-stat-num text-3xl md:text-4xl font-bold text-brand-700 leading-none mb-2">{{ $s['stat'] }}</p>
                        <p class="text-sm font-semibold text-gray-900">{{ $s['label'] }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $s['sub'] }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ── 10. JOIN US CTA ──────────────────────────────────────────────── --}}
    <section class="relative py-20 overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-leaf-50 via-white to-brand-50 pointer-events-none"></div>
        <div class="absolute -top-20 right-1/4 w-[300px] h-[300px] bg-brand-200/40 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-20 left-1/4 w-[300px] h-[300px] bg-leaf-200/40 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-4xl mx-auto px-6 relative text-center" data-reveal>
            <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Join us</p>
            <h2 class="text-4xl md:text-5xl font-bold text-gray-900 leading-[1.1] mb-5">
                Two paths in. <br class="hidden sm:inline">One promise of <span class="text-brand-600">trust</span>.
            </h2>
            <p class="text-lg text-gray-600 mb-10 max-w-2xl mx-auto leading-relaxed">
                Whether you come for the products or stay to build a business —
                you'll find a partner that takes the long view, the legal view, and the human view, every time.
            </p>

            <div class="grid sm:grid-cols-2 gap-5 max-w-2xl mx-auto">
                <a href="{{ route('shop.index') }}" class="group rounded-2xl bg-white border-2 border-gray-200 hover:border-brand-500 p-6 text-left transition-all hover:shadow-xl hover:-translate-y-1">
                    <p class="text-[11px] uppercase tracking-wider text-brand-600 font-semibold mb-1">For customers</p>
                    <p class="text-xl font-bold text-gray-900 mb-2">Shop the range</p>
                    <p class="text-sm text-gray-600 mb-3">Quality nutraceutical and personal-care products, delivered honestly.</p>
                    <p class="text-sm text-brand-700 font-semibold group-hover:translate-x-1 transition-transform">Browse products →</p>
                </a>

                <a href="{{ route('contact.show') }}" class="group rounded-2xl bg-brand-500 hover:bg-brand-600 text-white p-6 text-left transition-all hover:shadow-xl hover:-translate-y-1 shadow-lg shadow-brand-500/30">
                    <p class="text-[11px] uppercase tracking-wider text-brand-100 font-semibold mb-1">For aspiring distributors</p>
                    <p class="text-xl font-bold mb-2">Become a Direct Seller</p>
                    <p class="text-sm text-brand-50 mb-3">Free to join. Industry-leading support. Earn from real product sales.</p>
                    <p class="text-sm font-semibold group-hover:translate-x-1 transition-transform">Talk to our team →</p>
                </a>
            </div>

            <p class="mt-10 text-xs text-gray-500">
                Arovolife Private Limited — CIN U46909TS2026PTC210896 — Registered in India.
            </p>
        </div>
    </section>

    {{-- Footer (matches landing) --}}
    <footer class="bg-gray-900 text-gray-400 py-12">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <img src="{{ asset('assets/arovolife-logos/arovolife-white-logo.png') }}" alt="arovolife" class="h-12 w-auto mx-auto mb-4">
            <p class="text-xs">&copy; {{ date('Y') }} Arovolife Private Limited. All rights reserved.</p>
        </div>
    </footer>

    {{-- Reveal-on-scroll JS — minimal IntersectionObserver. --}}
    <script>
        (() => {
            const items = document.querySelectorAll('[data-reveal]');
            if (!('IntersectionObserver' in window)) {
                items.forEach(el => el.classList.add('is-visible'));
                return;
            }
            const io = new IntersectionObserver((entries) => {
                entries.forEach(e => {
                    if (e.isIntersecting) {
                        e.target.classList.add('is-visible');
                        io.unobserve(e.target);
                    }
                });
            }, { rootMargin: '0px 0px -10% 0px', threshold: 0.05 });
            items.forEach(el => io.observe(el));
        })();
    </script>

</body>
</html>
