{{--
    Purchase mark — the one thing a distributor should be able to read off a
    tree card without reading the card: has this person actually bought
    anything, and are they past the qualifying gate?

      red    — not active, or active with nothing purchased yet
      yellow — active and buying, still under the gate
      green  — active and at or above the gate (comp.gsb.min_bv_paise)

    It sits on the card's top edge, centred between the status dot (top-left)
    and the actions menu (top-right), and counter-scales as the canvas zooms
    out (--tree-mark-scale, set by setScale() in tree/_content.blade.php) so
    it stays a legible blob of colour long after the card's text has turned
    to mush.

    Derived from personal BV, so it carries personal BV's R-65 visibility:
    $state is null for any card whose BV row this viewer may not see, and the
    mark then renders nothing at all.

    Expects: $state (string|null), $purchaseMarks (state => class/icon/label/
    hint, resolved once per canvas in tree/_content.blade.php).

    Tailwind scans resources/**/*.blade.php, never app/**/*.php, so the fill
    and glyph classes the service hands over are listed literally here to keep
    them in the build: bg-green-600 bg-yellow-400 bg-red-600 text-white
    text-yellow-950

    @see \App\Modules\Identity\Services\DistributorIdCardStats::purchaseMarkMap()
--}}
@php $mark = $state === null ? null : (($purchaseMarks ?? [])[$state] ?? null); @endphp

@if($mark !== null)
    <div data-purchase-mark class="group/mark absolute -top-2.5 left-1/2 -translate-x-1/2 z-20">
        {{-- Two nested spans: the wrapper owns the centring translate, the
             inner one owns the zoom counter-scale, so neither transform
             clobbers the other. --}}
        <span class="block origin-center" style="transform: scale(var(--tree-mark-scale, 1));">
            <span class="flex h-5 w-5 items-center justify-center rounded-full ring-2 ring-white shadow {{ $mark['class'] }}">
                <x-lucide-star class="h-3 w-3 {{ $mark['icon'] }}" fill="currentColor" />
            </span>
        </span>
        <span class="sr-only">{{ $mark['hint'] }}</span>
        <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 top-full mt-1.5 z-50 hidden group-hover/mark:block whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-lg">
            {{ $mark['hint'] }}
            <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-gray-900"></span>
        </div>
    </div>
@endif
