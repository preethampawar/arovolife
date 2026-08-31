{{-- Nav groups shared by the desktop sidenav column and the mobile
     drawer. $groups is defined in partials/distributor-sidenav. --}}
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
