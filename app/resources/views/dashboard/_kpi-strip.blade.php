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
        'icon'   => 'shopping-bag',
    ];
    $tiles[] = [
        'label' => 'Wallet balance',
        'value' => $walletBalancePaise !== null ? $fmt::rupees($walletBalancePaise) : '₹—',
        'sub'   => 'Credited from your product sales',
        'href'  => route('income.wallet'),
        'tone'  => 'brand',
        'tip'   => 'Bonus income already credited to your wallet from product sales. It transfers to your bank on payout days after the 3% admin charge, 5% TDS and any repurchase deduction.',
        'icon'   => 'wallet',
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
            'icon'   => 'arrow-left',
        ];
        $tiles[] = [
            'label' => 'Right Genos BV →',
            'value' => \App\Modules\Commerce\Support\Bv::format($rightToday),
            'sub'   => 'Today, before cut-off',
            'href'  => route('income.genos-bv'),
            'tone'  => 'indigo',
            'tip'   => $genosTip,
            'icon'   => 'arrow-right',
        ];
    }
    $tiles[] = [
        'label' => 'Total team',
        'value' => $fmt::format((int) ($teamStats['total_team'] ?? 0)),
        'sub'   => 'Members in your Genos downline',
        'roster' => 'total',
        'tone'  => 'brand',
        'tip'   => 'Everyone placed below you in the Genos, on both sides. Click to see the list.',
        'icon'   => 'users-round',
    ];
    $tiles[] = [
        'label' => 'Direct referrals',
        'value' => $fmt::format((int) ($teamStats['direct_referrals'] ?? 0)),
        'sub'   => 'People you personally invited',
        'roster' => 'direct',
        'tone'  => 'sunrise',
        'tip'   => 'Distributors who registered with your referral link. Click to see the list.',
        'icon'   => 'user-plus',
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
                    {{ svg('lucide-'.$tile['icon'], 'w-5 h-5') }}
                </span>
                <x-help-tip :text="$tile['tip']" />
            </div>
            <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">{{ $tile['label'] }}</p>
            <p class="mt-1 text-xl sm:text-2xl font-bold {{ $tone['value'] }} leading-tight truncate">{{ $tile['value'] }}</p>
            <p class="mt-1 text-[11px] text-gray-600 leading-snug">{{ $tile['sub'] }}</p>
        </{{ $tag }}>
    @endforeach
</div>
