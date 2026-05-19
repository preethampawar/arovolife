<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — arovolife Admin</title>
    @vite(['resources/css/app.css'])
    @stack('styles')
</head>
<body class="min-h-full bg-[#f4f7f6] text-gray-900 antialiased flex">

    {{-- Sidebar — top-0/bottom-0 stretches the aside to the full viewport
         vertically (more robust than h-screen which can mis-resolve under
         position: fixed in Tailwind v4). The logo header is pinned at top,
         and a single scrollable region holds nav + footer; mt-auto pushes
         the footer to the bottom of that region when content fits, and
         scrolls it into view when the viewport is too short. --}}
    <aside class="w-60 fixed top-0 bottom-0 left-0 z-40 bg-white border-r border-gray-200 flex flex-col">
        <div class="px-5 py-5 border-b border-gray-200 shrink-0">
            <a href="{{ route('admin.dashboard') }}" class="block">
                <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife" class="h-10 w-auto">
            </a>
            <span class="block text-[11px] text-gray-500 mt-1.5 tracking-wider uppercase font-semibold">Admin Console</span>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto flex flex-col">
        <nav class="px-3 py-4 space-y-0.5">
            @php
                // Unread Contact-inquiries count for the sidebar badge.
                // Cached for 60s so this query doesn't run on every admin page.
                $unhandledContactCount = \Illuminate\Support\Facades\Cache::remember(
                    'admin.contact_inquiries.unhandled_count',
                    60,
                    fn () => \App\Modules\Public\Models\ContactInquiry::query()->whereNull('handled_at')->count(),
                );

                $navItems = [
                    ['route' => 'admin.dashboard',                'label' => 'Dashboard',      'icon' => '⬡'],
                    ['route' => 'admin.distributors.index',       'label' => 'Distributors',   'icon' => '◉'],
                    ['route' => 'admin.tree.show',                'label' => 'Genealogy tree', 'icon' => '⌬', 'prefix' => 'admin.tree'],
                    ['route' => 'admin.kyc.index',                'label' => 'KYC review',     'icon' => '✓', 'prefix' => 'admin.kyc'],
                    ['route' => 'admin.contact-inquiries.index',  'label' => 'Contact Inbox',  'icon' => '✉', 'prefix' => 'admin.contact-inquiries', 'badge' => $unhandledContactCount],
                    ['route' => 'admin.commerce.orders.index',    'label' => 'Orders',         'icon' => '🛒', 'prefix' => 'admin.commerce'],
                    ['route' => 'admin.content.index',            'label' => 'Content Pages',  'icon' => '📄', 'prefix' => 'admin.content'],
                    ['route' => 'admin.settings',                 'label' => 'Settings',       'icon' => '⚙'],
                    ['route' => 'admin.feature-flags.index',      'label' => 'Feature flags',  'icon' => '⚑', 'prefix' => 'admin.feature-flags'],
                    ['route' => 'admin.audit-log',                'label' => 'Audit Log',      'icon' => '☰'],
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
                             ? 'bg-brand-50 text-brand-700 font-semibold'
                             : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900 font-medium' }}">
                    @if($active)
                    <span class="absolute left-0 top-1.5 bottom-1.5 w-1 rounded-r-full bg-brand-500"></span>
                    @endif
                    <span class="text-base {{ $active ? 'text-brand-600' : 'text-gray-500' }}">{{ $item['icon'] }}</span>
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if(!empty($item['badge']))
                        <span class="inline-flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full bg-sunrise-500 text-white text-[10px] font-bold leading-none">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

            <div class="mt-auto px-3 py-4 border-t border-gray-200">
                <p class="text-xs text-gray-600 px-3 mb-2 truncate font-medium">{{ auth()->user()->email }}</p>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-700 font-medium hover:bg-gray-50 hover:text-red-600 transition-colors">
                        <span class="text-gray-500">⏻</span> Sign out
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Main --}}
    <div class="ml-60 flex-1 min-h-screen flex flex-col">
        <header class="bg-brand-700 border-b border-brand-800 px-8 py-4 flex items-center justify-between sticky top-0 z-30">
            <h1 class="text-base font-semibold text-white tracking-tight">@yield('heading', 'Admin Console')</h1>
            <div class="flex items-center gap-4 text-xs text-white font-medium">
                <span>{{ now()->format('d M Y, H:i') }} IST</span>
                <span class="text-brand-300">|</span>
                <a href="{{ route('dashboard') }}" class="text-white hover:text-brand-100 transition-colors">← Distributor view</a>
            </div>
        </header>

        <main class="flex-1 px-8 py-8">
            @if(session('status'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                {{ session('status') }}
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

    @stack('scripts')
</body>
</html>
