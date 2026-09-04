<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — arovolife Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    <style>
        /* Admin sidebar: hide scrollbar by default, reveal a slim slate-tinted
           one on hover so the nav looks clean but stays usable when the
           viewport is short. Firefox uses scrollbar-width; WebKit uses
           the ::-webkit-scrollbar pseudo. */
        .admin-sidebar-scroll {
            scrollbar-width: none;
            scrollbar-color: transparent transparent;
            transition: scrollbar-color 0.2s ease;
        }
        .admin-sidebar-scroll::-webkit-scrollbar {
            width: 0;
            background: transparent;
        }
        .admin-sidebar-scroll:hover,
        .admin-sidebar-scroll:focus-within {
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.4) transparent; /* slate-400 @ 40% */
        }
        .admin-sidebar-scroll:hover::-webkit-scrollbar,
        .admin-sidebar-scroll:focus-within::-webkit-scrollbar {
            width: 6px;
        }
        .admin-sidebar-scroll:hover::-webkit-scrollbar-thumb,
        .admin-sidebar-scroll:focus-within::-webkit-scrollbar-thumb {
            background-color: rgba(148, 163, 184, 0.4); /* slate-400 @ 40% */
            border-radius: 4px;
        }
        .admin-sidebar-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
    </style>
    @stack('styles')
