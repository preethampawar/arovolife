{{-- Shared public top nav — used by landing, shop, wizard, dashboard, content pages --}}

@php
    // Nav items support two shapes:
    //   flat link  — ['label', 'route'|'url', 'match']
    //   dropdown   — ['label', 'dropdown' => true, 'match', 'children' => [['label','url'],...]]
    $navItems = [
        ['label' => 'Home',     'route' => 'home',           'match' => ['home']],
        [
            'label'    => 'About',
            'dropdown' => true,
            'match'    => ['about'],
            'children' => [
                ['label' => 'About Us',               'url' => route('about')],
                ['label' => 'Management Team',        'url' => '/p/management-team'],
                ['label' => 'Business Opportunities', 'url' => '/p/business-opportunities'],
                ['label' => 'Success Story',          'url' => '/p/success-story'],
            ],
        ],
        ['label' => 'News',     'route' => 'public.news.index',    'match' => ['public.news.index']],
        ['label' => 'Shop',     'route' => 'shop.index',           'match' => ['shop.index', 'shop.product', 'shop.cart', 'shop.checkout', 'shop.confirmation']],
        ['label' => 'Blogs',    'route' => 'public.blogs.index',   'match' => ['public.blogs.index']],
        ['label' => 'Contact',  'route' => 'contact.show',         'match' => ['contact.show']],
        ['label' => 'Seminars', 'route' => 'public.seminars.index','match' => ['public.seminars.index']],
        // Arovo Hub (Health Services / Social Contribution / Online Courses)
        // is hidden until its pages are written — client, 2026-08-29. The
        // /arovo-hub route and the hub content-page type stay in place.
    ];
@endphp

{{-- The whole header — utility strip + main nav — sticks to the top together. --}}
<header class="sticky top-0 z-40">
{{-- Utility strip: deep blue. Hidden below sm so the mobile header doesn't
     stack two coloured strips. --}}
