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
            ['label' => 'Dashboard',          'route' => 'dashboard',           'icon' => 'house', 'prefix' => 'dashboard'],
            ['label' => 'My Business',        'route' => 'my-business',         'icon' => 'chart-column'],
            ['label' => 'My Income',          'route' => 'income.dashboard',    'icon' => 'banknote', 'prefix' => 'income.'],
        ],
        'My Network' => [
            ['label' => 'My Genos',           'route' => 'tree.binary',         'icon' => 'network'],
            ['label' => 'Sponsorship Tree',   'route' => 'tree.sponsorship',    'icon' => 'git-branch'],
            ['label' => 'Messages',           'route' => 'messages.index',      'icon' => 'mail', 'prefix' => 'messages.'],
        ],
        'Shopping' => [
            ['label' => 'Shop',               'route' => 'shop.index',          'icon' => 'shopping-bag'],
            ['label' => 'My Orders & Sales',  'route' => 'orders.index',        'icon' => 'package', 'prefix' => 'orders.'],
            ['label' => 'My BV Ledger',       'route' => 'bv-ledger.index',     'icon' => 'trending-up', 'prefix' => 'bv-ledger.'],
            ['label' => 'My Addresses',       'route' => 'addresses.index',     'icon' => 'map-pin', 'prefix' => 'addresses.'],
            ...($offersOn
                ? [['label' => 'My Offers',   'route' => 'my.offers.index',     'icon' => 'gift', 'prefix' => 'my.offers.']]
                : []),
        ],
        'My Account' => [
            ['label' => 'My Profile',         'route' => 'profile.show',        'icon' => 'user', 'prefix' => 'profile.'],
            ['label' => 'Arete Centres',      'route' => 'my.adc.directory',    'icon' => 'landmark'],
            ...($adcApplicationsOn
                ? [['label' => 'My Arete Centre', 'route' => 'my.adc.status',   'icon' => 'graduation-cap', 'prefix' => 'my.adc.status']]
                : []),
            ...($requestsOn
                ? [['label' => 'My Requests', 'route' => 'my.requests.index',   'icon' => 'clipboard-list', 'prefix' => 'my.requests.']]
                : []),
            ['label' => 'My Grievances',      'route' => 'my.grievances.index', 'icon' => 'megaphone', 'prefix' => 'my.grievances.'],
        ],
    ];
@endphp

{{-- Desktop (lg+): sticky column. When the list is taller than the
     viewport the script below lowers the sticky offset so the column
     scrolls with the page until its bottom edge meets the viewport, then
     pins there — every entry stays reachable, no inner scrollbar. --}}
<aside class="hidden lg:block" aria-label="My account navigation">
    <div id="distributorSidenavSticky" class="lg:sticky lg:top-28 space-y-5 rounded-2xl border border-brand-100/80 bg-gradient-to-b from-white via-brand-50/50 to-leaf-50/40 shadow-sm p-4">
        @include('partials._distributor-sidenav-groups')
    </div>
</aside>

{{-- Mobile (below lg): trigger button + slide-over drawer --}}
<div class="lg:hidden mb-4">
    <button type="button" id="distributorNavBtn"
        class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition-colors">
        <x-lucide-menu class="w-4 h-4" />
        My Account menu
    </button>
</div>

<div id="distributorNavBackdrop" class="lg:hidden fixed inset-0 z-40 bg-gray-900/40 hidden"></div>

<aside id="distributorNavDrawer"
    class="lg:hidden fixed top-0 bottom-0 left-0 z-50 w-72 max-w-[85vw] overflow-y-auto border-r border-brand-100/80 bg-gradient-to-b from-white via-brand-50 to-leaf-50 shadow-xl -translate-x-full transition-transform duration-200 ease-out"
    aria-label="My account navigation">
    <div class="flex items-center justify-between px-4 pt-4 pb-2">
        <p class="text-[11px] uppercase tracking-[0.2em] text-brand-700 font-semibold">My Account</p>
        <button type="button" id="distributorNavCloseBtn" aria-label="Close menu"
            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-500 hover:bg-white/80 hover:text-gray-800 transition-colors">
            <x-lucide-x class="w-5 h-5" />
        </button>
    </div>
    <div class="space-y-5 p-4 pt-2">
        @include('partials._distributor-sidenav-groups')
    </div>
</aside>

{{-- Drawer toggle — vanilla JS, same pattern as the admin sidebar drawer. --}}
<script>
    (function () {
        const btn = document.getElementById('distributorNavBtn');
        const closeBtn = document.getElementById('distributorNavCloseBtn');
        const drawer = document.getElementById('distributorNavDrawer');
        const backdrop = document.getElementById('distributorNavBackdrop');
        if (! btn || ! drawer || ! backdrop) return;

        // Move both to <body> so their z-indexes are compared at the document
        // root. Inside the content grid they're trapped by the wizard-stage
        // rule (`.wizard-stage > * { position: relative; z-index: 1 }` on the
        // grid), which lets the sticky z-40 topnav paint over the z-50 drawer
        // — same escape the topnav profile dropdown uses.
        document.body.appendChild(backdrop);
        document.body.appendChild(drawer);

        const open = () => {
            drawer.classList.remove('-translate-x-full');
            backdrop.classList.remove('hidden');
            document.body.classList.add('overflow-hidden', 'lg:overflow-auto');
        };
        const close = () => {
            drawer.classList.add('-translate-x-full');
            backdrop.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        };

        btn.addEventListener('click', open);
        if (closeBtn) closeBtn.addEventListener('click', close);
        backdrop.addEventListener('click', close);
        drawer.querySelectorAll('a').forEach((a) => a.addEventListener('click', close));

        // Auto-close when the viewport widens into desktop (lg = 1024px).
        const mql = window.matchMedia('(min-width: 1024px)');
        const onChange = (e) => { if (e.matches) close(); };
        mql.addEventListener ? mql.addEventListener('change', onChange) : mql.addListener(onChange);
    })();

    // Desktop sticky offset. Default is 7rem below the viewport top (matches
    // lg:top-28). If the column is taller than the space below that, the
    // offset goes negative by the overflow so the column pins with its
    // bottom edge 1rem above the viewport bottom instead. Re-fitted on
    // resize and whenever the column's height changes (font-size toggle,
    // feature-gated entries).
    (function () {
        const el = document.getElementById('distributorSidenavSticky');
        if (! el) return;

        const fit = () => {
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
            const defaultTop = 7 * rem;
            const bottomGap = rem;
            const available = window.innerHeight - defaultTop - bottomGap;
            const overflow = el.offsetHeight - available;
            el.style.top = overflow > 0 ? `${defaultTop - overflow}px` : '';
        };

        fit();
        window.addEventListener('resize', fit);
        if ('ResizeObserver' in window) new ResizeObserver(fit).observe(el);
    })();
</script>
