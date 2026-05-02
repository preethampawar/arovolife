{{-- Shared public top nav — used by landing, shop, wizard, dashboard, content pages --}}

@php
    $navItems = [
        ['label' => 'Home',         'route' => 'home',      'match' => ['home']],
        ['label' => 'Shop',         'route' => 'shop.index','match' => ['shop.index', 'shop.product', 'shop.cart', 'shop.checkout', 'shop.confirmation']],
        ['label' => 'About',        'route' => 'about',                                'match' => ['about']],
        ['label' => 'How It Works', 'url'   => route('home') . '#how-it-works',        'match' => []],
        ['label' => 'Contact',      'route' => 'contact.show','match' => ['contact.show']],
        ['label' => 'Support',      'url'   => route('content.show', 'grievance'),     'match' => []],
    ];
@endphp

{{-- Utility strip: deep blue. Hidden below sm so the mobile header doesn't
     stack two coloured strips. --}}
<div class="hidden sm:block bg-brand-700 text-white text-xs">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2 flex items-center justify-end gap-3 sm:gap-4 flex-wrap">
        @guest
            <a href="{{ route('contact.show', ['reason' => 'join_us']) }}" class="hover:text-brand-50 transition-colors">Join Us</a>
            <span class="text-brand-400">|</span>
            <a href="{{ route('login') }}" class="hover:text-brand-50 transition-colors">Sign in</a>
        @else
            @if(auth()->user()->hasRole('admin'))
                <a href="{{ route('admin.dashboard') }}" class="hover:text-brand-50 transition-colors">Admin Console</a>
            @else
                <a href="{{ route('dashboard') }}" class="hover:text-brand-50 transition-colors">My Office</a>
            @endif
            <span class="text-brand-400">|</span>
            <form method="POST" action="{{ route('logout') }}" class="inline">
                @csrf
                <button type="submit" class="hover:text-brand-50 transition-colors">Sign out</button>
            </form>
        @endguest
        <span class="text-brand-400">|</span>
        <a href="{{ route('about') }}" class="hover:text-brand-50 transition-colors">About Us</a>
        <span class="text-brand-400">|</span>
        <span>India 🇮🇳</span>
    </div>
</div>

{{-- Main header --}}
<nav class="bg-brand-500 border-b border-brand-600 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 flex items-center gap-4 sm:gap-6 lg:gap-8">

        <a href="{{ route('home') }}" class="flex items-center shrink-0 py-2.5">
            <img src="{{ asset('assets/arovolife-logos/arovolife-white-logo.png') }}" alt="arovolife" class="h-8 sm:h-10 w-auto">
        </a>

        {{-- Desktop nav (lg+) --}}
        <div class="hidden lg:flex items-center gap-7 text-sm ml-auto">
            @foreach($navItems as $item)
                @php
                    $active = !empty($item['match']) && collect($item['match'])->contains(fn ($r) => request()->routeIs($r));
                    $href   = $item['url'] ?? route($item['route']);
                @endphp
                <a href="{{ $href }}"
                   class="py-5 font-medium transition-colors
                          {{ $active
                             ? 'text-white border-b-2 border-white -mb-px'
                             : 'text-brand-50 hover:text-white' }}">
                    {{ $item['label'] }}
                </a>
            @endforeach

            <a href="{{ route('shop.cart') }}"
               class="relative flex items-center gap-2 text-brand-50 hover:text-white transition-colors py-5"
               aria-label="Cart">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                </svg>
            </a>

            @guest
            <a href="{{ route('login') }}"
               class="px-4 py-2 rounded-full bg-white hover:bg-brand-50 text-brand-700 text-xs font-semibold transition-colors shadow-sm">
                Sign In
            </a>
            @endguest
        </div>

        {{-- Mobile/tablet right side: cart + hamburger --}}
        <div class="flex lg:hidden items-center gap-1 ml-auto">
            <a href="{{ route('shop.cart') }}"
               class="relative w-10 h-10 inline-flex items-center justify-center text-brand-50 hover:text-white transition-colors"
               aria-label="Cart">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                </svg>
            </a>
            <button type="button"
                onclick="document.getElementById('mobileNavDrawer').classList.toggle('hidden')"
                class="w-10 h-10 inline-flex items-center justify-center text-white rounded-md hover:bg-brand-600 transition-colors"
                aria-label="Open menu">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- Mobile drawer (slides down under the header) --}}
    <div id="mobileNavDrawer" class="hidden lg:hidden bg-brand-500 border-t border-brand-600">
        <div class="px-4 py-2 flex flex-col text-sm">
            @foreach($navItems as $item)
                @php
                    $active = !empty($item['match']) && collect($item['match'])->contains(fn ($r) => request()->routeIs($r));
                    $href   = $item['url'] ?? route($item['route']);
                @endphp
                <a href="{{ $href }}"
                   class="py-2.5 px-2 rounded-md font-medium transition-colors
                          {{ $active ? 'text-white bg-brand-600' : 'text-brand-50 hover:text-white hover:bg-brand-600' }}">
                    {{ $item['label'] }}
                </a>
            @endforeach

            <div class="border-t border-brand-600 my-2"></div>

            @guest
                <a href="{{ route('login') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-600 transition-colors font-medium">Sign in</a>
                <a href="{{ route('contact.show') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-600 transition-colors font-medium">Become a Direct Seller</a>
            @else
                @if(auth()->user()->hasRole('admin'))
                    <a href="{{ route('admin.dashboard') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-600 transition-colors font-medium">Admin Console</a>
                @else
                    <a href="{{ route('dashboard') }}" class="py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-600 transition-colors font-medium">My Office</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left py-2.5 px-2 rounded-md text-brand-50 hover:text-white hover:bg-brand-600 transition-colors font-medium">Sign out</button>
                </form>
            @endguest
        </div>
    </div>
</nav>
