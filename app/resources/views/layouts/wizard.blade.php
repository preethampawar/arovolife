<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Registration') — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    @include('partials._google-analytics')
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage overflow-x-hidden">

    @include('partials.public-topnav')

    @php
        $steps = [
            1  => ['label' => 'Sponsor & Placement', 'route' => 'join.show'],
            2  => ['label' => 'Account',             'route' => 'register.account.show'],
            3  => ['label' => 'Orientation',         'route' => 'register.orientation'],
            4  => ['label' => 'Consent',             'route' => 'register.consent'],
            5  => ['label' => 'PAN',                 'route' => 'register.pan'],
            6  => ['label' => 'Aadhaar',             'route' => 'register.aadhaar'],
            7  => ['label' => 'Bank (optional)',     'route' => 'register.bank'],
            8  => ['label' => 'Personal',            'route' => 'register.personal'],
            9  => ['label' => 'Documents',           'route' => 'register.documents'],
            10 => ['label' => 'Complete',            'route' => 'register.complete'],
        ];
        $current = $currentStep ?? 1;
    @endphp

    {{-- Compact horizontal stepper for narrow screens (lg-) --}}
    @if(isset($currentStep))
    <div class="lg:hidden bg-white border-b border-gray-200">
        <div class="max-w-3xl mx-auto px-6 py-4">
            <div class="flex items-center gap-1">
                @foreach($steps as $n => $meta)
                    <div class="flex-1 text-center">
                        <div class="h-1 rounded-full mb-1
                            {{ $n < $current ? 'bg-brand-500' : ($n === $current ? 'bg-brand-400' : 'bg-gray-100') }}"></div>
                        <span class="text-[11px] {{ $n === $current ? 'text-brand-600 font-semibold' : 'text-gray-600' }}">
                            {{ $meta['label'] }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- Two-column layout (lg+): vertical step nav on the left + form on the right.
         min-w-0 keeps wide form content (image grids, document upload previews,
         the tree on review steps) from pushing past the viewport. --}}
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 lg:py-12 lg:grid lg:grid-cols-[260px_1fr] lg:gap-12 min-w-0 max-w-full">

        {{-- Vertical step sidebar (lg+) --}}
        @if(isset($currentStep))
        <aside class="hidden lg:block">
            <div class="lg:sticky lg:top-8">
                {{-- Editorial header — keeps the sidebar from feeling utilitarian --}}
                <div class="mb-6 lift-in" style="animation-delay: 0ms;">
                    <p class="text-[10px] uppercase tracking-[0.22em] text-brand-700/80 font-semibold mb-2">Registration</p>
                    <h2 class="text-display text-3xl text-slate-900 leading-[1.05]" style="font-weight: 380;">
                        Step <span class="text-brand-500">{{ str_pad((string) $current, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="text-slate-300 font-light">/ 10</span>
                    </h2>
                    <p class="mt-2 text-[12px] text-slate-500 leading-relaxed">
                        {{ $steps[$current]['label'] ?? '' }}.
                    </p>
                </div>

                <nav aria-label="Registration steps" class="relative">
                    {{-- Vertical rail behind the numerals — gradient fills with progress --}}
                    @php $progressPct = max(0, min(100, ($current - 1) / 9 * 100)); @endphp
                    <span aria-hidden="true"
                          class="absolute left-[14px] top-2 bottom-2 w-px bg-slate-200/70"></span>
                    <span aria-hidden="true"
                          class="absolute left-[14px] top-2 w-px bg-gradient-to-b from-brand-500 via-leaf-500 to-sunrise-500 transition-[height] duration-700"
                          style="height: calc({{ $progressPct }}% - 0.5rem);"></span>

                    <ol class="space-y-0.5 relative">
                        @foreach($steps as $n => $meta)
                            @php
                                $isDone   = $n < $current;
                                $isActive = $n === $current;
                                $clickable = ($isDone && $n !== 1) || $isActive;
                                $caption = $isDone ? 'Complete' : ($isActive ? 'In progress' : 'Pending');
                                $captionClass = $isDone
                                    ? 'text-leaf-600'
                                    : ($isActive ? 'text-brand-600' : 'text-slate-400');
                                $delay = ($n * 40) + 80;
                            @endphp
                            <li class="lift-in" style="animation-delay: {{ $delay }}ms;">
                                @if($clickable)
                                <a href="{{ route($meta['route']) }}"
                                   aria-current="{{ $isActive ? 'step' : 'false' }}"
                                   class="group grid grid-cols-[36px_1fr] items-center gap-3 px-2 py-2 rounded-lg transition-all duration-200
                                          {{ $isActive ? 'bg-white/70 ring-1 ring-brand-200/60 shadow-[0_2px_8px_-4px_rgba(28,128,227,0.25)]' : 'hover:bg-white/50' }}">
                                @else
                                <span class="grid grid-cols-[36px_1fr] items-center gap-3 px-2 py-2 cursor-not-allowed">
                                @endif

                                    {{-- Editorial numeral / status mark --}}
                                    <span class="relative flex items-center justify-center w-9 h-9">
                                        @if($isDone)
                                            <span class="absolute inset-0 rounded-full bg-leaf-500 flex items-center justify-center">
                                                <svg class="w-4 h-4 text-white" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3.5 8.5l3 3 6-6"/>
                                                </svg>
                                            </span>
                                        @elseif($isActive)
                                            <span class="absolute inset-0 rounded-full bg-white ring-2 ring-brand-500 glow-pulse"></span>
                                            <span class="step-numeral relative text-brand-600 text-[15px]" style="font-weight: 500;">{{ str_pad((string) $n, 2, '0', STR_PAD_LEFT) }}</span>
                                        @else
                                            <span class="step-numeral text-slate-300 text-[18px]" style="font-weight: 350;">{{ str_pad((string) $n, 2, '0', STR_PAD_LEFT) }}</span>
                                        @endif
                                    </span>

                                    {{-- Label + caption --}}
                                    <div class="min-w-0">
                                        <p class="text-[10px] uppercase tracking-[0.18em] {{ $captionClass }} font-semibold leading-none mb-0.5">{{ $caption }}</p>
                                        <p class="text-sm leading-tight
                                            {{ $isActive ? 'text-slate-900 font-semibold' :
                                               ($isDone ? 'text-slate-700 group-hover:text-brand-700' : 'text-slate-400') }}">
                                            {{ $meta['label'] }}
                                        </p>
                                    </div>

                                @if($clickable)
                                </a>
                                @else
                                </span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </nav>

                {{-- Trust panel — editorial footer --}}
                <div class="mt-8 lift-in p-4 rounded-xl border border-slate-200/70 bg-white/60 backdrop-blur-sm" style="animation-delay: 600ms;">
                    <p class="text-[10px] uppercase tracking-[0.2em] text-slate-400 font-semibold mb-3">A note on signing up</p>
                    <ul class="space-y-2.5 text-[12px] text-slate-600 leading-snug">
                        <li class="flex gap-2"><span class="text-leaf-500 font-bold">·</span><span>Registration is <strong class="text-slate-800">free of charge</strong>.</span></li>
                        <li class="flex gap-2"><span class="text-brand-500 font-bold">·</span><span>30-day cooling-off, one-click cancel.</span></li>
                        <li class="flex gap-2"><span class="text-sunrise-500 font-bold">·</span><span>PAN/Aadhaar are encrypted at rest.</span></li>
                        <li class="flex gap-2"><span class="text-slate-400 font-bold">·</span><span>Backed by India's DSR&nbsp;2021.</span></li>
                    </ul>
                </div>
            </div>
        </aside>
        @endif

        {{-- Main content --}}
        <main class="min-w-0">
            @if($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
                <ul class="list-disc list-inside space-y-1 text-sm text-red-700">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if(session('status'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                {{ session('status') }}
            </div>
            @endif

            @yield('content')
        </main>
    </div>

    {{-- Footer --}}
    <footer class="border-t border-gray-200 mt-16 px-6 py-6 text-center text-xs text-gray-700">
        Registering with arovolife is <strong class="text-gray-900">free of charge</strong>.
        No payment is required at registration.
        <div class="mt-2 space-x-4">
            <a href="{{ route('content.show', 'terms') }}" class="underline hover:text-gray-900">Terms</a>
            <a href="{{ route('content.show', 'privacy') }}" class="underline hover:text-gray-900">Privacy</a>
            <a href="{{ route('content.show', 'ethics') }}" class="underline hover:text-gray-900">Code of Ethics</a>
            <a href="{{ route('content.show', 'grievance') }}" class="underline hover:text-gray-900">Grievance</a>
            <a href="{{ route('compliance-documents.index') }}" class="underline hover:text-gray-900">Compliance Documents</a>
        </div>
        <p class="mt-2 text-gray-600">
            Customer Care:
            <a href="tel:+918886662949" class="hover:text-gray-900">+91 88866 62949</a> ·
            <a href="mailto:support@arovolife.com" class="hover:text-gray-900">support@arovolife.com</a> ·
            9:30 am – 5:30 pm, every day except Sundays &amp; public holidays
        </p>
    </footer>

</body>
</html>