<div class="hidden sm:block bg-brand-700 text-white text-xs">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2 flex items-center justify-end gap-3 sm:gap-4 flex-wrap">
        @guest
            <a href="{{ route('join.show') }}" class="hover:text-brand-50 transition-colors">Register with us</a>
            <span class="text-brand-400">|</span>
            <a href="{{ route('login') }}" class="hover:text-brand-50 transition-colors">Sign in</a>
        @else
            @php
                $user = auth()->user();
                $isAdmin = $user->isSuperStaff();
                $name = $user->full_name ?: explode('@', (string) $user->email)[0];
                $initials = collect(preg_split('/\s+/', trim((string) $name)))
                    ->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
                if ($initials === '') { $initials = mb_strtoupper(mb_substr((string) $user->email, 0, 1)); }
            @endphp

            {{-- Profile dropdown (replaces the old "My Dashboard | Sign out" pair) --}}
            <div class="relative" data-profile-menu>
                <button type="button" data-profile-trigger
                        class="inline-flex items-center gap-2 px-2 py-0.5 rounded-full hover:bg-brand-800 transition-colors"
                        aria-haspopup="menu" aria-expanded="false">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-white text-brand-700 text-[10px] font-bold leading-none">{{ $initials }}</span>
                    <span class="font-medium">{{ $name }}</span>
                    <x-lucide-chevron-down class="w-3 h-3" />
                </button>
                <div data-profile-panel hidden
                     class="w-64 rounded-xl bg-white shadow-lg ring-1 ring-gray-200 text-gray-900"
                     style="position: fixed; z-index: 9999;"
                     role="menu">
                    <div class="px-4 py-3 border-b border-gray-100">
                        <p class="text-sm font-semibold leading-tight truncate">{{ $name }}</p>
                        {{-- Email shown to admins only; hidden for distributors. --}}
                        @if($isAdmin)
                        <p class="text-[11px] text-gray-600 truncate mt-0.5">{{ $user->email }}</p>
                        @endif
                        @if($isAdmin)
                            <span class="mt-1.5 inline-block text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded-full bg-sunrise-100 text-sunrise-800 font-bold">Admin</span>
                        @endif
                    </div>
                    <div class="py-1">
                        @if($isAdmin)
                            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                                <x-lucide-layout-grid class="w-4 h-4 text-gray-600" />
                                Admin Console
                            </a>
                        @else
                            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                                <x-lucide-house class="w-4 h-4 text-gray-600" />
                                My Dashboard
                            </a>
                        @endif
                        @if(! $isAdmin && $user->distributor)
                        <a href="{{ route('my-business') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <x-lucide-briefcase class="w-4 h-4 text-gray-600" />
                            My Business
                        </a>
                        @if (\Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\PurchaseOffersFeature::class) && auth()->user()?->distributor)
                        <a href="{{ route('my.offers.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <span aria-hidden="true">🎁</span> My offers
                        </a>
                        @endif
                        @if (\Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\AreteCenterApplicationsFeature::class) && auth()->user()?->distributor)
                        <a href="{{ route('my.adc.status') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <span aria-hidden="true">🏛</span> My Arete Centre
                        </a>
                        @endif
                        <a href="{{ route('my.adc.directory') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <span aria-hidden="true">📍</span> Arete Centres
                        </a>
                        @if (\Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\DistributorRequestsFeature::class) && auth()->user()?->distributor)
                        <a href="{{ route('my.requests.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <span aria-hidden="true">📝</span> My Requests
                        </a>
                        @endif
                        <a href="{{ route('orders.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <x-lucide-clipboard-list class="w-4 h-4 text-gray-600" />
                            My Orders &amp; Sales
                        </a>
                        <a href="{{ route('bv-ledger.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <x-lucide-chart-column class="w-4 h-4 text-gray-600" />
                            My BV Ledger
                        </a>
                        @endif
                        <a href="{{ route('addresses.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <x-lucide-map-pin class="w-4 h-4 text-gray-600" />
                            My Addresses
                        </a>
                        <a href="{{ route('profile.show') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <x-lucide-user class="w-4 h-4 text-gray-600" />
                            Edit profile
                        </a>
                        <a href="{{ route('profile.password.show') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">
                            <x-lucide-lock class="w-4 h-4 text-gray-600" />
                            Change password
                        </a>
                    </div>
                    <div class="border-t border-gray-100 py-1">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50" role="menuitem">
                                <x-lucide-log-out class="w-4 h-4" />
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @endguest
        <span class="text-brand-400">|</span>

        {{-- A11y: Font-size adjuster. Sets the root <html> font-size as a
             percentage (90 / 100 / 115 / 130). Because the rest of the
             codebase already uses rem-based / `text-*` Tailwind classes,
             every page scales from this single property. Choice persists
             in localStorage under `arovolife_root_font_size_pct` and is
             also restored in the layout <head> to prevent FOUC. --}}
        <div class="font-size-adjuster inline-flex items-center gap-0.5 px-1 py-0.5 rounded-full bg-brand-600/40"
             role="group" aria-label="Adjust font size" data-font-size-adjuster>
            <button type="button" data-font-size="90"
                    class="w-7 h-6 inline-flex items-center justify-center rounded-full text-[11px] font-semibold text-white/90 hover:text-white hover:bg-brand-700 transition-colors data-[active=true]:bg-white data-[active=true]:text-brand-700 data-[active=true]:ring-2 data-[active=true]:ring-sunrise-300"
                    title="Smaller text (90%)" aria-label="Smaller text">A<span class="text-[9px]">−</span></button>
            <button type="button" data-font-size="100"
                    class="w-7 h-6 inline-flex items-center justify-center rounded-full text-[11px] font-semibold text-white/90 hover:text-white hover:bg-brand-700 transition-colors data-[active=true]:bg-white data-[active=true]:text-brand-700 data-[active=true]:ring-2 data-[active=true]:ring-sunrise-300"
                    title="Default text (100%)" aria-label="Default text">A</button>
            <button type="button" data-font-size="115"
                    class="w-7 h-6 inline-flex items-center justify-center rounded-full text-[11px] font-semibold text-white/90 hover:text-white hover:bg-brand-700 transition-colors data-[active=true]:bg-white data-[active=true]:text-brand-700 data-[active=true]:ring-2 data-[active=true]:ring-sunrise-300"
                    title="Larger text (115%)" aria-label="Larger text">A<span class="text-[12px]">+</span></button>
            <button type="button" data-font-size="130"
                    class="w-7 h-6 inline-flex items-center justify-center rounded-full text-[11px] font-semibold text-white/90 hover:text-white hover:bg-brand-700 transition-colors data-[active=true]:bg-white data-[active=true]:text-brand-700 data-[active=true]:ring-2 data-[active=true]:ring-sunrise-300"
                    title="Largest text (130%)" aria-label="Largest text">A<span class="text-[14px]">++</span></button>
            <button type="button" data-font-size="100" data-font-size-reset
                    class="w-7 h-6 inline-flex items-center justify-center rounded-full text-[13px] font-semibold text-white/90 hover:text-white hover:bg-brand-700 transition-colors"
                    title="Reset to default" aria-label="Reset font size">↺</button>
        </div>
        <span class="text-brand-400">|</span>

        @auth
            {{-- Admins jump to their console; distributors jump to their
                 dashboard ("My Dashboard"). Guests keep the About Us link
                 since they have no dashboard to jump to. --}}
            @if(auth()->user()->isSuperStaff())
                <a href="{{ route('admin.dashboard') }}" class="hover:text-brand-50 transition-colors">Admin Console</a>
            @else
                <a href="{{ route('dashboard') }}" class="hover:text-brand-50 transition-colors">My Dashboard</a>
                @if(auth()->user()->distributor)
                    <span class="text-brand-400">|</span>
                    <a href="{{ route('my-business') }}" class="hover:text-brand-50 transition-colors">My Business</a>
                @endif
            @endif
        @else
            <a href="{{ route('about') }}" class="hover:text-brand-50 transition-colors">About Us</a>
        @endauth
        <span class="text-brand-400">|</span>
        <span>India 🇮🇳</span>
    </div>
