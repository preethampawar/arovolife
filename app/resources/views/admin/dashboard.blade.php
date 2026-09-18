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

    function load(el) {
        if (el.dataset.panelState === 'loading') { return; }
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
                el.dataset.panelState = 'error';
                el.innerHTML = markup('error', el.dataset.panelUrl);
            });
    }

    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-panel-url]'));

    panels.filter(function (el) { return el.dataset.panelLazy !== '1'; }).forEach(load);

    var lazy = panels.filter(function (el) { return el.dataset.panelLazy === '1'; });

    if (!('IntersectionObserver' in window)) {
        lazy.forEach(load);
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
})();
</script>
@endpush
