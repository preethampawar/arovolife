@props([
    'title'       => null,
    'description' => null,
])

{{--
    In-content section header for a list or form page: the row that carries the
    "Add …" / "Export" actions above the card.

    The page's own <h1> lives in the sticky topbar, so this is an <h2>. Keep it
    that way — a second <h1> on the page breaks the document outline, and it is
    the most likely mistake when migrating a view whose old markup had its own
    big title.
--}}
<div {{ $attributes->class(['mb-5 flex flex-wrap items-start justify-between gap-3']) }}>
    <div class="min-w-0">
        @if($title)<h2 class="text-base font-semibold tracking-tight text-gray-900">{{ $title }}</h2>@endif
        @if($description)<p class="mt-1 max-w-2xl text-sm text-gray-500">{{ $description }}</p>@endif
        {{ $slot }}
    </div>
    @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</div>
