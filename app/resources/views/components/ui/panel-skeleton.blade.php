@props([
    'title' => null,
])

{{--
    Placeholder chrome a dashboard panel shows before its fragment arrives.
    Same x-ui.card header as the real panel (so the title is correct and the
    page never reflows once data lands), 3 shimmer bars of varying widths in
    the body.

    Light-first utilities only — no dark: variants; html.dark remaps in app.css.
--}}
{{-- `:title`, not `title="{{ }}"`: an interpolated attribute is escaped
     into the attribute and escaped again when the component echoes it, so a
     panel called "Stock & warehouses" rendered as "Stock &amp;amp; warehouses". --}}
<x-ui.card flush :title="$title">
    <div class="space-y-3 p-5">
        <div class="h-3.5 w-3/4 animate-pulse rounded bg-gray-100"></div>
        <div class="h-3.5 w-1/2 animate-pulse rounded bg-gray-100"></div>
        <div class="h-3.5 w-5/6 animate-pulse rounded bg-gray-100"></div>
    </div>
</x-ui.card>
