@php
    // Flag gating lives in one place — see IncomeNavLinks. This tab bar is
    // shared by My Business and every income page so the menu never
    // disappears while moving between them.
    $tabs = \App\Modules\Compensation\Support\IncomeNavLinks::visible();
@endphp
<nav class="mb-6 border-b border-gray-200" aria-label="My business pages">
    <div class="flex flex-wrap gap-1">
        @foreach($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               class="px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors
                      {{ request()->routeIs($tab['route'])
                          ? 'border-brand-500 text-brand-700'
                          : 'border-transparent text-gray-600 hover:text-gray-900 hover:border-gray-300' }}"
               @if(request()->routeIs($tab['route'])) aria-current="page" @endif>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
</nav>
