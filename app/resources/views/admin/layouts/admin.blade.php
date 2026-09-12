<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — arovolife Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    {{-- Sidebar collapse FOUC preventer: restore the saved desktop rail state
         before first paint so the nav never renders wide and then snaps. --}}
    <script>
        (() => {
            try {
                if (localStorage.getItem('arovolife_admin_sidebar_collapsed') === '1') {
                    document.documentElement.classList.add('admin-nav-collapsed');
                }
            } catch (e) { /* private-browsing — start expanded */ }
        })();
    </script>
    <style>
        /* Admin sidebar collapse (desktop only). The <html> class is the single
           source of truth; everything below re-lays out the aside as a 4rem
           icon rail and pulls the main column in to match. Below lg the
           sidebar is a slide-over drawer and these rules never apply. */
        #adminSidebar { transition: transform 0.2s ease-out, width 0.2s ease-out; }
        #adminMain { transition: margin-left 0.2s ease-out; }
        .admin-nav-expand-icon { display: none; }
        @media (min-width: 1024px) {
            html.admin-nav-collapsed #adminSidebar { width: 4rem; }
            html.admin-nav-collapsed #adminMain { margin-left: 4rem; }
            html.admin-nav-collapsed .admin-nav-label,
            html.admin-nav-collapsed .admin-nav-brand,
            html.admin-nav-collapsed .admin-nav-collapse-icon { display: none; }
            html.admin-nav-collapsed .admin-nav-expand-icon { display: block; }
            html.admin-nav-collapsed .admin-nav-head { justify-content: center; padding-left: 0.75rem; padding-right: 0.75rem; }
            html.admin-nav-collapsed .admin-nav-item { justify-content: center; padding-left: 0; padding-right: 0; }
            /* Rail mode: the group toggles are unreachable, so headers go away
               and each section becomes a hairline-separated block of icons. */
            html.admin-nav-collapsed .admin-nav-group-header { display: none; }
            html.admin-nav-collapsed [data-nav-group] + [data-nav-group] .admin-nav-group-list {
                border-top: 1px solid #1e293b; /* slate-800 */
                padding-top: 0.25rem;
                margin-top: 0.25rem;
            }
            html.admin-nav-collapsed .admin-nav-group-list { display: block; }
            html.admin-nav-collapsed .admin-nav-badge {
                position: absolute; top: 0.375rem; right: 0.625rem;
                min-width: 0.5rem; width: 0.5rem; height: 0.5rem; padding: 0;
                font-size: 0; line-height: 0;
            }
        }

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
        <div class="admin-nav-head px-5 py-5 border-b border-slate-800 shrink-0 flex items-start justify-between gap-2">
            <div class="admin-nav-brand min-w-0">
                <a href="{{ route('admin.dashboard') }}" class="block">
                    <img src="{{ asset('assets/arovolife-logos/arovolife-white-logo.png') }}" alt="arovolife" class="h-10 w-auto">
                </a>
                <span class="block text-[11px] text-sunrise-400 mt-1.5 tracking-wider uppercase font-semibold">Admin Console</span>
            </div>
            {{-- Desktop-only collapse / expand toggle. State lives on <html>
                 (class admin-nav-collapsed) and persists in localStorage. --}}
            <button type="button" id="adminSidebarToggle"
                aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar"
                class="hidden lg:inline-flex shrink-0 w-8 h-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition-colors">
                <x-lucide-panel-left-close class="admin-nav-collapse-icon w-4 h-4" />
                <x-lucide-panel-left-open class="admin-nav-expand-icon w-4 h-4" />
            </button>
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

                // Open message reports for the sidebar badge. Gated on the
                // messaging killswitch as well as the permission: a closed
                // channel must leave no trace, badge included.
                $messagingOn = \Laravel\Pennant\Feature::for(null)->active(\App\Modules\Shared\Features\MessagingFeature::class);
                $openMessageReportCount = ($messagingOn && (auth()->user()?->can('messaging.moderate') ?? false))
                    ? \Illuminate\Support\Facades\Cache::remember(
                        'admin.message_reports.open_count',
                        60,
                        fn () => \App\Modules\Messaging\Models\MessageReport::query()
                            ->where('status', \App\Modules\Messaging\Models\MessageReport::STATUS_OPEN)
                            ->count(),
                    )
                    : 0;

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

                // Compensation engines whose last outcome for a period is a
                // FAILURE. Nothing else in the platform reads a failed run, so
                // a month that stopped part-way was invisible until somebody
                // opened the Engine Runs page — and the monthly payout on the
                // 8th refuses over exactly these. Same 60s cache as the other
                // queues; the entry disappears once the engine is re-run.
                // `finance.record` as well as `audit.read`: admin-finance owns
                // the payouts the 8th-of-month batch refuses over, so it must
                // see the badge whether or not it also holds the audit-log
                // permission.
                $canSeeEngineFailures = (auth()->user()?->can('audit.read') ?? false)
                    || (auth()->user()?->can('finance.record') ?? false);

                $failedEngineRunCount = $canSeeEngineFailures
                    ? \Illuminate\Support\Facades\Cache::remember(
                        'admin.engine_runs.unresolved_failure_count',
                        60,
                        fn () => app(\App\Modules\Compensation\Services\EngineStatusService::class)->unresolvedFailureCount(),
                    )
                    : 0;

                // Action Center critical count for the sidebar badge. Reads the
                // same 60s summary cache the screen itself uses (plan §6), so
                // this adds no extra query beyond what `ActionCenterService`
                // already computes. Zero renders no badge, not a grey zero
                // (plan §7, §10.5).
                $actionCenterOn = (auth()->user()?->can('action.center.view') ?? false)
                    && (auth()->user()?->hasRole('developer') ?? false);
                $actionCenterCriticalCount = $actionCenterOn
                    ? app(\App\Modules\ActionCenter\Services\ActionCenterService::class)->criticalCount(auth()->user())
                    : 0;

                $badges = [
                    'action-center' => $actionCenterCriticalCount,
                    'contact' => $unhandledContactCount,
                    'grievances' => $openGrievanceCount,
                    'message-reports' => $openMessageReportCount,
                    'distributor-requests' => $openDistributorRequestCount,
                    'adc-applications' => $openAdcApplicationCount,
                    'engine-failures' => $failedEngineRunCount,
                    // Inventory alerts and the payments worklist keep their own
                    // 60s caches here rather than inside AdminNavigation, which
                    // stays free of queries.
                    'inventory-reports' => auth()->user()?->can('inventory.view')
                        ? \Illuminate\Support\Facades\Cache::remember('admin.inventory.alert_count', 60, fn () => app(\App\Modules\Inventory\Services\InventoryAlertService::class)->lowStock()->count() + app(\App\Modules\Inventory\Services\InventoryAlertService::class)->expired()->count())
                        : 0,
                    'payments' => auth()->user()?->can('audit.read')
                        ? \Illuminate\Support\Facades\Cache::remember('admin.payments.attention_count', 60, fn () => app(\App\Modules\Payments\Support\RefundWorklist::class)->attentionCount() + app(\App\Modules\Payments\Support\InvoiceGapWorklist::class)->count())
                        : 0,
                ];

                $navGroups = \App\Modules\Shared\Support\AdminNavigation::groups(auth()->user(), $badges);
            @endphp
            @foreach($navGroups as $group)
                @php
                    // Forced-open-on-active, computed server-side with the same
                    // routeIs logic the items use, so the section holding the
                    // current page is never rendered collapsed and never flashes.
                    $groupActive = false;
                    foreach ($group['items'] as $groupItem) {
                        if (request()->routeIs($groupItem['route'])
                            || (isset($groupItem['prefix']) && request()->routeIs($groupItem['prefix'].'*'))) {
                            $groupActive = true;
                            break;
                        }
                    }
                @endphp
                <div data-nav-group="{{ $group['key'] }}" @if($groupActive) data-nav-group-active="1" @endif>
                @if($group['label'] !== null)
                    <button type="button" data-nav-group-toggle
                            aria-expanded="true"
                            aria-controls="nav-group-{{ $group['key'] }}"
                            class="admin-nav-group-header w-full flex items-center justify-between gap-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500 px-4 pt-4 pb-1 hover:text-slate-300 transition-colors">
                        <span>{{ $group['label'] }}</span>
                        <span data-nav-group-chevron="down">{{ svg('lucide-chevron-down', 'w-3 h-3') }}</span>
                        <span data-nav-group-chevron="right" hidden>{{ svg('lucide-chevron-right', 'w-3 h-3') }}</span>
                    </button>
                @endif
                    <div id="nav-group-{{ $group['key'] }}" class="admin-nav-group-list space-y-1">
            @foreach($group['items'] as $item)
                @php
                    $active = request()->routeIs($item['route'])
                        || (isset($item['prefix']) && request()->routeIs($item['prefix'].'*'));
                @endphp
                <a href="{{ route($item['route'], $item['params'] ?? []) }}" title="{{ $item['label'] }}"
                   class="admin-nav-item relative flex items-center gap-3 pl-4 pr-3 py-2.5 rounded-lg text-sm transition-colors
                          {{ $active
                             ? 'bg-slate-800 text-white font-semibold'
                             : 'text-slate-300 hover:bg-slate-800 hover:text-white font-medium' }}">
                    @if($active)
                    <span class="absolute left-0 top-1.5 bottom-1.5 w-1 rounded-r-full bg-sunrise-500"></span>
                    @endif
                    <span class="{{ $active ? 'text-sunrise-400' : 'text-slate-600' }}">{{ svg('lucide-'.$item['icon'], 'w-4 h-4') }}</span>
                    <span class="admin-nav-label flex-1">{{ $item['label'] }}</span>
                    @if(!empty($item['badge']))
                        <span class="admin-nav-badge inline-flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full bg-sunrise-800 text-white text-[10px] font-bold leading-none">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
                    </div>
                </div>
            @endforeach
        </nav>
        {{-- Re-apply stored per-group collapse state during parse, before the
             sidebar paints. Groups holding the active route are skipped: the
             server already rendered them open and stored state must not win. --}}
        <script>
            (() => {
                try {
                    const stored = JSON.parse(localStorage.getItem('arovolife_admin_nav_groups') || '{}');
                    document.querySelectorAll('[data-nav-group]').forEach((group) => {
                        if (group.hasAttribute('data-nav-group-active')) return;
                        if (stored[group.getAttribute('data-nav-group')] !== 0) return;
                        const btn = group.querySelector('[data-nav-group-toggle]');
                        const list = group.querySelector('.admin-nav-group-list');
                        if (! btn || ! list) return;
                        list.hidden = true;
                        btn.setAttribute('aria-expanded', 'false');
                        btn.querySelector('[data-nav-group-chevron="down"]').hidden = true;
                        btn.querySelector('[data-nav-group-chevron="right"]').hidden = false;
                    });
                } catch (e) { /* private-browsing — leave every group open */ }
            })();
        </script>

            <div class="mt-auto px-3 py-4 border-t border-slate-800">
                <p class="admin-nav-label text-xs text-slate-600 px-3 mb-2 truncate font-medium">{{ auth()->user()->email }}</p>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="Sign out"
                        class="admin-nav-item w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-slate-300 font-medium hover:bg-slate-800 hover:text-red-400 transition-colors">
                        <span class="text-slate-600">⏻</span> <span class="admin-nav-label">Sign out</span>
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
    <div id="adminMain" class="ml-0 lg:ml-60 flex-1 min-h-screen flex flex-col min-w-0 max-w-full">
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
            </div>
        </header>

        @include('admin.layouts._breadcrumbs')

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

        // Desktop sidebar collapse / expand. The <html> class is applied before
        // first paint by the head snippet; this only toggles and persists it.
        (function () {
            const toggle = document.getElementById('adminSidebarToggle');
            if (! toggle) return;
            const root = document.documentElement;
            const KEY = 'arovolife_admin_sidebar_collapsed';

            const render = () => {
                const collapsed = root.classList.contains('admin-nav-collapsed');
                const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                toggle.setAttribute('aria-label', label);
                toggle.setAttribute('title', label);
            };

            toggle.addEventListener('click', () => {
                const collapsed = root.classList.toggle('admin-nav-collapsed');
                try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
                render();
            });
            render();
        })();

        // Sidebar group collapse. The stored state is applied during parse by
        // the snippet next to the nav; this only toggles and persists it.
        (function () {
            const KEY = 'arovolife_admin_nav_groups';

            const read = () => {
                try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; }
                catch (e) { return {}; }
            };

            document.querySelectorAll('[data-nav-group]').forEach((group) => {
                const key = group.getAttribute('data-nav-group');
                const btn = group.querySelector('[data-nav-group-toggle]');
                const list = group.querySelector('.admin-nav-group-list');
                if (! btn || ! list) return;

                btn.addEventListener('click', () => {
                    const open = btn.getAttribute('aria-expanded') !== 'true';
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    list.hidden = ! open;
                    btn.querySelector('[data-nav-group-chevron="down"]').hidden = ! open;
                    btn.querySelector('[data-nav-group-chevron="right"]').hidden = open;

                    const state = read();
                    state[key] = open ? 1 : 0;
                    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* ignore */ }
                });
            });
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
