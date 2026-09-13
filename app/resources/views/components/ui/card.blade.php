@props([
    'title'    => null,
    'subtitle' => null,
    'flush'    => false,    // no body padding — for tables and divide-y lists
    'padding'  => 'p-5',
])

{{--
    The console's card. Absorbs the hand-written wrapper string that appears
    244 times across the admin views (the most common spelling alone, "bg-white
    rounded-xl border border-gray-200 shadow-sm overflow-hidden", occurs 46
    times verbatim).

    Light-first utilities only — html.dark .bg-white / .border-gray-200 in
    app.css do the dark pass, and a dark: variant here would fight that layer.

    Header renders only when there is something to put in it. `actions` is the
    right-hand slot (buttons, filters); `footer` is the bottom bar, which is
    where ->links() goes on the 71 paginated pages.
--}}
<div {{ $attributes->class(['overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm']) }}>
    @if($title || $subtitle || isset($actions))
    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-3.5">
        <div class="min-w-0">
            @if($title)<h3 class="truncate text-sm font-semibold text-gray-900">{{ $title }}</h3>@endif
            @if($subtitle)<p class="mt-0.5 text-xs text-gray-500">{{ $subtitle }}</p>@endif
        </div>
        @isset($actions)<div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>@endisset
    </div>
    @endif

    @if($flush)
        {{ $slot }}
    @else
        <div class="{{ $padding }}">{{ $slot }}</div>
    @endif

    @isset($footer)
    <div class="border-t border-gray-200 px-5 py-3">{{ $footer }}</div>
    @endisset
</div>
