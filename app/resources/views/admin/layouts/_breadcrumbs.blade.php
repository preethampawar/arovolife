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

    <div class="bg-white border-b border-gray-200 px-4 sm:px-6 lg:px-8 py-2">
        <nav aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1.5 text-xs">
                @foreach ($crumbs as $crumbIndex => $crumb)
                    @php
                        // On mobile only the last two crumbs (and the separator
                        // between them) survive; the rest are hidden, not dropped,
                        // so assistive tech still reads the whole trail.
                        $crumbHidden = $crumbIndex < $crumbCount - 2 ? 'hidden sm:flex' : 'flex';
                    @endphp

                    @if ($crumbIndex > 0)
                        <li aria-hidden="true" class="{{ $crumbIndex < $crumbCount - 1 ? 'hidden sm:flex' : 'flex' }} items-center text-gray-400">&rsaquo;</li>
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
                            <span aria-current="page" class="font-medium text-gray-900">{!! $crumb['label'] !!}</span>
                        @elseif ($crumb['url'])
                            <a href="{{ $crumb['url'] }}" class="text-gray-500 hover:text-gray-900 hover:underline">{{ $crumb['label'] }}</a>
                        @else
                            <span class="text-gray-500">{{ $crumb['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>
@endif
