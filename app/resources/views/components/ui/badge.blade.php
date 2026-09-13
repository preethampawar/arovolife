@props([
    'tone' => 'neutral',   // neutral|success|warning|danger|info|brand
    'dot'  => false,
])

@php
    // Every tint/text/border triple below already has a dark remap in app.css
    // ("Tinted status surfaces"), so no dark: variant is needed — and one here
    // would fight that layer.
    $tones = [
        'neutral' => 'bg-gray-100 text-gray-700 border-gray-200',
        'success' => 'bg-green-50 text-green-700 border-green-200',
        'warning' => 'bg-amber-50 text-amber-700 border-amber-200',
        'danger'  => 'bg-red-50 text-red-700 border-red-200',
        'info'    => 'bg-sky-50 text-sky-700 border-sky-200',
        'brand'   => 'bg-brand-50 text-brand-700 border-brand-200',
    ];

    // The dot carries the status independently of the tint, so the badge does
    // not rely on colour alone at a glance.
    $dotColours = [
        'neutral' => 'bg-gray-400', 'success' => 'bg-green-500', 'warning' => 'bg-amber-500',
        'danger'  => 'bg-red-500',  'info'    => 'bg-sky-500',   'brand'   => 'bg-brand-500',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium',
    $tones[$tone] ?? $tones['neutral'],
]) }}>
    @if($dot)<span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotColours[$tone] ?? $dotColours['neutral'] }}"></span>@endif
    {{ $slot }}
</span>