</div>

{{-- Font-size adjuster JS: applies the saved value on every page load AND
     wires up the buttons. The same `apply` call is also inlined into the
     <head> of every layout that uses this nav, to prevent FOUC (no flash
     of the wrong size before this script runs). --}}
<script>
    (() => {
        const root = document.documentElement;
        const KEY = 'arovolife_root_font_size_pct';
        const VALID = [90, 100, 115, 130];
        const groups = document.querySelectorAll('[data-font-size-adjuster]');
        if (groups.length === 0) return;

        const readSaved = () => {
            const raw = parseInt(localStorage.getItem(KEY) || '100', 10);
            return VALID.includes(raw) ? raw : 100;
        };
        const markActive = (pct) => {
            groups.forEach((g) => {
                g.querySelectorAll('[data-font-size]').forEach((btn) => {
                    const isReset = btn.hasAttribute('data-font-size-reset');
                    btn.dataset.active = (!isReset && parseInt(btn.dataset.fontSize, 10) === pct) ? 'true' : 'false';
                });
            });
        };
        const apply = (pct) => {
            root.style.fontSize = pct + '%';
            try { localStorage.setItem(KEY, String(pct)); } catch (e) { /* private mode */ }
            markActive(pct);
        };

        // Initial sync — the <head> inline script has already set the inline
        // style; this just paints the active-button highlight.
        apply(readSaved());

        groups.forEach((g) => {
            g.querySelectorAll('[data-font-size]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const pct = parseInt(btn.dataset.fontSize, 10);
                    if (VALID.includes(pct)) apply(pct);
                });
            });
        });
    })();
</script>

