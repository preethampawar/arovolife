@php
    /**
     * Route-derived breadcrumbs. No view declares its own trail: the ancestry
     * comes from the same map that renders the sidebar, so a new admin page
     * gets a correct trail the moment its route name lands under a nav prefix.
     */
    $crumbTrail = \App\Modules\Shared\Support\AdminNavigation::resolve(
        request()->route()?->getName(),
        auth()->user(),
    );
@endphp

@php
    // The ADMIN mark. Rendered as a link inside the trail, and as a bare
    // span on pages with no trail (the dashboard), so the topbar's eyebrow
    // line is never empty and the bar never changes height between pages.
    $crumbChip = 'inline-flex shrink-0 items-center rounded-md border border-sunrise-200 bg-sunrise-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] text-sunrise-800 transition-colors hover:bg-sunrise-100';
@endphp

@if ($crumbTrail)
    @php
        $crumbLinks = [
            ['label' => 'Admin', 'url' => route('admin.dashboard')],
            ['label' => $crumbTrail['group']['label'], 'url' => null],
            ['label' => $crumbTrail['item']['label'], 'url' => route($crumbTrail['item']['route'], $crumbTrail['item']['params'] ?? [])],
        ];

        if ($crumbTrail['child']) {
            $crumbLinks[] = ['label' => $crumbTrail['child']['label'], 'url' => route($crumbTrail['child']['route'])];
        }

        // A group with no label (Overview) renders flat in the sidebar and has
        // no crumb either.
        $crumbLinks = array_values(array_filter($crumbLinks, fn (array $crumb): bool => filled($crumb['label'])));

        // A group whose only item carries the group's own name (Compensation)
        // would otherwise read "Compensation › Compensation". Collapse any
        // two consecutive crumbs with the same text, keeping the later one
        // because it is the linked entry.
        $crumbLinks = array_values(array_filter(
            $crumbLinks,
            fn (array $crumb, int $i): bool => $i === 0 || $crumb['label'] !== $crumbLinks[$i - 1]['label'],
            ARRAY_FILTER_USE_BOTH,
        ));

        // The current page is whatever heading the view set. Index pages
        // normally repeat their own nav label, so drop the duplicate rather
        // than render "Warehouses › Warehouses".
        $crumbLeaf = trim($__env->yieldContent('heading'));
        $crumbLast = end($crumbLinks);

        if ($crumbLeaf === '' || $crumbLeaf === 'Admin Console') {
            $crumbLeaf = $crumbLast['label'];
        }

        // Drop the leaf when it only restates its parent — either exactly
        // ("Warehouses › Warehouses") or as a qualified form of it
        // ("Engine Runs › Compensation — Engine Runs"). Compared on
        // letters and digits alone so punctuation and an em-dash prefix do
        // not defeat it.
        $crumbNormalise = static fn (string $text): string => mb_strtolower(
            (string) preg_replace(
                '/[^\p{L}\p{N}]+/u',
                '',
                html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ),
        );

        $crumbLeafKey = $crumbNormalise($crumbLeaf);
        $crumbLastKey = $crumbNormalise($crumbLast['label']);

        if ($crumbLeafKey === $crumbLastKey || ($crumbLastKey !== '' && str_ends_with($crumbLeafKey, $crumbLastKey))) {
            array_pop($crumbLinks);
        }

        $crumbs = [...$crumbLinks, ['label' => $crumbLeaf, 'url' => null, 'current' => true]];
        $crumbCount = count($crumbs);
    @endphp

    {{-- No bar chrome of its own any more: this renders as the eyebrow line
         inside the topbar, which owns the surface and the bottom border. --}}
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1.5 text-[11px] leading-4">
            @foreach ($crumbs as $crumbIndex => $crumb)
                @php
                    // On mobile only the last two crumbs (and the separator
                    // between them) survive; the rest are hidden, not dropped,
                    // so assistive tech still reads the whole trail.
                    $crumbHidden = $crumbIndex < $crumbCount - 2 ? 'hidden sm:flex' : 'flex';
                @endphp

                @if ($crumbIndex > 0)
                    <li aria-hidden="true" class="{{ $crumbIndex < $crumbCount - 1 ? 'hidden sm:flex' : 'flex' }} items-center text-gray-300">{{ svg('lucide-chevron-right', 'w-3 h-3') }}</li>
                @endif

                <li class="{{ $crumbHidden }} items-center">
                    @if (! empty($crumb['current']))
                        {{-- The leaf is the view's `heading` section. Blade's
                             `@section('x', $value)` already runs `e()` over
                             that value, so it is echoed raw here exactly as
                             the layout header echoes it — `{{ }}` would
                             double-escape and render "Returns &amp;amp;
                             Refunds". Nav labels are literals in
                             AdminNavigation. --}}
                        <span aria-current="page" class="font-medium text-gray-500">{!! $crumb['label'] !!}</span>
                    @elseif ($crumbIndex === 0 && $crumb['label'] === 'Admin')
                        {{-- The root crumb is the ADMIN mark. It stays an <a>
                             whose accessible name is exactly "Admin" —
                             admin-navigation.spec.js selects it by role+name. --}}
                        <a href="{{ $crumb['url'] }}" class="{{ $crumbChip }}">Admin</a>
                    @elseif ($crumb['url'])
                        <a href="{{ $crumb['url'] }}" class="text-gray-500 transition-colors hover:text-gray-900">{{ $crumb['label'] }}</a>
                    @else
                        {{-- gray-500, not 400: at 11px on the dark topbar
                             gray-400 is 4.14:1, under the 4.5:1 AA floor for
                             small text. axe catches this one. --}}
                        <span class="text-gray-500">{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@else
    {{-- Dashboard, and any route not under a nav prefix: no trail, so no
         <nav aria-label="Breadcrumb"> at all — admin-navigation.spec.js
         asserts a count of 0 on /admin. The chip still renders so the ADMIN
         mark is present on every page and the topbar keeps its height. --}}
    <span class="{{ $crumbChip }}">Admin</span>
@endif
