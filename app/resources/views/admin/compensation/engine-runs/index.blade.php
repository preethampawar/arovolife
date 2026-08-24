@extends('admin.layouts.admin')
@section('title', 'Engine Runs')
@section('heading', 'Compensation — Engine Runs')

@section('content')

{{-- Warning banner --}}
<div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
    <strong>Engine runs move real money into wallets.</strong>
    Every engine is idempotent — a re-run never credits anybody twice — but a manual run should only replace a scheduled run
    that failed or never happened. Triggering an engine also runs the engines it depends on for any missing periods,
    and every trigger is permanently audit-logged with your admin ID and the reason you provide.
</div>

{{-- Flash messages (status / error / validation) are rendered by the admin
     layout for every page. Do not repeat them here. --}}

{{-- TESTING-ONLY full recompute. Rendered only when RecomputeGuard permits it,
     so on any environment where it is off there is no trace of it here. --}}
@if($recomputeAllowed)
@php $recomputeTotal = array_sum($recomputeRowCounts); @endphp
<div class="mb-6 rounded-xl border-2 border-red-300 bg-red-50 p-4">
    <p class="text-sm font-bold text-red-900">Testing tool — recompute everything from scratch</p>
    <p class="mt-1 text-xs text-red-800 max-w-4xl">
        Deletes <strong>every</strong> bonus result, frozen pool, carry-forward, rank qualification, repurchase cycle,
        wallet credit and payout batch
        (<strong>{{ \App\Modules\Shared\Support\IndianNumber::format($recomputeTotal) }}</strong> rows on
        <code class="font-mono bg-red-100 px-1 rounded">{{ $recomputeTargetDatabase }}</code>)
        and replays every engine from the first BV date up to right now — including today's cut-off, this week's
        payout and this month's bonuses, computed as at this moment. Orders, the BV ledger, distributors, the Genos
        and the plan settings are kept.
    </p>
    <p class="mt-1 text-xs text-red-700 max-w-4xl">
        The daily and monthly schedulers are unaffected — they keep running normally and each run still freezes its
        period as usual. This button is the only thing that throws those snapshots away. Wallet credits are deleted
        outright, not reversed, so any figure a distributor has already seen will change.
    </p>
    <p class="mt-1 text-xs text-red-700 max-w-4xl">
        Needs a queue worker that will let a job run for minutes —
        <code class="font-mono bg-red-100 px-1 rounded">queue:work</code> or the
        <code class="font-mono bg-red-100 px-1 rounded">compensation:recompute-all</code> command.
        <strong><code class="font-mono bg-red-100 px-1 rounded">queue:listen</code> cannot run this</strong>: it kills
        every job at 60 seconds, which leaves the database wiped and half-rebuilt.
    </p>

    @if($recomputeRowCounts !== [])
    <details class="mt-3">
        <summary class="cursor-pointer text-xs font-medium text-red-800">What would be destroyed</summary>
        <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-red-200 bg-white p-3">
            <table class="w-full text-xs">
                @foreach($recomputeRowCounts as $table => $count)
                <tr>
                    <td class="py-0.5 font-mono text-gray-600">{{ $table }}</td>
                    <td class="py-0.5 text-right font-medium text-gray-900">
                        {{ \App\Modules\Shared\Support\IndianNumber::format($count) }}
                    </td>
                </tr>
                @endforeach
            </table>
        </div>
    </details>
    @endif

    {{-- Live progress. Hidden until the poller sees a run; the whole panel is
         driven by the recompute-progress endpoint, which reads one cache key. --}}
    <div id="recompute-progress" class="mt-4 hidden rounded-lg border border-red-200 bg-white p-4">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-semibold text-gray-900">
                <span id="rp-phase">Starting</span>
                <span id="rp-detail" class="font-normal text-gray-500"></span>
            </p>
            <span id="rp-percent" class="text-sm font-bold tabular-nums text-red-700">0%</span>
        </div>

        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200">
            <div id="rp-bar" class="h-full rounded-full bg-red-600 transition-all duration-500" style="width:0%"></div>
        </div>

        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
            <div><dt class="text-gray-500">Replaying</dt><dd id="rp-date" class="font-medium text-gray-900 tabular-nums">—</dd></div>
            <div><dt class="text-gray-500">Days</dt><dd id="rp-days" class="font-medium text-gray-900 tabular-nums">—</dd></div>
            <div><dt class="text-gray-500">Orders re-propagated</dt><dd id="rp-orders" class="font-medium text-gray-900 tabular-nums">—</dd></div>
            <div><dt class="text-gray-500">Engine runs</dt><dd id="rp-runs" class="font-medium text-gray-900 tabular-nums">—</dd></div>
        </dl>

        <p class="mt-2 text-xs text-gray-500">
            Engines on this date: <span id="rp-engines" class="font-mono text-gray-700">—</span>
        </p>

        <p id="rp-error" class="mt-3 hidden rounded-lg border border-red-300 bg-red-50 p-3 text-xs text-red-800"></p>
        <p id="rp-done" class="mt-3 hidden rounded-lg border border-green-300 bg-green-50 p-3 text-xs text-green-800"></p>
    </div>

    <form method="POST" action="{{ route('admin.compensation.engine-runs.recompute-all') }}"
          class="mt-4 flex flex-wrap items-end gap-3"
          data-confirm="Wipe every bonus, payout and wallet credit, then replay all engines?"
          data-confirm-title="Destroy and rebuild all compensation data?"
          data-confirm-impact="This cannot be undone. {{ \App\Modules\Shared\Support\IndianNumber::format($recomputeTotal) }} rows on {{ $recomputeTargetDatabase }} are deleted and rebuilt from the surviving orders. The replay runs in the background and takes several minutes.">
        @csrf
        <div>
            <label for="recompute-confirm-db" class="block text-xs font-medium text-red-900 mb-1">
                Type <span class="font-mono">{{ $recomputeTargetDatabase }}</span> to unlock
            </label>
            <input type="text" id="recompute-confirm-db" autocomplete="off"
                   data-expected="{{ $recomputeTargetDatabase }}"
                   class="rounded-lg border-red-300 text-sm font-mono focus:border-red-500 focus:ring-red-500">
        </div>
        <button type="submit" id="recompute-submit" disabled
                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
            Recompute everything
        </button>
    </form>