{{-- Main nav (sticky is handled by the wrapping <header>). --}}
<nav class="bg-brand-700 border-b border-brand-600">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 flex items-center gap-4 sm:gap-6 lg:gap-8">

        <a href="{{ route('home') }}" class="flex items-center shrink-0 py-2.5">
            <img src="{{ asset('assets/arovolife-logos/arovolife-white-logo.png') }}" alt="arovolife" class="h-8 sm:h-10 w-auto">
        </a>

        {{-- Desktop nav (lg+) --}}
        <div class="hidden lg:flex items-center gap-7 text-sm ml-auto">
            @foreach($navItems as $navIdx => $item)
                @php
                    $active = !empty($item['match']) && collect($item['match'])->contains(fn ($r) => request()->routeIs($r));
                @endphp
                @if(!empty($item['dropdown']))
                    {{-- Dropdown nav item --}}
                    @php $ddKey = 'nav-dd-'.$navIdx; @endphp
                    <div class="relative" data-nav-dd="{{ $ddKey }}">
                        <button type="button" data-nav-dd-trigger="{{ $ddKey }}" aria-haspopup="menu" aria-expanded="false"
                            class="py-5 font-medium transition-colors inline-flex items-center gap-1
                                   {{ $active ? 'text-white border-b-2 border-white -mb-px' : 'text-brand-50 hover:text-white' }}">
                            {{ $item['label'] }}
                            <x-lucide-chevron-down class="w-3.5 h-3.5" />
                        </button>
                        <div data-nav-dd-panel="{{ $ddKey }}" hidden role="menu"
                            class="absolute left-0 top-full w-52 rounded-xl bg-white shadow-lg ring-1 ring-gray-200 text-gray-900 py-1 z-[60]">
                            @foreach($item['children'] as $child)
                                <a href="{{ $child['url'] }}" class="block px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">{{ $child['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                @else
                    @php $href = $item['url'] ?? route($item['route']); @endphp
                    <a href="{{ $href }}"
                       class="py-5 font-medium transition-colors
                              {{ $active
                                 ? 'text-white border-b-2 border-white -mb-px'
                                 : 'text-brand-50 hover:text-white' }}">
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach

            {{-- Categories mega-dropdown (Atomy-style), built from the category master. --}}
            @if(($navCategories ?? collect())->isNotEmpty())
            <div class="relative" data-cat-menu>
                <button type="button" data-cat-trigger aria-haspopup="menu" aria-expanded="false"
                    class="py-5 font-medium text-brand-50 hover:text-white transition-colors inline-flex items-center gap-1">
                    Categories
                    <x-lucide-chevron-down class="w-3.5 h-3.5" />
                </button>
                <div data-cat-panel hidden role="menu"
                    class="absolute right-0 top-full w-56 rounded-xl bg-white shadow-lg ring-1 ring-gray-200 text-gray-900 py-1 z-[60]">
                    <a href="{{ route('shop.index') }}" class="block px-4 py-2 text-sm font-medium hover:bg-gray-50" role="menuitem">All products</a>
                    <div class="border-t border-gray-100 my-1"></div>
                    @foreach($navCategories as $c)
                    <a href="{{ route('shop.index', ['category' => $c->slug]) }}" class="block px-4 py-2 text-sm hover:bg-gray-50" role="menuitem">{{ $c->name }}</a>
                    @endforeach
                </div>
            </div>
            @endif

            @include('partials._notification-bell', ['bellLayout' => 'flex items-center py-5'])

            @php $cartItemCount = $cartItemCount ?? 0; @endphp
            <a href="{{ route('shop.cart') }}"
               class="relative flex items-center gap-2 {{ $cartItemCount > 0 ? 'text-white' : 'text-brand-50' }} hover:text-white transition-colors py-5"
               aria-label="Cart{{ $cartItemCount > 0 ? ' — '.$cartItemCount.' item'.($cartItemCount === 1 ? '' : 's') : '' }}">
                <span class="relative inline-flex">
                    <x-lucide-shopping-cart class="w-5 h-5" />
                    <span data-cart-count class="absolute -top-2 -right-2.5 inline-flex items-center justify-center min-w-[17px] h-[17px] px-1 rounded-full bg-white text-brand-700 text-[10px] font-bold leading-none shadow ring-1 ring-brand-500/20 {{ $cartItemCount > 0 ? '' : 'hidden' }}">{{ $cartItemCount > 99 ? '99+' : $cartItemCount }}</span>
                </span>
            </a>

            @guest
            <a href="{{ route('login') }}"
               class="px-4 py-2 rounded-full bg-white hover:bg-brand-50 text-brand-700 text-xs font-semibold transition-colors shadow-sm">
                Sign In
            </a>
            @endguest
        </div>

        {{-- Mobile/tablet right side: bell + cart + hamburger --}}
        <div class="flex lg:hidden items-center gap-1 ml-auto">
            @include('partials._notification-bell', ['bellLayout' => 'w-10 h-10 inline-flex items-center justify-center'])

            <a href="{{ route('shop.cart') }}"
               class="relative w-10 h-10 inline-flex items-center justify-center {{ ($cartItemCount ?? 0) > 0 ? 'text-white' : 'text-brand-50' }} hover:text-white transition-colors"
               aria-label="Cart{{ ($cartItemCount ?? 0) > 0 ? ' — '.$cartItemCount.' item'.($cartItemCount === 1 ? '' : 's') : '' }}">
                <span class="relative inline-flex">
                    <x-lucide-shopping-cart class="w-5 h-5" />
                    <span data-cart-count class="absolute -top-2 -right-2.5 inline-flex items-center justify-center min-w-[17px] h-[17px] px-1 rounded-full bg-white text-brand-700 text-[10px] font-bold leading-none shadow ring-1 ring-brand-500/20 {{ ($cartItemCount ?? 0) > 0 ? '' : 'hidden' }}">{{ ($cartItemCount ?? 0) > 99 ? '99+' : ($cartItemCount ?? 0) }}</span>
                </span>
            </a>
            <button type="button"
                onclick="document.getElementById('mobileNavDrawer').classList.toggle('hidden')"
                class="w-10 h-10 inline-flex items-center justify-center text-white rounded-md hover:bg-brand-800 transition-colors"
                aria-label="Open menu">
                <x-lucide-menu class="w-6 h-6" />
            </button>
        </div>
    </div>

    {{-- Profile-dropdown toggle (vanilla JS, no Alpine dependency). The
         panel is teleported to <body> on first open so it isn't trapped
         in `.wizard-stage > * { z-index: 1 }`'s stacking context. Once
         it's a direct child of body, `position: fixed; z-index: 9999`
         actually wins against the dashboard cards and the sticky nav. --}}
    @auth
    <script>
        (function () {
            const wrapper = document.querySelector('[data-profile-menu]');
            if (!wrapper) return;
            const trigger = wrapper.querySelector('[data-profile-trigger]');
            const panel   = wrapper.querySelector('[data-profile-panel]');

            // Move panel to <body> once so its z-index is compared at the
            // document root, escaping the wizard-stage stacking trap.
            let portalled = false;
            const portal = () => {
                if (portalled) return;
                document.body.appendChild(panel);
                portalled = true;
            };

            const place = () => {
                const r = trigger.getBoundingClientRect();
                panel.style.top   = (r.bottom + 8) + 'px';
                panel.style.right = (window.innerWidth - r.right) + 'px';
                panel.style.left  = 'auto';
            };
            const close = () => { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); };
            const open  = () => { portal(); place(); panel.hidden = false; trigger.setAttribute('aria-expanded', 'true'); };

            trigger.addEventListener('click', (e) => { e.stopPropagation(); panel.hidden ? open() : close(); });
            document.addEventListener('click', (e) => { if (!wrapper.contains(e.target) && !panel.contains(e.target)) close(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
            window.addEventListener('resize', () => { if (!panel.hidden) place(); });
            window.addEventListener('scroll', () => { if (!panel.hidden) place(); }, { passive: true });
        })();
    </script>
    @endauth

    {{-- Categories dropdown toggle (available to guests + members). --}}
    <script>
        (function () {
            var menu = document.querySelector('[data-cat-menu]');
            if (!menu) return;
            var trigger = menu.querySelector('[data-cat-trigger]');
            var panel = menu.querySelector('[data-cat-panel]');
            function close() { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); }
            function open() { panel.hidden = false; trigger.setAttribute('aria-expanded', 'true'); }
            trigger.addEventListener('click', function (e) { e.stopPropagation(); panel.hidden ? open() : close(); });
            document.addEventListener('click', function (e) { if (!menu.contains(e.target)) close(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        })();
    </script>

    {{-- Nav dropdown toggles (About, Arovo Hub, etc. — each identified by a data-nav-dd key). --}}
    <script>
        (function () {
            document.querySelectorAll('[data-nav-dd]').forEach(function (menu) {
                var key     = menu.getAttribute('data-nav-dd');
                var trigger = menu.querySelector('[data-nav-dd-trigger="' + key + '"]');
                var panel   = menu.querySelector('[data-nav-dd-panel="' + key + '"]');
                if (!trigger || !panel) { return; }
                function close() { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); }
                function open()  { panel.hidden = false; trigger.setAttribute('aria-expanded', 'true'); }
                trigger.addEventListener('click', function (e) { e.stopPropagation(); panel.hidden ? open() : close(); });
                document.addEventListener('click', function (e) { if (!menu.contains(e.target)) close(); });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
            });
        })();
    </script>

    {{-- Mobile drawer (slides down under the header) --}}
    <div id="mobileNavDrawer" class="hidden lg:hidden bg-brand-700 border-t border-brand-600">
        <div class="px-4 py-2 flex flex-col text-sm">
            @foreach($navItems as $item)
                @php
                    $active = !empty($item['match']) && collect($item['match'])->contains(fn ($r) => request()->routeIs($r));
                @endphp
                @if(!empty($item['dropdown']))
                    <p class="px-2 pt-3 pb-1 text-xs text-brand-200 font-semibold uppercase tracking-wider">{{ $item['label'] }}</p>
                    @foreach($item['children'] as $child)
                        <a href="{{ $child['url'] }}"
                           class="py-2 px-4 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors">
                            {{ $child['label'] }}
                        </a>
                    @endforeach
                @else
                    @php $href = $item['url'] ?? route($item['route']); @endphp
                    <a href="{{ $href }}"
                       class="py-2.5 px-2 rounded-md font-medium transition-colors
                              {{ $active ? 'text-white bg-brand-600' : 'text-brand-50 hover:text-white hover:bg-brand-800' }}">
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach

            <div class="border-t border-brand-600 my-2"></div>

            @guest
                <a href="{{ route('login') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">Sign in</a>
                <a href="{{ route('contact.show') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">Become a Direct Seller</a>
            @else
                <p class="px-2 pt-2 pb-1 text-xs text-brand-200 font-semibold uppercase tracking-wider">Signed in as {{ auth()->user()->full_name ?: auth()->user()->email }}</p>
                @if(auth()->user()->isSuperStaff())
                    <a href="{{ route('admin.dashboard') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">Admin Console</a>
                @else
                    <a href="{{ route('dashboard') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">My Dashboard</a>
                @endif
                @if(! auth()->user()->isSuperStaff() && auth()->user()->distributor)
                <a href="{{ route('my-business') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">My Business</a>
                @if (\Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\PurchaseOffersFeature::class) && auth()->user()?->distributor)
                <a href="{{ route('my.offers.index') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">My Offers</a>
                @endif
                @if (\Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\AreteCenterApplicationsFeature::class) && auth()->user()?->distributor)
                <a href="{{ route('my.adc.status') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">My Arete Centre</a>
                @endif
                <a href="{{ route('my.adc.directory') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">Arete Centres</a>
                @if (\Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\DistributorRequestsFeature::class) && auth()->user()?->distributor)
                <a href="{{ route('my.requests.index') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">My Requests</a>
                @endif
                <a href="{{ route('orders.index') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">My Orders &amp; Sales</a>
                <a href="{{ route('bv-ledger.index') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">My BV Ledger</a>
                @endif
                <a href="{{ route('addresses.index') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">My Addresses</a>
                <a href="{{ route('profile.show') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">Edit profile</a>
                <a href="{{ route('profile.password.show') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">Change password</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-800 transition-colors font-medium">Sign out</button>
                </form>
            @endguest
        </div>
    </div>
</nav>
</header>
