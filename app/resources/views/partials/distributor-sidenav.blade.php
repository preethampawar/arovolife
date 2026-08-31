{{-- Distributor side navigation — included by layouts.app for signed-in
     distributors on lg+ screens. Phones keep the top-nav profile menu, so
     this list must stay in sync with the profile dropdown in
     partials/public-topnav (same routes, same feature gating). --}}
@php
    $offersOn = \Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\PurchaseOffersFeature::class);
    $adcApplicationsOn = \Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\AreteCenterApplicationsFeature::class);
    $requestsOn = \Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\DistributorRequestsFeature::class);

    $groups = [
        'Overview' => [
            ['label' => 'Dashboard',          'route' => 'dashboard',           'icon' => '⌂', 'prefix' => 'dashboard'],
            ['label' => 'My Business',        'route' => 'my-business',         'icon' => '📊'],
            ['label' => 'My Income',          'route' => 'income.dashboard',    'icon' => '💰', 'prefix' => 'income.'],
        ],
        'My Network' => [
            ['label' => 'My Genos',           'route' => 'tree.binary',         'icon' => '⌬'],
            ['label' => 'Sponsorship Tree',   'route' => 'tree.sponsorship',    'icon' => '⌥'],
            ['label' => 'Messages',           'route' => 'messages.index',      'icon' => '✉', 'prefix' => 'messages.'],
        ],
        'Shopping' => [
            ['label' => 'Shop',               'route' => 'shop.index',          'icon' => '🛍'],
            ['label' => 'My Orders & Sales',  'route' => 'orders.index',        'icon' => '📦', 'prefix' => 'orders.'],
            ['label' => 'My BV Ledger',       'route' => 'bv-ledger.index',     'icon' => '📈', 'prefix' => 'bv-ledger.'],
            ['label' => 'My Addresses',       'route' => 'addresses.index',     'icon' => '📍', 'prefix' => 'addresses.'],
            ...($offersOn
                ? [['label' => 'My Offers',   'route' => 'my.offers.index',     'icon' => '🎁', 'prefix' => 'my.offers.']]
                : []),
        ],
        'My Account' => [
            ['label' => 'My Profile',         'route' => 'profile.show',        'icon' => '👤', 'prefix' => 'profile.'],
            ['label' => 'Arete Centres',      'route' => 'my.adc.directory',    'icon' => '🏛'],
            ...($adcApplicationsOn
                ? [['label' => 'My Arete Centre', 'route' => 'my.adc.status',   'icon' => '🎓', 'prefix' => 'my.adc.status']]
                : []),
            ...($requestsOn
                ? [['label' => 'My Requests', 'route' => 'my.requests.index',   'icon' => '📝', 'prefix' => 'my.requests.']]
                : []),
            ['label' => 'My Grievances',      'route' => 'my.grievances.index', 'icon' => '📣', 'prefix' => 'my.grievances.'],
        ],
    ];
@endphp

<aside class="hidden lg:block" aria-label="My account navigation">
    <div class="lg:sticky lg:top-28 space-y-5 rounded-2xl border border-brand-100/80 bg-gradient-to-b from-white via-brand-50/50 to-leaf-50/40 shadow-sm p-4">
        @foreach($groups as $groupLabel => $items)
        <div>
            <p class="px-3 mb-1.5 text-[10px] uppercase tracking-[0.18em] text-gray-500 font-semibold">{{ $groupLabel }}</p>
            <nav class="space-y-0.5">
                @foreach($items as $item)
                    @php
                        $active = request()->routeIs($item['route'])
                            || (isset($item['prefix']) && request()->routeIs($item['prefix'].'*'));
                    @endphp
                    <a href="{{ route($item['route']) }}"
                       @if($active) aria-current="page" @endif
                       class="relative flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm transition-colors
                              {{ $active
                                 ? 'bg-brand-100/70 text-brand-800 font-semibold shadow-sm'
                                 : 'text-gray-700 hover:bg-white/80 hover:text-gray-900 font-medium' }}">
                        @if($active)
                        <span class="absolute left-0 top-1.5 bottom-1.5 w-1 rounded-r-full bg-brand-600"></span>
                        @endif
                        <span class="w-5 text-center {{ $active ? 'text-brand-700' : 'text-gray-500' }}" aria-hidden="true">{{ $item['icon'] }}</span>
                        <span class="flex-1 truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>
        @endforeach
    </div>
</aside>