</div>

<script>
// Typed-database gate. Deliberately a second lock in front of the shared
// confirm modal: this action is irreversible and the staging database carries
// real distributor data, so the operator must name the target before the
// button is even clickable.
(function () {
    var input = document.getElementById('recompute-confirm-db');
    var button = document.getElementById('recompute-submit');
    if (!input || !button) { return; }

    // Chrome restores the typed value across the post-run reload, which would
    // hand back an armed button nobody re-authorised. Clear it on every load so
    // the lock always has to be opened deliberately.
    input.value = '';
    button.disabled = true;

    input.addEventListener('input', function () {
        button.disabled = input.value.trim() !== input.dataset.expected;
    });
})();

// Live progress poller. The replay runs on the queue for minutes, so without
// this the page gives no sign anything is happening.
(function () {
    var panel = document.getElementById('recompute-progress');
    if (!panel) { return; }

    var url = @json(route('admin.compensation.engine-runs.recompute-progress'));
    var el = function (id) { return document.getElementById(id); };
    var timer = null;

    // Set once this page instance has actually watched a run in flight, so the
    // completion reload happens exactly once. Without it the reloaded page —
    // which reads the same 'complete' state back out of the cache — would
    // reload itself again, forever.
    var watchedARun = false;

    function text(id, value) { var n = el(id); if (n) { n.textContent = value; } }

    function render(state) {
        if (!state || state.state === 'idle') { panel.classList.add('hidden'); return; }

        panel.classList.remove('hidden');
        text('rp-phase', state.phase || '—');
        text('rp-detail', state.detail ? ' — ' + state.detail : '');
        text('rp-percent', (state.percent || 0) + '%');
        el('rp-bar').style.width = (state.percent || 0) + '%';
        text('rp-date', state.current_date || '—');
        text('rp-days', state.days_total ? state.days_done + ' / ' + state.days_total : (state.days_done || '—'));
        text('rp-orders', state.orders_total ? state.orders_done + ' / ' + state.orders_total : (state.orders_done || '—'));
        text('rp-runs', state.engine_runs || 0);
        text('rp-engines', (state.current_engines && state.current_engines.length)
            ? state.current_engines.join(', ')
            : '—');

        var err = el('rp-error');
        var done = el('rp-done');

        // A fresh run must not inherit the previous run's green summary or red
        // failure box — the panel is reused, so reset it whenever one starts.
        if (state.state === 'running') {
            watchedARun = true;
            err.classList.add('hidden');
            done.classList.add('hidden');
            el('rp-bar').classList.remove('bg-green-600', 'bg-red-800');
            el('rp-bar').classList.add('bg-red-600');
        }

        if (state.state === 'failed') {
            el('rp-bar').classList.remove('bg-red-600');
            el('rp-bar').classList.add('bg-red-800');
            err.textContent = 'Replay failed: ' + (state.error || 'unknown error')
                + ' — the data is wiped and only partly rebuilt. Run it again to start clean.';
            err.classList.remove('hidden');
            stop();
            return;
        }

        if (state.state === 'complete' && state.summary) {
            el('rp-bar').classList.remove('bg-red-600');
            el('rp-bar').classList.add('bg-green-600');
            done.textContent = 'Complete — ' + state.summary.days + ' days, '
                + state.summary.orders + ' orders, ' + state.summary.engine_runs + ' engine runs, '
                + state.summary.rows_removed.toLocaleString() + ' rows replaced in '
                + state.summary.duration_seconds + 's.'
                + (watchedARun ? ' Refreshing the runs below…' : '');
            done.classList.remove('hidden');
            stop();

            // Every card below is server-rendered from engine_runs, which the
            // replay has just rewritten — reload so they show the new run
            // instead of the pre-wipe timestamps. The completed state stays in
            // the cache, so this panel renders the same summary afterwards.
            if (watchedARun) {
                watchedARun = false;
                setTimeout(function () { window.location.reload(); }, 1200);
            }
        }
    }

    function stop() { if (timer) { clearInterval(timer); timer = null; } }

    function poll() {
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(render)
            .catch(function () { /* transient: keep polling */ });
    }

    poll();
    timer = setInterval(poll, 2000);
})();
</script>
@endif

