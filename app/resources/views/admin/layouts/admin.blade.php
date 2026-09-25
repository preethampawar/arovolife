<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — arovolife Admin</title>
    @include('partials._favicons')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    {{-- Admin dark/light theme: restore the saved choice before first paint. --}}
    @include('partials._theme-fouc')
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
                border-top: 1px solid #e0e7ed; /* gray-200 (light); dark override below */
                padding-top: 0.25rem;
                margin-top: 0.25rem;
            }
            html.dark.admin-nav-collapsed [data-nav-group] + [data-nav-group] .admin-nav-group-list {
                border-top-color: #1e2836; /* dark sidebar border */
            }
            html.admin-nav-collapsed .admin-nav-group-list { display: block; }
            html.admin-nav-collapsed .admin-nav-badge {
                position: absolute; top: 0.375rem; right: 0.625rem;
                min-width: 0.5rem; width: 0.5rem; height: 0.5rem; padding: 0;
                font-size: 0; line-height: 0;
            }
            /* Rail mode: the count collapses to an 8px dot. Without a ring it
               fuses with the icon glyph sitting underneath it. */
            html.admin-nav-collapsed .admin-nav-badge { box-shadow: 0 0 0 2px #ffffff; }
            html.dark.admin-nav-collapsed .admin-nav-badge { box-shadow: 0 0 0 2px #0f1927; }
        }

        /* Admin sidebar: keep the scrollbar track reserved at a constant width
           at all times (transparent by default) and only fade the thumb in on
           hover, so revealing it never changes the content box width — that
           reflow was shifting every nav item ~15px left on hover. Firefox uses
           scrollbar-width/scrollbar-color; WebKit uses the ::-webkit-scrollbar
           pseudo. */
        .admin-sidebar-scroll {
            scrollbar-width: thin;
            scrollbar-color: transparent transparent;
            transition: scrollbar-color 0.2s ease;
        }
        .admin-sidebar-scroll::-webkit-scrollbar {
            width: 6px;
            background: transparent;
        }
        .admin-sidebar-scroll::-webkit-scrollbar-thumb {
            background-color: transparent;
            border-radius: 4px;
        }
        .admin-sidebar-scroll:hover,
        .admin-sidebar-scroll:focus-within {
            scrollbar-color: rgba(148, 163, 184, 0.4) transparent; /* slate-400 @ 40% */
        }
        .admin-sidebar-scroll:hover::-webkit-scrollbar-thumb,
        .admin-sidebar-scroll:focus-within::-webkit-scrollbar-thumb {
            background-color: rgba(148, 163, 184, 0.4); /* slate-400 @ 40% */
        }
        .admin-sidebar-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
    </style>
    @stack('styles')
</head>
<body class="admin-shell min-h-full text-gray-900 antialiased flex overflow-x-hidden">

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
        class="w-60 fixed top-0 bottom-0 left-0 z-40 bg-white border-r border-gray-200 flex flex-col
               -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-out">
        <div class="admin-nav-head px-4 py-4 border-b border-gray-200 shrink-0 flex items-start justify-between gap-2">
            <div class="admin-nav-brand min-w-0">
                <a href="{{ route('admin.dashboard') }}" class="block rounded-lg">
                    {{-- Two logo variants swap with the theme, same mechanism as the
                         moon/sun toggle icons (see app.css, [data-theme-logo]). --}}
                    <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife" data-theme-logo="light" class="h-9 w-auto">
                    <img src="{{ asset('assets/arovolife-logos/arovolife-white-logo.png') }}" alt="arovolife" data-theme-logo="dark" class="h-9 w-auto">
                </a>
                {{-- Neutral, not sunrise-800. With the orange ADMIN chip now in
                     the topbar, an orange caption 200px away was a second
                     shouting brand accent. One orange textual mark in the
                     chrome; the active-nav rail is the other, and it earns it
                     by being positional rather than decorative. --}}
                <span class="block text-[10px] text-gray-500 mt-1.5 tracking-[0.14em] uppercase font-semibold">Admin Console</span>
            </div>
            {{-- Desktop-only collapse / expand toggle. State lives on <html>
                 (class admin-nav-collapsed) and persists in localStorage. --}}
            <button type="button" id="adminSidebarToggle"
                aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar"
                class="hidden lg:inline-flex shrink-0 w-8 h-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 transition-colors">
                <x-lucide-panel-left-close class="admin-nav-collapse-icon w-4 h-4" />
                <x-lucide-panel-left-open class="admin-nav-expand-icon w-4 h-4" />
            </button>
        </div>

        <div class="admin-sidebar-scroll flex-1 min-h-0 overflow-y-auto flex flex-col">
        <nav class="px-2.5 py-3">
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
                //
                // Category-filtered on the same rule as the queue itself
                // (`AdminGrievanceController::applyVisibility()`) and the
                // monthly report (R-100). It has to be: the queue's own
                // "Open (N)" tab already hides ethics and privacy tickets, so
                // an unfiltered badge let an operations officer read the
                // sensitive count straight off the difference between the two
                // numbers. Filtering one surface and not the other discloses
                // exactly what filtering was for.
                //
                // Hence two cache keys rather than one. The old single global
                // key would have served whichever number was computed first to
                // every viewer — a compliance officer's total to operations,
                // or the reverse — which is worse than not caching at all.
                // Not keyed per user: the count only has two possible values,
                // and a key per admin would multiply the entries for nothing.
                $seesSensitiveGrievances = auth()->user()?->can('compliance.discipline') ?? false;

                $openGrievanceCount = \Illuminate\Support\Facades\Cache::remember(
                    'admin.grievances.unsettled_count.'.($seesSensitiveGrievances ? 'all' : 'general'),
                    60,
                    fn () => \App\Modules\Grievance\Models\Ticket::query()
                        ->unsettled()
                        ->when(
                            ! $seesSensitiveGrievances,
                            fn ($q) => $q->whereNotIn('category', \App\Modules\Grievance\Enums\TicketCategory::sensitiveValues()),
                        )
                        ->count(),
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
                <div data-nav-group="{{ $group['key'] }}" class="mt-6 first:mt-0" @if($groupActive) data-nav-group-active="1" @endif>
                @if($group['label'] !== null)
                    {{-- text-left overrides the browser's default `button { text-align: center }`
                         — without it, a label long enough to wrap (e.g. "Support &
                         Compliance") centers its second line instead of staying flush left. --}}
                    {{-- The filled grey chip and its border-t are gone: a solid
                         pill for a section label is the most dated thing in the
                         sidebar. Separation is whitespace now (pt-5), which also
                         lets the eye chunk the nine groups faster than a rule
                         did. --}}
                    <button type="button" data-nav-group-toggle
                            aria-expanded="true"
                            aria-controls="nav-group-{{ $group['key'] }}"
                            class="admin-nav-group-header w-full flex items-center justify-between gap-2 px-3 pt-1 pb-2 text-left text-[10px] font-semibold uppercase tracking-[0.12em] text-gray-500 transition-colors hover:text-gray-900">
                        <span class="text-left">{{ $group['label'] }}</span>
                        <span data-nav-group-chevron="down" class="shrink-0 text-gray-300">{{ svg('lucide-chevron-down', 'w-3 h-3') }}</span>
                        <span data-nav-group-chevron="right" class="shrink-0 text-gray-300" hidden>{{ svg('lucide-chevron-right', 'w-3 h-3') }}</span>
                    </button>
                @endif
                    <div id="nav-group-{{ $group['key'] }}" class="admin-nav-group-list space-y-1 pt-0.5">
            @foreach($group['items'] as $item)
                @php
                    $active = request()->routeIs($item['route'])
                        || (isset($item['prefix']) && request()->routeIs($item['prefix'].'*'));
                @endphp
                {{-- Tightened ~5px per item across ~40 items: roughly 200px
                     less scroll in a nav that previously overflowed on a
                     laptop. The active rail thins 4px -> 3px so it reads as a
                     marker rather than a slab. --}}
                <a href="{{ route($item['route'], $item['params'] ?? []) }}" title="{{ $item['label'] }}"
                   class="admin-nav-item relative flex items-center gap-2.5 pl-3.5 pr-2.5 py-2 rounded-lg text-[13px] leading-5 transition-colors
                          {{ $active
                             ? 'bg-gray-100 text-gray-900 font-semibold'
                             : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 font-medium' }}">
                    @if($active)
                    <span class="absolute left-0 top-2 bottom-2 w-[3px] rounded-r-full bg-sunrise-500"></span>
                    @endif
                    <span class="shrink-0 {{ $active ? 'text-sunrise-600' : 'text-gray-400' }}">{{ svg('lucide-'.$item['icon'], 'w-[18px] h-[18px]') }}</span>
                    <span class="admin-nav-label flex-1">{{ $item['label'] }}</span>
                    @if(!empty($item['badge']))
                        {{-- sunrise-600, not 800: the deeper shade read as a muddy
                             brown rather than a live count. tabular-nums keeps a
                             two- and three-digit badge the same width. --}}
                        <span class="admin-nav-badge inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full bg-sunrise-600 text-white text-[10px] font-semibold leading-none tabular-nums">{{ $item['badge'] }}</span>
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

            <div class="mt-auto px-2.5 py-3 border-t border-gray-200">
                <p class="admin-nav-label text-[11px] text-gray-500 px-2.5 mb-1.5 truncate font-medium">{{ auth()->user()->email }}</p>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    {{-- The raw ⏻ character was the one glyph in the console not
                         from the icon set: it rendered at whatever the system
                         font supplied and some screen readers announced it as
                         "power symbol". The button's own "Sign out" text carries
                         the accessible name; the lucide icon is inert. --}}
                    <button type="submit" title="Sign out"
                        class="admin-nav-item w-full text-left flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-[13px] text-gray-600 font-medium hover:bg-red-50 hover:text-red-600 transition-colors">
                        <span class="shrink-0 text-gray-400">{{ svg('lucide-log-out', 'w-[18px] h-[18px]') }}</span>
                        <span class="admin-nav-label">Sign out</span>
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
        <div id="adminTopbarStack" class="sticky top-0 z-20">
            @include('admin.layouts._topbar')

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
            {{-- Demoted, not removed. This exists because of an explicit client
                 instruction (2026-08-28: the report's title must be visible on
                 the page itself, on every report). The topbar h1 now satisfies
                 that literally and stickily, so this is a subordinate label —
                 at text-2xl it was louder than the page's own h1. Deleting it
                 outright is a content decision and needs client sign-off. --}}
            <h2 class="text-base font-semibold text-gray-900 tracking-tight mb-5">@yield('heading')</h2>
            @endif
            @endif

            @include('partials.recompute-projection-banner')

            {{-- Flash messages are rendered here for every admin page. Views must
                 NOT repeat these blocks — a page that renders its own
                 session('status') shows the message twice. --}}
            {{-- A leading icon so the three blocks stop reading as coloured
                 paragraphs and are distinguishable at a glance without relying
                 on colour alone. Copy is unchanged.

                 The message body is a <div>, deliberately NOT a <span>: the
                 flash text used to be a bare text node, and several browser
                 specs select status badges with locator('span').filter({
                 hasText: '...' }). Wrapping the message in a span makes those
                 match the flash too and trips Playwright strict mode. --}}
            @if(session('status'))
            <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                <span class="shrink-0 mt-px" aria-hidden="true">{{ svg('lucide-circle-check', 'w-4 h-4') }}</span>
                <div>{{ session('status') }}</div>
            </div>
            @endif

            @if(session('error'))
            <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 font-medium">
                <span class="shrink-0 mt-px" aria-hidden="true">{{ svg('lucide-circle-alert', 'w-4 h-4') }}</span>
                <div>{{ session('error') }}</div>
            </div>
            @endif

            @if($errors->any())
            <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 p-4">
                <span class="shrink-0 mt-px text-red-700" aria-hidden="true">{{ svg('lucide-circle-alert', 'w-4 h-4') }}</span>
                <ul class="text-sm text-red-700 space-y-1 {{ count($errors->all()) > 1 ? 'list-disc list-inside' : '' }}">
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
