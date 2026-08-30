{{-- Quick actions — one tap to every distributor surface. Flag-gated
     entries are omitted entirely while their feature is off. --}}
@php
    $actions = [
        ['label' => 'Shop',          'href' => route('shop.index'),        'tone' => 'bg-sunrise-50 text-sunrise-700', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/>'],
        ['label' => 'My Orders',     'href' => route('orders.index'),      'tone' => 'bg-amber-50 text-amber-700',     'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>'],
        ['label' => 'My Genos',      'href' => route('tree.binary'),       'tone' => 'bg-brand-50 text-brand-700',     'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v4.5m0 0H8.25m3.75 0h3.75M6 12h12M6 12v4.5M6 12H4.5m1.5 0h1.5M18 12v4.5M18 12h1.5M18 12h-1.5M6 16.5h3m9 0h3M4.5 21h3m9 0h3"/>'],
        ['label' => 'My Referrals',  'href' => route('tree.sponsorship'),  'tone' => 'bg-leaf-50 text-leaf-700',       'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>'],
        ['label' => 'My Business',   'href' => route('my-business'),       'tone' => 'bg-indigo-50 text-indigo-700',   'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>'],
        ['label' => 'Income',        'href' => route('income.dashboard'),  'tone' => 'bg-emerald-50 text-emerald-700', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941"/>'],
        ['label' => 'Wallet',        'href' => route('income.wallet'),     'tone' => 'bg-brand-50 text-brand-700',     'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3"/>'],
    ];
    if ($offersOn) {
        $actions[] = ['label' => 'My Offers', 'href' => route('my.offers.index'), 'tone' => 'bg-pink-50 text-pink-700', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>'];
    }
    if ($adcDirectoryOn) {
        $actions[] = ['label' => 'Arete Centres', 'href' => route('my.adc.directory'), 'tone' => 'bg-violet-50 text-violet-700', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z"/>'];
    }
    if ($requestsOn) {
        $actions[] = ['label' => 'My Requests', 'href' => route('my.requests.index'), 'tone' => 'bg-slate-100 text-slate-700', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>'];
    }
    $actions[] = ['label' => 'Cooling-off', 'href' => route('cooling-off.show'), 'tone' => 'bg-red-50 text-red-700', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'];

    // Each tone string is "bg-{c}-50 text-{c}-700"; derive the tinted card,
    // border and solid icon from the colour name (classes listed literally
    // below so Tailwind keeps them).
    $quickTones = [
        'sunrise' => ['card' => 'border-sunrise-200 bg-sunrise-50 hover:border-sunrise-400 hover:bg-sunrise-100', 'icon' => 'bg-sunrise-500 text-white', 'label' => 'text-sunrise-900'],
        'amber'   => ['card' => 'border-amber-200 bg-amber-50 hover:border-amber-400 hover:bg-amber-100',         'icon' => 'bg-amber-500 text-white',   'label' => 'text-amber-900'],
        'brand'   => ['card' => 'border-brand-200 bg-brand-50 hover:border-brand-400 hover:bg-brand-100',         'icon' => 'bg-brand-500 text-white',   'label' => 'text-brand-900'],
        'leaf'    => ['card' => 'border-leaf-200 bg-leaf-50 hover:border-leaf-400 hover:bg-leaf-100',             'icon' => 'bg-leaf-500 text-white',    'label' => 'text-leaf-900'],
        'indigo'  => ['card' => 'border-indigo-200 bg-indigo-50 hover:border-indigo-400 hover:bg-indigo-100',     'icon' => 'bg-indigo-500 text-white',  'label' => 'text-indigo-900'],
        'emerald' => ['card' => 'border-emerald-200 bg-emerald-50 hover:border-emerald-400 hover:bg-emerald-100', 'icon' => 'bg-emerald-500 text-white', 'label' => 'text-emerald-900'],
        'pink'    => ['card' => 'border-pink-200 bg-pink-50 hover:border-pink-400 hover:bg-pink-100',             'icon' => 'bg-pink-500 text-white',    'label' => 'text-pink-900'],
        'violet'  => ['card' => 'border-violet-200 bg-violet-50 hover:border-violet-400 hover:bg-violet-100',     'icon' => 'bg-violet-500 text-white',  'label' => 'text-violet-900'],
        'slate'   => ['card' => 'border-slate-200 bg-slate-50 hover:border-slate-400 hover:bg-slate-100',         'icon' => 'bg-slate-600 text-white',   'label' => 'text-slate-900'],
        'red'     => ['card' => 'border-red-200 bg-red-50 hover:border-red-400 hover:bg-red-100',                 'icon' => 'bg-red-500 text-white',     'label' => 'text-red-900'],
    ];
@endphp

<div class="mb-6">
    <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold mb-3">Quick actions</p>
    <div class="flex flex-wrap gap-2 sm:gap-3">
        @foreach($actions as $action)
            @php
                preg_match('/^bg-([a-z]+)-/', $action['tone'], $m);
                $qt = $quickTones[$m[1] ?? 'brand'] ?? $quickTones['brand'];
            @endphp
            <a href="{{ $action['href'] }}"
               class="group flex flex-1 basis-[calc(33.333%-0.5rem)] sm:basis-24 sm:max-w-36 min-w-0 flex-col items-center gap-2 rounded-2xl border {{ $qt['card'] }} px-2 py-3 text-center shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl shadow-md {{ $qt['icon'] }} transition group-hover:scale-105">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $action['svg'] !!}</svg>
                </span>
                <span class="text-[11px] sm:text-xs font-semibold {{ $qt['label'] }} leading-tight">{{ $action['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
