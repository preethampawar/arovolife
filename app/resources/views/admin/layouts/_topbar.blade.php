{{--
    Admin topbar.

    Replaces the old dark bg-slate-800 header AND the separate breadcrumb
    strip that sat below it with one light page bar. Two stacked sticky rows
    (56px + 29px) become one 68px row, which gives every dense report table
    back ~17px of viewport on all 146 pages.

    Why light: the slate bar was the only element in the console that stayed
    identically dark in BOTH themes — app.css exempts every bg-slate-* from
    the dark remap by design — which is precisely why the light theme read as
    two different products stacked. As a white surface it is picked up by
    `html.dark .bg-white` for free and the console reads as one continuous
    plane in both themes.

    The breadcrumb <nav> is included inline as the title's eyebrow. It still
    renders nothing on the dashboard (AdminNavigation::resolve() returns null
    for admin.dashboard) — _breadcrumbs then emits the bare ADMIN chip with no
    <nav aria-label="Breadcrumb"> wrapper, which is what
    admin-navigation.spec.js asserts. min-h keeps the bar the same height
    whether or not a trail is present, so it never jumps between pages.

    Light-first utilities only: html.dark .bg-white / .border-gray-200 /
    .text-gray-* in app.css do the dark pass. A dark: variant here would
    fight that layer.
--}}
<header class="bg-white border-b border-gray-200 pl-16 pr-4 sm:pr-6 lg:px-8">
    {{-- pl-16 clears the fixed mobile hamburger (left-3, w-10 => 12-52px).
         The old bar used `pl-16 pr-4 sm:px-6`, so from 640px the sm rule
         reset the LEFT padding to 24px and the ADMIN pill sat underneath
         the hamburger for the whole 640-1024px range. Only the right side
         needs the sm bump. --}}
    <div class="min-h-[68px] py-3 flex items-center justify-between gap-4">

        <div class="min-w-0 flex-1">
            @include('admin.layouts._breadcrumbs')
            <h1 class="mt-1 text-lg sm:text-xl font-semibold tracking-tight text-gray-900 truncate">
                @yield('heading', 'Admin Console')
            </h1>
        </div>

        <div class="flex shrink-0 items-center gap-1 sm:gap-3">
            {{-- The console no longer owns the theme: the same control and the
                 same storage key now serve the distributor portal, the shop,
                 the wizard and the public pages. --}}
            @include('partials._theme-toggle')

            {{-- data-testid so visual snapshots can mask it: this text changes
                 every minute and would otherwise flake every screenshot. --}}
            <span data-testid="admin-clock"
                  class="hidden items-center gap-1.5 whitespace-nowrap text-xs font-medium tabular-nums text-gray-500 sm:inline-flex">
                {{ svg('lucide-clock', 'w-3.5 h-3.5 text-gray-400') }}
                {{ now()->format('d M Y, H:i') }} IST
            </span>
        </div>

    </div>
</header>