</head>
<body class="min-h-full bg-[#f4f7f6] text-gray-900 antialiased flex overflow-x-hidden">

    {{-- Sidebar — top-0/bottom-0 stretches the aside to the full viewport
         vertically (more robust than h-screen which can mis-resolve under
         position: fixed in Tailwind v4). The logo header is pinned at top,
         and a single scrollable region holds nav + footer; mt-auto pushes
         the footer to the bottom of that region when content fits, and
         scrolls it into view when the viewport is too short. --}}
    {{-- Mobile hamburger — only visible below the lg breakpoint. Toggles the
         sidebar drawer + backdrop via vanilla JS at the bottom of this file. --}}
    <button type="button" id="adminMobileMenuBtn" aria-label="Open menu"
        class="lg:hidden fixed top-3 left-3 z-50 w-10 h-10 rounded-lg bg-white border border-gray-200 shadow-sm flex items-center justify-center text-gray-700 hover:bg-gray-50 transition-colors">
        <x-lucide-menu class="w-5 h-5" />
    </button>

    {{-- Backdrop — only shown on mobile when sidebar is open. --}}
    <div id="adminSidebarBackdrop" class="lg:hidden fixed inset-0 z-30 bg-gray-900/40 hidden"></div>

    <aside id="adminSidebar"
        class="w-60 fixed top-0 bottom-0 left-0 z-40 bg-slate-900 border-r border-slate-800 flex flex-col
               -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-out">
        <div class="px-5 py-5 border-b border-slate-800 shrink-0">
            <a href="{{ route('admin.dashboard') }}" class="block">
                <img src="{{ asset('assets/arovolife-logos/arovolife-white-logo.png') }}" alt="arovolife" class="h-10 w-auto">
            </a>
            <span class="block text-[11px] text-sunrise-400 mt-1.5 tracking-wider uppercase font-semibold">Admin Console</span>
        </div>

        <div class="admin-sidebar-scroll flex-1 min-h-0 overflow-y-auto flex flex-col">
        <nav class="px-3 py-4 space-y-0.5">
            @php
                // Unread Contact-inquiries count for the sidebar badge.
                // Cached for 60s so this query doesn't run on every admin page.
                $unhandledContactCount = \Illuminate\Support\Facades\Cache::remember(
                    'admin.contact_inquiries.unhandled_count',
                    60,
                    fn () => \App\Modules\Public\Models\ContactInquiry::query()->whereNull('handled_at')->count(),
                );

                // Unsettled grievances for the sidebar badge. Same 60s cache —
                // a statutory SLA queue that nobody can see the size of is a
                // queue that gets missed.
                $openGrievanceCount = \Illuminate\Support\Facades\Cache::remember(
                    'admin.grievances.unsettled_count',
                    60,
                    fn () => \App\Modules\Grievance\Models\Ticket::query()->unsettled()->count(),
                );

                // Open distributor requests for the sidebar badge (flag-gated,
                // same 60s cache as the other queues).
                $distributorRequestsOn = \Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\DistributorRequestsFeature::class)
                    && (auth()->user()?->can('distributor.request.handle') ?? false);
                $openDistributorRequestCount = $distributorRequestsOn
                    ? \Illuminate\Support\Facades\Cache::remember(
                        'admin.distributor_requests.open_count',
                        60,
                        fn () => \App\Modules\Identity\Models\DistributorRequest::query()->open()->count(),
                    )
                    : 0;

                // Open Arete Centre applications for the sidebar badge. The
                // registry itself is always on; only the queue is flag-gated.
                $adcApplicationsOn = \Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\AreteCenterApplicationsFeature::class)
                    && (auth()->user()?->can('adc.application.review') ?? false);
                $openAdcApplicationCount = $adcApplicationsOn
                    ? \Illuminate\Support\Facades\Cache::remember(
                        'admin.adc_applications.open_count',
                        60,
                        fn () => \App\Modules\Compensation\Models\AreteCenterApplication::query()->open()->count(),
                    )
                    : 0;

                $navItems = [
                    ['route' => 'admin.dashboard',                'label' => 'Dashboard',      'icon' => 'layout-dashboard'],
                    ['route' => 'admin.distributors.index',       'label' => 'Distributors',   'icon' => 'users'],
                    // Staff register is super-staff only (route enforces role:admin|developer).
                    ...(auth()->user()?->isSuperStaff()
                        ? [['route' => 'admin.staff.index',       'label' => 'Staff users',    'icon' => 'users-round', 'prefix' => 'admin.staff']]
                        : []),
                    ['route' => 'admin.tree.show',                'label' => 'Genealogy tree', 'icon' => 'network', 'prefix' => 'admin.tree'],
                    // KYC is gated on `kyc.review` (R-17). Hiding the item
                    // rather than letting it 403 keeps admin-finance from
                    // walking into a wall on every shift.
                    ...(auth()->user()?->can('kyc.review')
                        ? [['route' => 'admin.kyc.index',            'label' => 'KYC review',     'icon' => 'file-check', 'prefix' => 'admin.kyc']]
                        : []),
                    ['route' => 'admin.line-changes.index',       'label' => 'Line changes',   'icon' => 'arrow-right-left', 'prefix' => 'admin.line-changes'],
                    ...($distributorRequestsOn
                        ? [['route' => 'admin.distributor-requests.index', 'label' => 'Distributor requests', 'icon' => 'clipboard-list', 'prefix' => 'admin.distributor-requests', 'badge' => $openDistributorRequestCount]]
                        : []),
                    ['route' => 'admin.contact-inquiries.index',  'label' => 'Contact Inbox',  'icon' => 'mail', 'prefix' => 'admin.contact-inquiries', 'badge' => $unhandledContactCount],
                    // Grievances are gated on `grievance.handle` (R-17: not
                    // admin-finance). Hiding the item rather than letting it
                    // 403 also keeps the open-complaint count out of view.
                    ...(auth()->user()?->can('grievance.handle')
                        ? [['route' => 'admin.grievances.index', 'label' => 'Grievances', 'icon' => 'megaphone', 'prefix' => 'admin.grievances', 'badge' => $openGrievanceCount]]
                        : []),
                    // Agreement §21 dormancy. Account discipline, so it follows
                    // the same permission as freeze / terminate.
                    ...(auth()->user()?->can('compliance.discipline')
                        ? [['route' => 'admin.dormancy.index', 'label' => 'Dormancy (§21)', 'icon' => 'hourglass', 'prefix' => 'admin.dormancy']]
                        : []),
                    ['route' => 'admin.commerce.orders.index',    'label' => 'Orders',         'icon' => 'shopping-cart', 'prefix' => 'admin.commerce.orders'],
                    // Payments and the unsettled-refunds worklist. Monitoring
                    // (`audit.read`), so every scoped role sees it; the badge
                    // is refunds needing a human — failed, or held past the
                    // 10-day return-receipt alert.
                    ...(auth()->user()?->can('audit.read')
                        ? [['route' => 'admin.payments.index', 'label' => 'Payments', 'icon' => 'credit-card', 'prefix' => 'admin.payments',
                            'badge' => \Illuminate\Support\Facades\Cache::remember('admin.payments.attention_count', 60, fn () => app(\App\Modules\Payments\Support\RefundWorklist::class)->attentionCount() + app(\App\Modules\Payments\Support\InvoiceGapWorklist::class)->count())]]
                        : []),
                    ['route' => 'admin.commerce.bv-ledger.index', 'label' => 'BV Ledger',      'icon' => 'chart-column', 'prefix' => 'admin.commerce.bv-ledger'],
                    ...(auth()->user()?->can('audit.read')
                        ? [['route' => 'admin.analytics.index',      'label' => 'Analytics',      'icon' => 'chart-line', 'prefix' => 'admin.analytics']]
                        : []),
                    ['route' => 'admin.compensation.overview',    'label' => 'Compensation',   'icon' => 'banknote', 'prefix' => 'admin.compensation'],
                    // Arete Development Centres are entities in their own right
                    // (Step 11, profile, member directory); the ADC bonus is a
                    // layer on top and lives under Compensation.
                    ['route' => 'admin.arete-centres.index',      'label' => 'Arete Centres',  'icon' => 'landmark', 'prefix' => 'admin.arete-centres', 'badge' => $openAdcApplicationCount],
                    ['route' => 'admin.commerce.coupons.index',   'label' => 'Coupons',        'icon' => 'tag', 'prefix' => 'admin.commerce.coupons'],
                    ...(\Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\PurchaseOffersFeature::class)
                        ? [['route' => 'admin.commerce.offers.index', 'label' => 'Offers', 'icon' => 'gift', 'prefix' => 'admin.commerce.offers']]
                        : []),
                    ['route' => 'admin.catalog.products.index',   'label' => 'Products',       'icon' => 'package', 'prefix' => 'admin.catalog.products'],
                    ['route' => 'admin.catalog.categories.index', 'label' => 'Categories',     'icon' => 'folder-tree', 'prefix' => 'admin.catalog.categories'],
                    ['route' => 'admin.catalog.banners.index',    'label' => 'Banners',        'icon' => 'image', 'prefix' => 'admin.catalog.banners'],
                    ['route' => 'admin.content.index',            'label' => 'Content Pages',  'icon' => 'file-text', 'prefix' => 'admin.content'],
                    ['route' => 'admin.compliance-documents.index','label' => 'Compliance Docs', 'icon' => 'shield-check', 'prefix' => 'admin.compliance-documents'],
                    ['route' => 'admin.settings',                 'label' => 'Settings',       'icon' => 'settings'],
                    ['route' => 'admin.feature-flags.index',      'label' => 'Feature flags',  'icon' => 'flag', 'prefix' => 'admin.feature-flags'],
                    ...(auth()->user()?->can('audit.read')
                        ? [['route' => 'admin.audit-log',            'label' => 'Audit Log',      'icon' => 'scroll-text']]
                        : []),
                    ['route' => 'admin.help.index',               'label' => 'Help & Reference', 'icon' => 'circle-help', 'prefix' => 'admin.help'],
                ];
            @endphp
            @foreach($navItems as $item)
                @php
                    $active = request()->routeIs($item['route'])
                        || (isset($item['prefix']) && request()->routeIs($item['prefix'].'*'));
                @endphp
                <a href="{{ route($item['route']) }}"
                   class="relative flex items-center gap-3 pl-4 pr-3 py-2.5 rounded-lg text-sm transition-colors
                          {{ $active
                             ? 'bg-slate-800 text-white font-semibold'
                             : 'text-slate-300 hover:bg-slate-800 hover:text-white font-medium' }}">
                    @if($active)
                    <span class="absolute left-0 top-1.5 bottom-1.5 w-1 rounded-r-full bg-sunrise-500"></span>
                    @endif
                    <span class="{{ $active ? 'text-sunrise-400' : 'text-slate-600' }}">{{ svg('lucide-'.$item['icon'], 'w-4 h-4') }}</span>
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if(!empty($item['badge']))
                        <span class="inline-flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full bg-sunrise-800 text-white text-[10px] font-bold leading-none">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

            <div class="mt-auto px-3 py-4 border-t border-slate-800">
                <p class="text-xs text-slate-600 px-3 mb-2 truncate font-medium">{{ auth()->user()->email }}</p>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-slate-300 font-medium hover:bg-slate-800 hover:text-red-400 transition-colors">
                        <span class="text-slate-600">⏻</span> Sign out
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Main. `min-w-0` is the critical fix: flex items default to
         min-width:auto which lets wide content (e.g. the genealogy tree
         canvas) push this wrapper wider than the viewport. min-w-0 lets
         the wrapper shrink and forces overflow to live inside its
         designated scroll container (#treeViewport).
         lg:ml-60 reserves space for the fixed sidebar on desktop; mobile
         has ml-0 because the sidebar is a slide-over drawer there. --}}
    <div class="ml-0 lg:ml-60 flex-1 min-h-screen flex flex-col min-w-0 max-w-full">
        {{-- Header + (on compensation pages) the compensation sub-nav travel
             together as one sticky block, so the sub-nav never has to guess
             the header's height as a top offset. --}}
        <div class="sticky top-0 z-20">
        <header class="bg-slate-800 border-b border-slate-900 pl-16 pr-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-sunrise-800 text-white shrink-0">Admin</span>
                <h1 class="text-sm sm:text-base font-semibold text-white tracking-tight truncate">@yield('heading', 'Admin Console')</h1>
            </div>
            <div class="flex items-center gap-2 sm:gap-4 text-[11px] sm:text-xs text-slate-300 font-medium whitespace-nowrap">
                <span class="hidden sm:inline">{{ now()->format('d M Y, H:i') }} IST</span>
                <span class="hidden sm:inline text-slate-500">|</span>
                <a href="{{ route('dashboard') }}" class="text-white hover:text-sunrise-300 transition-colors">← Distributor view</a>
            </div>
        </header>

        @includeWhen(
            request()->routeIs('admin.compensation.*') || request()->routeIs('admin.lifetime-awards.*'),
            'admin.compensation._nav'
        )
        </div>

        <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6 sm:py-8 min-w-0 max-w-full">
            {{-- Compensation pages carry an in-content page title as well as the
                 small header-bar one (client, 2026-08-28: the report's title must
                 be visible on the page itself, on every report, not just one).
                 It is the same `heading` section every view already declares,
                 so no page has to repeat it. --}}
            @if(request()->routeIs('admin.compensation.*') || request()->routeIs('admin.lifetime-awards.*'))
            @hasSection('heading')
            <h2 class="text-xl sm:text-2xl font-bold text-gray-900 tracking-tight mb-4">@yield('heading')</h2>
            @endif
            @endif

            {{-- Flash messages are rendered here for every admin page. Views must
                 NOT repeat these blocks — a page that renders its own
                 session('status') shows the message twice. --}}
            @if(session('status'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                {{ session('status') }}
            </div>
            @endif

            @if(session('error'))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 font-medium">
                {{ session('error') }}
            </div>
            @endif

            @if($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
                <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
            @endif

            @yield('content')
        </main>
    </div>

    {{-- Mobile sidebar drawer toggle. Vanilla JS so no dependency on Alpine. --}}
    <script>
        (function () {
            const btn = document.getElementById('adminMobileMenuBtn');
            const sidebar = document.getElementById('adminSidebar');
            const backdrop = document.getElementById('adminSidebarBackdrop');
            if (! btn || ! sidebar || ! backdrop) return;

            const open = () => {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
                document.body.classList.add('overflow-hidden', 'lg:overflow-auto');
            };
            const close = () => {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            };

            btn.addEventListener('click', open);
            backdrop.addEventListener('click', close);
            sidebar.querySelectorAll('a').forEach((a) => a.addEventListener('click', close));

            // Auto-close on viewport widening into desktop (lg = 1024px).
            const mql = window.matchMedia('(min-width: 1024px)');
            const onChange = (e) => { if (e.matches) close(); };
            mql.addEventListener ? mql.addEventListener('change', onChange) : mql.addListener(onChange);
        })();
    </script>

    {{-- Platform-wide confirmation modal. Any form marked with data-confirm
         is intercepted and confirmed here before submitting. --}}
    <x-confirm-modal />

    {{-- Read-only-until-Edit behaviour + change diff for any form marked
         data-editable. Must follow the confirm modal so the diff is rendered. --}}
    <x-editable-section />

    @stack('scripts')
</body>
</html>