<div class="space-y-4">
    @foreach($engines as $engine)
    @php
        /** @var \App\Modules\Compensation\Support\EngineDefinition $definition */
        $definition = $engine['definition'];
        /** @var \App\Modules\Compensation\Models\EngineRun|null $lastRun */
        $lastRun = $engine['lastRun'];
        $isMonth = $definition->periodType === \App\Modules\Compensation\Support\EnginePeriodType::Month;
        $notScheduled = ! $definition->cadence->isScheduled();
    @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">

            {{-- Identity + status column --}}
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <h3 class="text-sm font-semibold text-gray-900">{{ $definition->label }}</h3>
                    <x-help-tip :text="$definition->description" />

                    @if($engine['flagOn'] === null)
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-600">Always on</span>
                    @else
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700">Flag on</span>
                    @endif

                    @if($notScheduled)
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-700">Not scheduled — manual only</span>
                    @endif
                </div>

                <p class="text-xs text-gray-500 mb-2">Schedule: {{ $definition->scheduleText() }}</p>

                {{-- Last run --}}
                <p class="text-xs text-gray-600 mb-2">
                    <span class="font-medium text-gray-700">Last run:</span>
                    @if($lastRun !== null)
                        @php
                            $pill = match(true) {
                                $lastRun->isStale() => ['bg-amber-100 text-amber-700', 'stale'],
                                $lastRun->status === \App\Modules\Compensation\Models\EngineRun::STATUS_RUNNING => ['bg-blue-100 text-blue-700', 'running'],
                                $lastRun->status === \App\Modules\Compensation\Models\EngineRun::STATUS_SUCCEEDED => ['bg-green-100 text-green-700', 'succeeded'],
                                $lastRun->status === \App\Modules\Compensation\Models\EngineRun::STATUS_FAILED => ['bg-red-100 text-red-700', 'failed'],
                                default => ['bg-gray-100 text-gray-600', $lastRun->status],
                            };
                        @endphp
                        <span class="inline-flex px-2 py-0.5 rounded font-medium {{ $pill[0] }}">{{ $pill[1] }}</span>
                        {{ $lastRun->started_at->format('d M Y H:i') }}
                        · period {{ $definition->displayPeriod($lastRun->period_start) }}
                        · {{ $lastRun->trigger }}
                    @elseif($engine['derivedPeriod'] !== null)
                        latest results found for {{ $definition->displayPeriod($engine['derivedPeriod']) }}
                        <span class="text-gray-400">(derived from result tables — no run log yet)</span>
                    @else
                        <span class="text-gray-400">never recorded</span>
                    @endif
                </p>

                {{-- Dependencies --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Runs first:</span>
                    @forelse($engine['dependencyLabels'] as $label)
                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-medium bg-indigo-50 text-indigo-700 border border-indigo-100">{{ $label }}</span>
                    @empty
                    <span class="text-[10px] text-gray-400">none — runs on its own</span>
                    @endforelse
                </div>

                <div class="mt-2 flex items-center gap-3 text-xs">
                    @if($definition->reportRouteName !== null)
                    <a href="{{ route($definition->reportRouteName) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">Report page →</a>
                    @endif
                    <a href="{{ route('admin.compensation.engine-runs.events', ['engine' => $definition->key]) }}"
                       class="text-indigo-600 hover:text-indigo-800 font-medium">Run events →</a>
                </div>
            </div>

            {{-- Run form --}}
            @if(! $definition->manuallyTriggerable)
            <div class="w-full lg:w-80 shrink-0 rounded-lg border border-gray-100 bg-gray-50 p-3 text-xs text-gray-500">
                <span class="font-semibold text-gray-700">Scheduler-only.</span>
                Payout batches are created by the scheduler and approved separately on the Payouts page,
                so the same person never both creates and approves a batch.
            </div>
            @else
            <form method="POST" action="{{ route('admin.compensation.engine-runs.trigger') }}"
                  class="w-full lg:w-80 shrink-0 rounded-lg border border-gray-100 bg-gray-50 p-3"
                  data-confirm="This queues {{ $definition->label }} for the chosen period{{ count($engine['dependencyLabels']) > 0 ? ', after first running any missing prerequisite periods of: '.implode(', ', $engine['dependencyLabels']) : '' }}."
                  data-confirm-title="Confirm: Run {{ $definition->label }}"
                  data-confirm-impact="Wallet credits and result rows are written exactly as a scheduled run would write them. Idempotent — periods already computed are skipped, and nobody is credited twice.">
                @csrf
                <input type="hidden" name="engine" value="{{ $definition->key }}">
                <div class="mb-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">
                        {{ $isMonth ? 'Month' : 'Date' }}
                        <x-help-tip :text="$isMonth ? 'The month this engine should process. Pre-filled with the month the scheduler would use.' : 'The day this engine should process. Pre-filled with the day the scheduler would use.'" />
                    </label>
                    <input type="{{ $isMonth ? 'month' : 'date' }}" name="period" value="{{ old('engine') === $definition->key ? old('period') : $engine['defaultPeriodValue'] }}" required
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                </div>
                <div class="mb-3">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required, min 10 chars)
                        <x-help-tip text="Why this engine is being run manually. Recorded in the audit log." />
                    </label>
                    <textarea name="reason" rows="2" required placeholder="e.g. Scheduled run on the 2nd failed — re-running after fix"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">{{ old('engine') === $definition->key ? old('reason') : '' }}</textarea>
                </div>
                <button type="submit" class="w-full px-4 py-2 rounded-lg bg-brand-500 text-white text-sm font-medium hover:bg-brand-600">
                    Preview &amp; Confirm &rarr;
                </button>
            </form>
            @endif
        </div>
    </div>
    @endforeach
</div>

@endsection
