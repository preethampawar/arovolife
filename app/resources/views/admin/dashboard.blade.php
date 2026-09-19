@extends('admin.layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')

@php
    // The shell issues zero data queries. $panels is already permission- and
    // feature-flag-filtered by DashboardPanels::visibleTo() — every entry here
    // is one this viewer may see. Group consecutive 'half' spans into their own
    // grid row so the layout still pairs correctly if the registry changes;
    // 'full' spans render on their own row.
    $rows = [];
    $halfRow = [];

    foreach ($panels as $key => $meta) {
        if ($meta['span'] === 'half') {
            $halfRow[$key] = $meta;

            continue;
        }

        if ($halfRow) {
            $rows[] = ['span' => 'half', 'panels' => $halfRow];
            $halfRow = [];
        }

        $rows[] = ['span' => 'full', 'panels' => [$key => $meta]];
    }

    if ($halfRow) {
        $rows[] = ['span' => 'half', 'panels' => $halfRow];
    }
@endphp

<div class="space-y-5" id="dashboardPanels">
    @foreach($rows as $row)
        @if($row['span'] === 'half')
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            @foreach($row['panels'] as $key => $meta)
            <div data-panel-url="{{ route('admin.dashboard.panel', $key) }}"
                 data-panel-lazy="{{ $meta['lazy'] ? '1' : '0' }}">
                <x-ui.panel-skeleton :title="$meta['title']" />
            </div>
            @endforeach
        </div>
        @else
            @foreach($row['panels'] as $key => $meta)
            <div data-panel-url="{{ route('admin.dashboard.panel', $key) }}"
                 data-panel-lazy="{{ $meta['lazy'] ? '1' : '0' }}">
                <x-ui.panel-skeleton :title="$meta['title']" />
            </div>
            @endforeach
        @endif
    @endforeach
</div>

@endsection

@push('scripts')
<script>
(function () {
    var root = document.getElementById('dashboardPanels');
    if (!root) { return; }

    function markup(state, url) {
        if (state === 'error') {
            return '<div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">' +
                '<p class="text-sm text-gray-600">This panel could not be loaded.</p>' +
                '<button type="button" data-panel-retry class="mt-2 text-sm font-medium text-brand-700 hover:text-brand-800">Try again</button>' +
                '</div>';
        }
        return '';
    }

    // `background` marks a refresh nobody asked for: the 60-second tick. It
    // changes one thing — a failure leaves the panel as it is. Figures a minute
    // old beat an error card the viewer never clicked for, and the next tick
    // will try again anyway.
    function load(el, background) {
        if (el.dataset.panelState === 'loading') { return; }
        var previous = el.dataset.panelState;
        el.dataset.panelState = 'loading';
        fetch(el.dataset.panelUrl, {
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) {
                if (!r.ok) { throw new Error(String(r.status)); }
                return r.text();
            })
            .then(function (html) {
                el.innerHTML = html;
                el.dataset.panelState = 'done';
            })
            .catch(function () {
                if (background && previous === 'done') {
                    el.dataset.panelState = 'done';
                    return;
                }
                el.dataset.panelState = 'error';
                el.innerHTML = markup('error', el.dataset.panelUrl);
            })
            .then(function () {
                // Stamped on success and on failure alike, so a panel that
                // errors waits its full turn before being tried again instead
                // of being retried on every tick.
                el.dataset.panelFetchedAt = String(Date.now());
            });
    }

    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-panel-url]'));

    panels.filter(function (el) { return el.dataset.panelLazy !== '1'; })
        // Not `.forEach(load)`: forEach passes the index as a second argument,
        // which `load` reads as its `background` flag.
        .forEach(function (el) { load(el); });

    var lazy = panels.filter(function (el) { return el.dataset.panelLazy === '1'; });

    if (!('IntersectionObserver' in window)) {
        lazy.forEach(function (el) { load(el); });
    } else {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    io.unobserve(entry.target);
                    load(entry.target);
                }
            });
        }, { rootMargin: '250px' });
        lazy.forEach(function (el) { io.observe(el); });
    }

    root.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-panel-retry], [data-panel-refresh]');
        if (!trigger) { return; }
        var el = trigger.closest('[data-panel-url]');
        if (!el) { return; }
        el.dataset.panelState = '';
        load(el);
    });

    // Auto-refresh.
    //
    // STALE_MS matches DashboardPanelData::TTL_SECONDS — refreshing faster than
    // the cache would spend a request to be handed the same numbers back.
    //
    // The tick runs more often than that and re-fetches per panel by age, so a
    // panel that happened to load just before a tick is not held for a second
    // full interval. A tick costs nothing on its own; only a panel that is
    // genuinely a minute old makes a request.
    //
    // Two things are deliberately excluded. A panel that has never loaded — a
    // lazy one still below the fold — stays untouched, so scrolling remains the
    // only thing that fetches it. And a hidden tab refreshes nothing at all: a
    // dashboard left open in a background tab overnight is a request per panel
    // per minute for an audience of nobody. Returning to the tab refreshes it
    // immediately, which is also what makes the figures right when you come
    // back to them.
    var STALE_MS = 60000;
    var TICK_MS = 15000;

    function refreshStale() {
        if (document.hidden) { return; }
        var cutoff = Date.now() - STALE_MS;
        panels.forEach(function (el) {
            if (el.dataset.panelState !== 'done' && el.dataset.panelState !== 'error') { return; }
            if (Number(el.dataset.panelFetchedAt || 0) > cutoff) { return; }
            load(el, true);
        });
    }

    setInterval(refreshStale, TICK_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { refreshStale(); }
    });
})();
</script>
@endpush
