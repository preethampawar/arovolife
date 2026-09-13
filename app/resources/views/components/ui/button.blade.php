@props([
    'variant'      => 'primary',   // primary | secondary | ghost | danger
    'size'         => 'md',        // sm | md
    'href'         => null,        // renders an <a> instead of a <button>
    'icon'         => null,        // leading lucide name
    'iconTrailing' => null,        // trailing lucide name
    'type'         => 'submit',
])

@php
    // No focus utilities here on purpose: app.css gives the admin shell one
    // :focus-visible treatment for every focusable element. A ring on the
    // component too would double-draw.
    $base = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg font-medium transition-colors '
          . 'disabled:cursor-not-allowed disabled:opacity-50';

    $sizes = ['sm' => 'px-2.5 py-1.5 text-xs', 'md' => 'px-3.5 py-2 text-sm'];

    $variants = [
        'primary'   => 'bg-brand-700 text-white shadow-sm hover:bg-brand-800',
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 hover:text-gray-900',
        'ghost'     => 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
        'danger'    => 'bg-red-600 text-white shadow-sm hover:bg-red-700',
    ];

    $glyph = $size === 'sm' ? 'w-3.5 h-3.5' : 'w-4 h-4';
    $classes = [$base, $sizes[$size] ?? $sizes['md'], $variants[$variant] ?? $variants['primary']];
@endphp

@if($href)
<a href="{{ $href }}" {{ $attributes->class($classes) }}>
@else
{{-- `submit` is the default because that is what the ~40 form buttons in the
     console actually need; pass type="button" for the rest. --}}
<button type="{{ $type }}" {{ $attributes->class($classes) }}>
@endif
    @if($icon)<span class="shrink-0" aria-hidden="true">{{ svg('lucide-'.$icon, $glyph) }}</span>@endif
    {{ $slot }}
    @if($iconTrailing)<span class="shrink-0" aria-hidden="true">{{ svg('lucide-'.$iconTrailing, $glyph) }}</span>@endif
@if($href)</a>@else</button>@endif
