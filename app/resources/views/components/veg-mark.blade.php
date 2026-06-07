@props([
    'type' => null,   // 'veg' | 'non_veg' | null (null/other = not a food item, renders nothing)
    'size' => 'md',    // 'sm' | 'md'
])

@php
    $type = in_array($type, ['veg', 'non_veg'], true) ? $type : null;
    $box = $size === 'sm' ? 'w-4 h-4' : 'w-5 h-5';
    $dot = $size === 'sm' ? 'w-2 h-2' : 'w-2.5 h-2.5';
    $isVeg = $type === 'veg';
    $label = $isVeg ? 'Vegetarian' : 'Non-vegetarian';
    // Full literal classes (no interpolation) so Tailwind v4's JIT scanner keeps them.
    $border = $isVeg ? 'border-green-600' : 'border-red-600';
@endphp

@if($type)
    <span role="img" aria-label="{{ $label }}" title="{{ $label }}"
        {{ $attributes->merge(['class' => "inline-flex items-center justify-center {$box} border-2 {$border} rounded-[3px] shrink-0 bg-white"]) }}>
        @if($isVeg)
            <span class="{{ $dot }} rounded-full bg-green-600"></span>
        @else
            {{-- Non-veg: upward triangle --}}
            <span class="block w-0 h-0 border-l-[5px] border-r-[5px] border-b-[8px] border-l-transparent border-r-transparent border-b-red-600"></span>
        @endif
    </span>
@endif
