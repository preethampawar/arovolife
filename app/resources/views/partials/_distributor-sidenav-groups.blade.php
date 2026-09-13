{{-- Nav groups shared by the desktop sidenav column and the mobile
     drawer. $groups and $navSide are defined in
     partials/distributor-sidenav. --}}

{{-- Position — which Genos group this distributor sits in. Came here from
     the dashboard's Placement card, which was removed. --}}
@if(($navSide ?? null) !== null)
    @php $navIsLeft = $navSide === 'L'; @endphp
    <div data-nav-position="{{ $navSide }}"
         class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-xs {{ $navIsLeft ? 'border-sky-200 bg-sky-50' : 'border-indigo-200 bg-indigo-50' }}">
        <span class="font-medium text-gray-600">Position</span>
        <span class="font-semibold {{ $navIsLeft ? 'text-sky-700' : 'text-indigo-700' }}">{{ $navIsLeft ? '← Left' : '→ Right' }} group</span>
    </div>
@endif

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
                <span class="w-5 flex justify-center {{ $active ? 'text-brand-700' : 'text-gray-500' }}" aria-hidden="true">{{ svg('lucide-'.$item['icon'], 'w-4 h-4') }}</span>
                <span class="flex-1 truncate">{{ $item['label'] }}</span>
                @if(! empty($item['badge']))
                    <span class="shrink-0 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-amber-800">{{ $item['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </nav>
</div>
@endforeach
