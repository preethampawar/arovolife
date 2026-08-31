{{-- Quick actions — one tap to every distributor surface. Flag-gated
     entries are omitted entirely while their feature is off. --}}
@php
    $actions = [
        ['label' => 'Shop',          'href' => route('shop.index'),        'tone' => 'bg-sunrise-50 text-sunrise-700', 'icon' => 'shopping-cart'],
        ['label' => 'My Orders',     'href' => route('orders.index'),      'tone' => 'bg-amber-50 text-amber-700',     'icon' => 'package'],
        ['label' => 'My Genos',      'href' => route('tree.binary'),       'tone' => 'bg-brand-50 text-brand-700',     'icon' => 'network'],
        ['label' => 'My Referrals',  'href' => route('tree.sponsorship'),  'tone' => 'bg-leaf-50 text-leaf-700',       'icon' => 'users'],
        ['label' => 'My Business',   'href' => route('my-business'),       'tone' => 'bg-indigo-50 text-indigo-700',   'icon' => 'chart-column'],
        ['label' => 'Income',        'href' => route('income.dashboard'),  'tone' => 'bg-emerald-50 text-emerald-700', 'icon' => 'trending-up'],
        ['label' => 'Wallet',        'href' => route('income.wallet'),     'tone' => 'bg-brand-50 text-brand-700',     'icon' => 'wallet'],
    ];
    if ($offersOn) {
        $actions[] = ['label' => 'My Offers', 'href' => route('my.offers.index'), 'tone' => 'bg-pink-50 text-pink-700', 'icon' => 'gift'];
    }
    if ($adcDirectoryOn) {
        $actions[] = ['label' => 'Arete Centres', 'href' => route('my.adc.directory'), 'tone' => 'bg-violet-50 text-violet-700', 'icon' => 'landmark'];
    }
    if ($requestsOn) {
        $actions[] = ['label' => 'My Requests', 'href' => route('my.requests.index'), 'tone' => 'bg-slate-100 text-slate-700', 'icon' => 'clipboard-list'];
    }
    $actions[] = ['label' => 'Cooling-off', 'href' => route('cooling-off.show'), 'tone' => 'bg-red-50 text-red-700', 'icon' => 'clock'];

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
                    {{ svg('lucide-'.$action['icon'], 'w-5 h-5') }}
                </span>
                <span class="text-[11px] sm:text-xs font-semibold {{ $qt['label'] }} leading-tight">{{ $action['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
