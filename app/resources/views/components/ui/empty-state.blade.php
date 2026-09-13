@props([
    'title'       => 'Nothing here yet',
    'description' => null,
    'icon'        => 'inbox',
    'colspan'     => null,   // set it to render as a <tr><td colspan> inside a table
])

{{--
    Absorbs the 36 colspan empty rows and the 19 centered <p> empties across
    the console.

    IMPORTANT when migrating an existing view: several browser specs match the
    old copy with getByText(..., { exact: true }) — e.g. "Nothing needs action
    right now." — and `title` is rendered as one element's complete text. Pass
    the existing sentence verbatim as `title` and leave `description` empty, or
    the exact-match assertion fails.
--}}
@if($colspan)
<tr><td colspan="{{ $colspan }}" class="px-4 py-12">
@else
<div {{ $attributes->class(['px-6 py-12']) }}>
@endif
    <div class="flex flex-col items-center gap-2 text-center">
        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">{{ svg('lucide-'.$icon, 'w-5 h-5') }}</span>
        <p class="text-sm font-medium text-gray-700">{{ $title }}</p>
        @if($description)<p class="max-w-sm text-xs text-gray-500">{{ $description }}</p>@endif
        @if(trim($slot) !== '')<div class="mt-2">{{ $slot }}</div>@endif
    </div>
@if($colspan)
</td></tr>
@else
</div>
@endif
