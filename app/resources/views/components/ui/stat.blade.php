@props([
    'label',
    'value',
    'hint' => null,
    'icon' => null,                // lucide name, without the `lucide-` prefix
    'tone' => 'neutral',           // neutral|brand|green|amber|sky|red|slate|violet
    'href' => null,                // when set the whole tile is the link
    'labelLines' => 1,             // 2 = let a long label wrap instead of truncating
])

@php
    // Colour lives in the glyph only. The old tiles wrapped the icon in a 40px
    // pastel square, which cost ~56px of vertical budget per tile purely for
    // decoration and — because slate and violet are deliberately outside the
    // dark remap — left two of the seven squares glowing light in dark mode.
    // A bare tinted glyph has neither problem.
    $toneFg = [
        'neutral' => 'text-gray-400',  'brand'  => 'text-brand-600',
        'green'   => 'text-green-600', 'amber'  => 'text-amber-600',
        'sky'     => 'text-sky-600',   'red'    => 'text-red-600',
        'slate'   => 'text-slate-500', 'violet' => 'text-violet-600',
    ][$tone] ?? 'text-gray-400';

    $shell = 'group flex flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-all'
        .($href ? ' hover:border-gray-300 hover:shadow-md' : '');
@endphp

@if($href)<a href="{{ $href }}" {{ $attributes->class([$shell]) }}>@else<div {{ $attributes->class([$shell]) }}>@endif
    <div @class(['flex gap-2', 'items-center' => $labelLines < 2, 'items-start' => $labelLines >= 2])>
        @if($icon)<span class="shrink-0 {{ $toneFg }}">{{ svg('lucide-'.$icon, 'w-[18px] h-[18px]') }}</span>@endif
        {{-- Sentence case, not uppercase + wide tracking: that pairing with a
             3xl bold number is the dated combination. Sentence case reads
             faster and lets longer labels stay on one line.

             `label-lines="2"` opts a grid into wrapped labels. Truncation is
             still the default, because it is right for a wide tile — but in a
             three-up grid inside a half-width card there is no width to
             truncate against, and "Active warehous…" is not a label. A caller
             that opts in must opt in for every tile in the row: the reserved
             two lines are what keeps the numbers beneath them on one line. --}}
        <p @class([
            'min-w-0 text-[13px] font-medium text-gray-600',
            'truncate' => $labelLines < 2,
            'line-clamp-2 min-h-[2.125rem] leading-[1.0625rem]' => $labelLines >= 2,
        ])>{{ $label }}</p>
        @if($href)
        <span class="ml-auto shrink-0 text-gray-300 transition-all group-hover:translate-x-0.5 group-hover:text-brand-600" aria-hidden="true">{{ svg('lucide-arrow-up-right', 'w-4 h-4') }}</span>
        @endif
    </div>
    {{-- tabular-nums is the real win here: a row of tiles with different digit
         counts now aligns optically across the grid. --}}
    <p class="mt-2.5 text-[28px] font-semibold leading-none tracking-tight tabular-nums text-gray-900">{{ $value }}</p>
    @if($hint)<p class="mt-1.5 text-xs text-gray-500">{{ $hint }}</p>@endif
    {{ $slot }}
@if($href)</a>@else</div>@endif
