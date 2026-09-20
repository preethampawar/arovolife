@props([
    'columns' => 3,
    'heading' => null,
])

@php
    // Column counts are looked up, never interpolated: Tailwind scans source
    // text, so `sm:grid-cols-{{ $n }}` would compile to a class that was never
    // generated. Each entry also names a phone and tablet step — a six-up row
    // of figures is unreadable at 375px.
    $grid = [
        2 => 'grid-cols-2',
        3 => 'grid-cols-1 sm:grid-cols-3',
        4 => 'grid-cols-2 sm:grid-cols-4',
        5 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
        6 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6',
        7 => 'grid-cols-2 sm:grid-cols-4 lg:grid-cols-7',
        8 => 'grid-cols-2 sm:grid-cols-4 lg:grid-cols-8',
    ][$columns] ?? 'grid-cols-1 sm:grid-cols-3';
@endphp

{{--
    A row of figures inside a dashboard section card: the Sales panel's
    grammar, extracted so every section reads the same way.

    Hairlines are drawn by each cell (`border-t border-l` on x-ui.stat flush)
    rather than by `divide-x`, and that is the whole reason this component
    exists. `divide-*` skips the first child in DOM order, which is correct for
    one row and wrong the moment a row wraps: the first cell of the second line
    loses its left rule and gains one it should not have. Per-cell borders draw
    the same grid whatever the cell count, and — unlike a `gap-px` container
    tinted to fake the lines — a partly filled last row stays empty instead of
    showing a block of divider colour, which is the alignment problem this
    layout was meant to remove.

    The negative margins pull the leading top and left rules under the card's
    own border and header seam, so nothing double-draws. The card is
    overflow-hidden, which is what clips them.

    Light-first utilities only; html.dark in app.css does the dark pass.
--}}
@if($heading)
<div class="border-t border-gray-200 bg-gray-50 px-5 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $heading }}</div>
@endif
<div {{ $attributes->class(['grid -ml-px -mt-px', $grid]) }}>{{ $slot }}</div>
