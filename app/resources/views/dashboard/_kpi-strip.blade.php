{{-- Headline KPIs — every figure is the distributor's own historical data,
     read from the same services as My Business and /income. --}}
@php
    $fmt = \App\Modules\Shared\Support\IndianNumber::class;
    $tiles = [];

    $tiles[] = [
        'label' => 'Personal BV',
        'value' => $personalBvPaise !== null ? \App\Modules\Commerce\Support\Bv::format($personalBvPaise) : '—',
        'sub'   => $title?->title ? 'Title: '.$title->title : 'Lifetime, from your own purchases',
        'href'  => route('bv-ledger.index'),
        'tone'  => 'leaf',
        'tip'   => 'Business Volume from every product you have purchased yourself, since you joined. Your personal sales title is based on this figure.',
        'svg'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/>',
    ];
    $tiles[] = [
        'label' => 'Wallet balance',
        'value' => $walletBalancePaise !== null ? $fmt::rupees($walletBalancePaise) : '₹—',
        'sub'   => 'Credited from your product sales',
        'href'  => route('income.wallet'),
        'tone'  => 'brand',
        'tip'   => 'Bonus income already credited to your wallet from product sales. It transfers to your bank on payout days after the 3% admin charge, 5% TDS and any repurchase deduction.',
        'svg'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3"/>',
    ];
    if ($gsbOn) {
        $leftToday  = $genosBvEligible ? (int) ($dailyBv?->left_bv_paise ?? 0) : 0;
        $rightToday = $genosBvEligible ? (int) ($dailyBv?->right_bv_paise ?? 0) : 0;
        $genosTip = $genosBvEligible
            ? 'Business Volume from product purchases in this group so far today. It locks at the 23:59 IST cut-off.'
            : 'Genos BV starts counting once your lifetime personal BV reaches the plan minimum'.($gsbMinBvPaise !== null ? ' ('.\App\Modules\Commerce\Support\Bv::format($gsbMinBvPaise).')' : '').'.';
        $tiles[] = [
            'label' => '← Left Genos BV',
            'value' => \App\Modules\Commerce\Support\Bv::format($leftToday),
            'sub'   => 'Today, before cut-off',
            'href'  => route('income.genos-bv'),
            'tone'  => 'sky',
            'tip'   => $genosTip,
            'svg'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>',
        ];
        $tiles[] = [
            'label' => 'Right Genos BV →',
            'value' => \App\Modules\Commerce\Support\Bv::format($rightToday),
            'sub'   => 'Today, before cut-off',
            'href'  => route('income.genos-bv'),
            'tone'  => 'indigo',
            'tip'   => $genosTip,
            'svg'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>',
        ];
    }
    $tiles[] = [
        'label' => 'Total team',
        'value' => $fmt::format((int) ($teamStats['total_team'] ?? 0)),
        'sub'   => 'Members in your Genos downline',
        'roster' => 'total',
        'tone'  => 'brand',
        'tip'   => 'Everyone placed below you in the Genos, on both sides. Click to see the list.',
        'svg'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/>',
    ];
    $tiles[] = [
        'label' => 'Direct referrals',
        'value' => $fmt::format((int) ($teamStats['direct_referrals'] ?? 0)),
        'sub'   => 'People you personally invited',
        'roster' => 'direct',
        'tone'  => 'sunrise',
        'tip'   => 'Distributors who registered with your referral link. Click to see the list.',
        'svg'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/>',
    ];

    $toneClasses = [
        'brand'   => ['card' => 'border-brand-200 bg-gradient-to-br from-brand-50 via-white to-white hover:border-brand-300',       'bar' => 'from-brand-400 to-brand-600',     'icon' => 'bg-brand-500 text-white shadow-brand-500/30',     'value' => 'text-brand-800',   'ring' => 'focus:ring-brand-500'],
        'leaf'    => ['card' => 'border-leaf-200 bg-gradient-to-br from-leaf-50 via-white to-white hover:border-leaf-300',           'bar' => 'from-leaf-400 to-leaf-600',       'icon' => 'bg-leaf-500 text-white shadow-leaf-500/30',       'value' => 'text-leaf-800',    'ring' => 'focus:ring-leaf-500'],
        'sky'     => ['card' => 'border-sky-200 bg-gradient-to-br from-sky-50 via-white to-white hover:border-sky-300',               'bar' => 'from-sky-400 to-sky-600',         'icon' => 'bg-sky-500 text-white shadow-sky-500/30',         'value' => 'text-sky-800',     'ring' => 'focus:ring-sky-500'],
        'indigo'  => ['card' => 'border-indigo-200 bg-gradient-to-br from-indigo-50 via-white to-white hover:border-indigo-300',     'bar' => 'from-indigo-400 to-indigo-600',   'icon' => 'bg-indigo-500 text-white shadow-indigo-500/30',   'value' => 'text-indigo-800',  'ring' => 'focus:ring-indigo-500'],
        'sunrise' => ['card' => 'border-sunrise-200 bg-gradient-to-br from-sunrise-50 via-white to-white hover:border-sunrise-300', 'bar' => 'from-sunrise-400 to-sunrise-600', 'icon' => 'bg-sunrise-500 text-white shadow-sunrise-500/30', 'value' => 'text-sunrise-800', 'ring' => 'focus:ring-sunrise-500'],
    ];
    $gridCols = count($tiles) === 6 ? 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-6' : 'grid-cols-2 lg:grid-cols-4';
@endphp

<div class="grid {{ $gridCols }} gap-3 sm:gap-4 mb-6">
    @foreach($tiles as $tile)
        @php
            $tone = $toneClasses[$tile['tone']];
            // Roster tiles cannot be <button>s: the help-tip inside is itself a
            // button and nested buttons are invalid HTML (the parser splits them).
            // The roster script binds on [data-team-roster], so a div works.
            $isButton = isset($tile['roster']);
            $tag = $isButton ? 'div' : 'a';
            $attrs = $isButton
                ? 'role="button" tabindex="0" data-team-roster="'.$tile['roster'].'" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();this.click();}"'
                : 'href="'.$tile['href'].'"';
        @endphp
        <{{ $tag }} {!! $attrs !!}
            class="group relative block cursor-pointer overflow-hidden text-left rounded-2xl border {{ $tone['card'] }} p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 {{ $tone['ring'] }}">
            <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r {{ $tone['bar'] }}" aria-hidden="true"></span>
            <div class="flex items-start justify-between gap-2 mb-3">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl shadow-md {{ $tone['icon'] }}">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $tile['svg'] !!}</svg>
                </span>
                <x-help-tip :text="$tile['tip']" />
            </div>
            <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">{{ $tile['label'] }}</p>
            <p class="mt-1 text-xl sm:text-2xl font-bold {{ $tone['value'] }} leading-tight truncate">{{ $tile['value'] }}</p>
            <p class="mt-1 text-[11px] text-gray-600 leading-snug">{{ $tile['sub'] }}</p>
        </{{ $tag }}>
    @endforeach
</div>
