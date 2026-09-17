@extends('admin.layouts.admin')
@section('title', 'Engine Runs')
@section('heading', 'Compensation — Engine Runs')

@section('content')

{{-- The retry banner. Rendered ONLY when the chain's last finished attempt
     failed and no later night has succeeded since — on a healthy platform this
     block does not exist, which is what stops it becoming wallpaper nobody
     reads. EngineStatusService::failedChainRun() decides; see its docblock for
     why a healed night stops showing. --}}
@if($failedChain !== null)
<div class="mb-5 rounded-lg border-2 border-rose-400 bg-rose-50 p-4 text-sm text-rose-900">
    <div class="flex items-start gap-3">
        <x-lucide-circle-alert class="w-5 h-5 mt-0.5 shrink-0 text-rose-600" />
        <div class="flex-1">
            <strong class="block text-base mb-1">
                The nightly chain failed on {{ $failedChain['night'] }}.
            </strong>
            <p class="mb-3">
                It stopped at {{ $failedChain['failedAt'] }}. The steps that had already finished kept what they
                credited; the steps after the failure never ran, so nobody has been credited for them yet.
            </p>

            @if($failedChain['error'] !== null)
            <div class="mb-3 rounded border border-rose-200 bg-white p-3 font-mono text-xs whitespace-pre-line">{{ $failedChain['error'] }}</div>
            @endif

            @if($failedChain['steps'] !== [])
            <div class="mb-3">
                <p class="font-medium mb-1">What each step did that night:</p>
                <ul class="space-y-0.5">
                    @foreach($failedChain['steps'] as $step)
                    <li class="flex items-center gap-2">
                        @if($step['status'] === 'succeeded')
                            <x-lucide-check class="w-3.5 h-3.5 text-emerald-600 shrink-0" />
                        @elseif($step['status'] === 'failed')
                            <x-lucide-x class="w-3.5 h-3.5 text-rose-600 shrink-0" />
                        @else
                            <x-lucide-minus class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                        @endif
                        <span>{{ $step['label'] }}</span>
                        <span class="text-xs text-rose-700">&mdash; {{ $step['status'] }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            <p class="mb-3">
                <strong>Nothing is lost by waiting.</strong> Tonight's chain backfills the cut-offs this night missed,
                and rebuilds a Tuesday payout batch it skipped &mdash; still dated that Tuesday, so the week paid is
                unchanged. Retry here once the cause is fixed if you would rather not wait a day.
            </p>
            <p class="mb-3">
                A retry credits wallets and, if this night owed a weekly payout batch that was never started,
                <strong>rebuilds it in your name</strong> &mdash; so someone else has to approve it. A batch that
                started and was killed part-way is <em>not</em> rebuilt by this button and needs the platform team.
                The monthly payout close is left alone: the scheduler rebuilds that on any night from the 8th,
                unaided.
            </p>

            @if($failedChain['inFlight'])
            {{-- A retry does not clear this banner while it runs, so say so
                 rather than leave an enabled button under an unchanged page. --}}
            <p class="mt-3 border-t border-rose-200 pt-3 font-medium">
                A retry is running now. This banner clears once it finishes &mdash; refresh to check.
            </p>
            @else
            @can('finance.record')
            <form method="POST" action="{{ route('admin.compensation.engine-runs.retry-chain') }}"
                  class="mt-3 border-t border-rose-200 pt-3"
                  data-confirm="Re-run the {{ $failedChain['night'] }} chain from the step that failed?"
                  data-confirm-title="Retry the {{ $failedChain['night'] }} chain?"
                  data-confirm-impact="The chain resumes where it stopped, and every engine skips a distributor already credited for the period, so nobody is paid twice. A weekly payout batch this night owed, and never started, is rebuilt and recorded as created by you, so a second person must approve it. It runs in the background and can take several minutes.">
                @csrf
                <input type="hidden" name="night" value="{{ $failedChain['nightValue'] }}">
                <label for="retry-chain-reason" class="block text-xs font-medium text-rose-900 mb-1">
                    What was fixed? (recorded in the audit log with your admin ID)
                </label>
                <div class="flex flex-wrap items-start gap-2">
                    <input type="text" id="retry-chain-reason" name="reason" required minlength="10" maxlength="500"
                           placeholder="e.g. Corrected the bank details the payout step threw on"
                           class="flex-1 min-w-64 rounded border border-rose-300 px-3 py-2 text-sm">
                    <button type="submit"
                            class="rounded bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">
                        Retry this night
                    </button>
                </div>
            </form>
            @endcan
            @endif
        </div>
    </div>
</div>
@endif

{{-- Warning banner --}}
<div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
    <strong>Engine runs move real money into wallets.</strong>
    Every engine is idempotent — a re-run never credits anybody twice — but a manual run should only replace a scheduled run
    that failed or never happened. Triggering an engine also runs the engines it depends on for any missing periods,
    and every trigger is permanently audit-logged with your admin ID and the reason you provide.
</div>

{{-- On a test environment the recompute owns the calendar, so the per-engine
     trigger forms are not rendered at all and this explains why. Production
     never sees it: RecomputeGuard is shut there and the buttons stay. --}}
@if($manualTriggersDisabled)
<div class="mb-5 rounded-lg border border-indigo-300 bg-indigo-50 p-4 text-sm text-indigo-900">
    <strong>Engines are not run one at a time on this environment.</strong>
    Use the recompute below: it wipes the derived rows and replays every engine at the instant the scheduler would
    have fired it &mdash; the cut-off for a day at 00:10 the next morning, a month's bonuses on the 1st, its payout on
    the 8th. That is what makes these figures match what production would produce. Firing one engine by hand instead
    runs it at the wrong instant, against a period that has not finished forming.
</div>
@endif

{{-- Flash messages (status / error / validation) are rendered by the admin
     layout for every page. Do not repeat them here. --}}

{{-- TESTING-ONLY purchase-data reset. Same guard as the recompute above: when
     it refuses, nothing here is rendered at all — including the database name
     and the row counts, which are not for the whole admin role family. --}}
@if($destructiveToolsVisible)
@php $purchaseResetTotal = array_sum($purchaseResetRowCounts); @endphp
<div class="mb-6 rounded-xl border-2 border-red-300 bg-red-50 p-4">
    <p class="text-sm font-bold text-red-900">Testing tool — reset purchase data (start a fresh test cycle)</p>
    <p class="mt-1 text-xs text-red-800 max-w-4xl">
        Deletes the <strong>orders themselves</strong> along with everything derived from them — the BV ledger, every
        bonus result and frozen pool, carry-forwards, repurchase cycles, wallet credits, payout batches, returns and
        carts (<strong>{{ \App\Modules\Shared\Support\IndianNumber::format($purchaseResetTotal) }}</strong> rows on
        <code class="font-mono bg-red-100 px-1 rounded">{{ $recomputeTargetDatabase }}</code>).
    </p>
    <p class="mt-1 text-xs text-red-800 max-w-4xl">
        <strong>Kept:</strong> users, distributors, the Genos tree and sponsorship, KYC, consents, settings, the whole
        compensation plan configuration, arete centers, the product catalog, coupons, customers and the audit log.
        This is the difference from a recompute: a recompute rebuilds the same history, this leaves no history to
        rebuild. Afterwards a recompute finishes in seconds, because there is nothing to replay until you place new
        orders.
    </p>

    @if($purchaseResetRowCounts !== [])
    <details class="mt-3">
        <summary class="cursor-pointer text-xs font-medium text-red-800">What would be destroyed</summary>
        <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-red-200 bg-white p-3">
            <table class="w-full text-xs">
                @foreach($purchaseResetRowCounts as $table => $count)
                <tr>
                    <td class="py-0.5 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
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

    <form method="POST" action="{{ route('admin.compensation.engine-runs.reset-purchase-data') }}"
          class="mt-4 flex flex-wrap items-end gap-3"
          data-confirm="Delete every order and everything derived from it?"
          data-confirm-title="Reset all purchase data?"
          data-confirm-impact="This cannot be undone. {{ \App\Modules\Shared\Support\IndianNumber::format($purchaseResetTotal) }} rows on {{ $recomputeTargetDatabase }} are deleted, including the orders themselves. Distributors, the Genos tree and the plan settings are kept.">
        @csrf
        <div>
            <label for="purchase-reset-confirm-db" class="block text-xs font-medium text-red-900 mb-1">
                Type <span class="font-mono">{{ $recomputeTargetDatabase }}</span> to unlock
            </label>
            <input type="text" name="confirm_database" id="purchase-reset-confirm-db" autocomplete="off"
                   data-expected="{{ $recomputeTargetDatabase }}"
                   class="rounded-lg border-red-300 text-sm font-mono focus:border-red-500 focus:ring-red-500">
        </div>
        <button type="submit" id="purchase-reset-submit" disabled
                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
            Reset purchase data
        </button>
    </form>
</div>

<script>
// Same typed-database lock as the recompute button, for the same reason: this
// one destroys the orders too, and staging carries real distributor data.
(function () {
    var input = document.getElementById('purchase-reset-confirm-db');
    var button = document.getElementById('purchase-reset-submit');
    if (!input || !button) { return; }

    input.value = '';
    button.disabled = true;

    input.addEventListener('input', function () {
        button.disabled = input.value.trim() !== input.dataset.expected;
    });
})();
</script>
@endif

{{-- The recompute. Rendered only when RecomputeGuard permits it AND the reader
     holds the developer or admin role, so on any environment where it is off —
     or for a scoped admin role — there is no trace of it here. On the
     environments where it IS rendered it is not a "testing tool" beside the
     real controls: it is the only way compensation is computed there. --}}
@if($destructiveToolsVisible)
@php $recomputeTotal = array_sum($recomputeRowCounts); @endphp
<div class="mb-6 rounded-xl border-2 border-red-300 bg-red-50 p-4">
    <p class="text-sm font-bold text-red-900">Recompute — rebuild every bonus from the orders</p>
    <p class="mt-1 text-xs text-red-800 max-w-4xl">
        Deletes <strong>every</strong> bonus result, frozen pool, carry-forward, rank qualification, repurchase cycle,
        wallet credit and payout batch
        (<strong>{{ \App\Modules\Shared\Support\IndianNumber::format($recomputeTotal) }}</strong> rows on
        <code class="font-mono bg-red-100 px-1 rounded">{{ $recomputeTargetDatabase }}</code>)
        and replays every engine from the first BV date, each at the instant the scheduler would have fired it.
        Orders, the BV ledger, distributors, the Genos and the plan settings are kept.
    </p>
    <p class="mt-1 text-xs text-red-700 max-w-4xl">
        Wallet credits are deleted outright, not reversed, so any figure a distributor has already seen will change.
        Needs a queue worker that will let a job run for minutes &mdash;
        <code class="font-mono bg-red-100 px-1 rounded">queue:work</code>.
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
                    <td class="py-0.5 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
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

    {{-- The impact line is rewritten by the script below whenever the window
         changes: a modal that says "every bonus" while the form is set to
         rebuild one month is worse than no modal at all. --}}
    <form method="POST" action="{{ route('admin.compensation.engine-runs.recompute-all') }}"
          id="recompute-form"
          class="mt-4 flex flex-wrap items-end gap-3"
          data-confirm="Wipe every bonus, payout and wallet credit, then replay all engines?"
          data-confirm-title="Destroy and rebuild all compensation data?"
          data-confirm-impact="This cannot be undone. {{ \App\Modules\Shared\Support\IndianNumber::format($recomputeTotal) }} rows on {{ $recomputeTargetDatabase }} are deleted and rebuilt from the surviving orders. The replay runs in the background and takes several minutes.">
        @csrf

        <fieldset class="w-full rounded-lg border border-red-200 bg-white p-3">
            <legend class="px-1 text-xs font-semibold uppercase tracking-wider text-gray-600">How far to replay</legend>
            <div class="space-y-2">
                @foreach($recomputeHorizons as $horizon)
                <label class="flex items-start gap-2 text-xs text-gray-700">
                    <input type="radio" name="horizon" value="{{ $horizon['value'] }}"
                           @checked(old('horizon', 'now') === $horizon['value'])
                           class="mt-0.5 border-gray-300 text-red-600 focus:ring-red-500">
                    <span>
                        <span class="font-semibold text-gray-900">{{ $horizon['label'] }}</span>
                        @if($horizon['projected'])
                        <span class="ml-1 inline-flex rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
                            simulated
                        </span>
                        @endif
                        <span class="block text-gray-600">{{ $horizon['description'] }}</span>
                    </span>
                </label>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-gray-500">
                A simulated run marks this environment as holding projected figures: every page carries a banner
                saying so, the scheduled engines pause (they would otherwise run against a carry-forward store that
                has already moved past them), and the nightly reset at 23:30 IST puts it back to
                <em>up to now</em>. Rebuilding only part of the history, or only some engines, is a debugging
                shortcut and lives on the command line:
                <code class="font-mono bg-gray-100 px-1 rounded">php artisan compensation:recompute-all --help</code>.
            </p>
        </fieldset>

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
            Run recompute
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
    <x-ui.card padding="p-5">
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

                <p class="text-xs text-gray-600 mb-2">Schedule: {{ $definition->scheduleText() }}</p>

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
                        · took {{ $lastRun->durationForHumans() }}
                    @elseif($engine['derivedPeriod'] !== null)
                        latest results found for {{ $definition->displayPeriod($engine['derivedPeriod']) }}
                        <span class="text-gray-600">(derived from result tables — no run log yet)</span>
                    @else
                        <span class="text-gray-600">never recorded</span>
                    @endif
                </p>

                {{-- Dependencies --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-600">Runs first:</span>
                    @forelse($engine['dependencyLabels'] as $label)
                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-medium bg-indigo-50 text-indigo-700 border border-indigo-100">{{ $label }}</span>
                    @empty
                    <span class="text-[10px] text-gray-600">none — runs on its own</span>
                    @endforelse
                </div>

                <div class="mt-2 flex items-center gap-3 text-xs">
                    @if($definition->reportRouteName !== null)
                    <a href="{{ route($definition->reportRouteName) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">Report page {{ svg('lucide-chevron-right', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }}</a>
                    @endif
                    <a href="{{ route('admin.compensation.engine-runs.events', ['engine' => $definition->key]) }}"
                       class="text-indigo-600 hover:text-indigo-800 font-medium">Run events {{ svg('lucide-chevron-right', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }}</a>
                </div>
            </div>

            {{-- Run form --}}
            @if($manualTriggersDisabled)
            <div class="w-full lg:w-80 shrink-0 rounded-lg border border-gray-100 bg-gray-50 p-3 text-xs text-gray-600">
                <span class="font-semibold text-gray-700">Run by recompute.</span>
                On this environment every engine is fired by the recompute at the top of the page, at the instant the
                scheduler would have fired it. Its last run and its results are still shown here.
            </div>
            @elseif(! $definition->manuallyTriggerable)
            <div class="w-full lg:w-80 shrink-0 rounded-lg border border-gray-100 bg-gray-50 p-3 text-xs text-gray-600">
                <span class="font-semibold text-gray-700">Scheduler-only.</span>
                @if($definition->isOrchestrator)
                This runs the other engines rather than computing anything itself — trigger the individual
                engine you need instead, or re-run the close from the command line, which resumes at the
                first step that has not succeeded.
                @else
                Payout batches are created by the scheduler and approved separately on the Payouts page,
                so the same person never both creates and approves a batch.
                @endif
            </div>
            @else
            <form method="POST" action="{{ route('admin.compensation.engine-runs.trigger') }}"
                  class="w-full lg:w-80 shrink-0 rounded-lg border border-gray-100 bg-gray-50 p-3"
                  data-engine-trigger
                  data-engine-label="{{ $definition->label }}"
                  data-confirm="This queues {{ $definition->label }} for the chosen period{{ count($engine['dependencyLabels']) > 0 ? ', after first running any missing prerequisite periods of: '.implode(', ', $engine['dependencyLabels']) : '' }}."
                  data-confirm-suffix="{{ count($engine['dependencyLabels']) > 0 ? ', after first running any missing prerequisite periods of: '.implode(', ', $engine['dependencyLabels']) : '' }}."
                  data-confirm-title="Confirm: Run {{ $definition->label }}"
                  data-confirm-impact="Wallet credits and result rows are written exactly as a scheduled run would write them. Idempotent — periods already computed are skipped, and nobody is credited twice.">
                @csrf
                <input type="hidden" name="engine" value="{{ $definition->key }}">
                <div class="mb-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">
                        {{ $isMonth ? 'Month' : 'Date' }}
                        <x-help-tip :text="$definition->requiresClosedPeriod
                            ? ($isMonth ? 'The month this engine should process. Only a month that has already ended can be chosen — this engine freezes the month\'s pool economics permanently, so running it mid-month would price the month on partial sales.' : 'The day this engine should process. Only a day that has already ended can be chosen — this engine freezes the day\'s pool economics permanently, so running it before the day closes would price the day on partial sales.')
                            : ($isMonth ? 'The month this engine should process. Pre-filled with the month the scheduler would use.' : 'The day this engine should process. Pre-filled with the day the scheduler would use.')" />
                    </label>
                    <input type="{{ $isMonth ? 'month' : 'date' }}" name="period" value="{{ old('engine') === $definition->key ? old('period') : $engine['defaultPeriodValue'] }}" @if($engine['maxPeriodValue'] !== '') max="{{ $engine['maxPeriodValue'] }}" @endif required
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">
                </div>
                <div class="mb-3">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Reason (required, min 10 chars)
                        <x-help-tip text="Why this engine is being run manually. Recorded in the audit log." />
                    </label>
                    <textarea name="reason" rows="2" required placeholder="e.g. Scheduled run on the 1st failed — re-running after fix"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-brand-400 focus:outline-none">{{ old('engine') === $definition->key ? old('reason') : '' }}</textarea>
                </div>
                <x-ui.button class="w-full">
                    Preview &amp; Confirm &rarr;
                </x-ui.button>
            </form>
            @endif
        </div>
    </x-ui.card>
    @endforeach
</div>

<script>
// The period is chosen in the form, so the confirmation modal has to read it
// back: "for the chosen period" let an operator confirm a month they had not
// noticed the picker was still on (F83). Re-read on every change rather than
// rendered once, because the picker is what is being confirmed.
(function () {
    document.querySelectorAll('form[data-engine-trigger]').forEach(function (form) {
        var period = form.querySelector('input[name="period"]');
        if (!period) { return; }

        function sync() {
            var value = period.value;

            if (value === '') {
                form.dataset.confirmTitle = 'Confirm: Run ' + form.dataset.engineLabel;
                form.dataset.confirm = 'This queues ' + form.dataset.engineLabel
                    + ' for the chosen period' + form.dataset.confirmSuffix;

                return;
            }

            form.dataset.confirmTitle = 'Confirm: Run ' + form.dataset.engineLabel + ' for ' + value;
            form.dataset.confirm = 'This queues ' + form.dataset.engineLabel + ' for ' + value
                + form.dataset.confirmSuffix;
        }

        period.addEventListener('change', sync);
        period.addEventListener('input', sync);
        sync();
    });
})();
</script>

@endsection
