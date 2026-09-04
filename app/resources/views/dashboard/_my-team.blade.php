{{-- ── My Team — genealogy + status summary ─────────────────────── --}}
@if($teamStats !== null)
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
    <div class="flex items-baseline justify-between mb-4 gap-3 flex-wrap">
        <div>
            <p class="text-xs text-gray-700 uppercase tracking-wider mb-1 font-semibold">My Team</p>
            <p class="text-sm text-gray-800">A live view of your Genos downline and direct referrals.</p>
        </div>
        <div class="flex items-center gap-3 text-xs">
            <a href="{{ route('tree.binary') }}" class="text-brand-700 hover:text-brand-800 underline">Genos →</a>
            <span class="text-gray-600">·</span>
            <a href="{{ route('tree.sponsorship') }}" class="text-brand-700 hover:text-brand-800 underline">Direct referrals →</a>
        </div>
    </div>

    @php
        $statuses = [
            ['key' => 'active',     'label' => 'Active',     'count' => $teamStats['active'],     'cls' => 'bg-green-50 text-green-700 border-green-200',     'dot' => 'bg-green-500'],
            ['key' => 'pending',    'label' => 'Pending',    'count' => $teamStats['pending'],    'cls' => 'bg-amber-50 text-amber-700 border-amber-200',     'dot' => 'bg-amber-500'],
            ['key' => 'frozen',     'label' => 'Blocked',    'count' => $teamStats['frozen'],     'cls' => 'bg-red-50 text-red-700 border-red-200', 'dot' => 'bg-red-500'],
            ['key' => 'terminated', 'label' => 'Terminated', 'count' => $teamStats['terminated'], 'cls' => 'bg-gray-100 text-gray-600 border-gray-200',       'dot' => 'bg-gray-400'],
        ];
        $activity = [
            ['label' => 'Registered this week',  'count' => $teamStats['joined_this_week']],
            ['label' => 'Registered this month', 'count' => $teamStats['joined_this_month']],
            ['label' => 'Cooling-off active',    'count' => $teamStats['cooling_off']],
        ];
    @endphp

    {{-- Two columns on wide screens: headline numbers on the left, the
         status + activity breakdown on the right. Stacks on mobile. --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-0 lg:divide-x lg:divide-gray-100">

        {{-- Left column: the four headline numbers. Each card is a button —
             clicking it opens a modal with the underlying roster (S.No, ADN,
             name, state, status) and a Download CSV button. JSON +
             CSV come from TeamRosterController. --}}
        <div class="lg:pr-6">
            <p class="text-[11px] text-gray-700 uppercase tracking-wider font-semibold mb-3">Team size</p>
            <div class="grid grid-cols-2 gap-3">
                <button type="button" data-team-roster="total"
                    class="text-left rounded-xl border border-brand-200 bg-brand-50/60 p-4 hover:bg-brand-50 hover:border-brand-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <p class="text-[11px] text-brand-700 uppercase tracking-wider font-semibold mb-1">Total team</p>
                    <p class="text-3xl font-bold text-brand-700 leading-none">{{ \App\Modules\Shared\Support\IndianNumber::format($teamStats['total_team']) }}</p>
                    <p class="text-[11px] text-gray-700 mt-1.5">members in your Genos downline</p>
                </button>
                <button type="button" data-team-roster="direct"
                    class="text-left rounded-xl border border-leaf-200 bg-leaf-50/60 p-4 hover:bg-leaf-50 hover:border-leaf-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-leaf-500">
                    <p class="text-[11px] text-leaf-700 uppercase tracking-wider font-semibold mb-1">Direct referrals</p>
                    <p class="text-3xl font-bold text-leaf-700 leading-none">{{ \App\Modules\Shared\Support\IndianNumber::format($teamStats['direct_referrals']) }}</p>
                    <p class="text-[11px] text-gray-700 mt-1.5">people you personally invited</p>
                </button>
                <button type="button" data-team-roster="left"
                    class="text-left rounded-xl border border-sky-200 bg-sky-50/60 p-4 hover:bg-sky-50 hover:border-sky-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500">
                    <p class="text-[11px] text-sky-700 uppercase tracking-wider font-semibold mb-1">← Left team</p>
                    <p class="text-3xl font-bold text-sky-700 leading-none">{{ \App\Modules\Shared\Support\IndianNumber::format($teamStats['left_team']) }}</p>
                    <p class="text-[11px] text-gray-700 mt-1.5">members under your left group</p>
                </button>
                <button type="button" data-team-roster="right"
                    class="text-left rounded-xl border border-indigo-200 bg-indigo-50/60 p-4 hover:bg-indigo-50 hover:border-indigo-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <p class="text-[11px] text-indigo-700 uppercase tracking-wider font-semibold mb-1">Right team →</p>
                    <p class="text-3xl font-bold text-indigo-700 leading-none">{{ \App\Modules\Shared\Support\IndianNumber::format($teamStats['right_team']) }}</p>
                    <p class="text-[11px] text-gray-700 mt-1.5">members under your right group</p>
                </button>
            </div>
        </div>

        {{-- Right column: status breakdown, then recent activity. --}}
        <div class="lg:pl-6 flex flex-col gap-5">
            <div>
                <p class="text-[11px] text-gray-700 uppercase tracking-wider font-semibold mb-3">By status</p>
                <div class="grid grid-cols-2 gap-2">
                    @foreach($statuses as $s)
                        <div class="flex items-center justify-between gap-3 rounded-lg border {{ $s['cls'] }} px-3 py-2.5">
                            <span class="inline-flex items-center gap-2 text-xs font-semibold">
                                <span class="w-2 h-2 rounded-full {{ $s['dot'] }}"></span>
                                {{ $s['label'] }}
                            </span>
                            <span class="text-lg font-bold leading-none">{{ \App\Modules\Shared\Support\IndianNumber::format($s['count']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="border-t border-gray-100 pt-4">
                <p class="text-[11px] text-gray-700 uppercase tracking-wider font-semibold mb-3">Recent activity</p>
                <div class="flex flex-col gap-2">
                    @foreach($activity as $a)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
                            <span class="text-xs text-gray-800 font-medium">{{ $a['label'] }}</span>
                            <span class="text-base font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($a['count']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Roster modal: shared by all four stat-card buttons. Populated on
     click via /dashboard/team-roster/{scope}; download button hits the
     CSV endpoint with the same scope. Uses a native <dialog> element so
     it always renders in the browser's top layer with a real ::backdrop
     — sidesteps any ancestor stacking-context / transform that would
     otherwise trap a div-based modal. --}}
<style>
    dialog#team-roster-modal::backdrop { background: rgba(15, 23, 42, 0.6); }
    dialog#team-roster-modal {
        padding: 0;
        border: 0;
        background: transparent;
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: 100%;
        margin: 0;
        inset: 0;
    }
    dialog#team-roster-modal[open] {
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>
<dialog id="team-roster-modal">
    <div class="bg-white rounded-2xl w-[calc(100vw-2rem)] sm:w-full max-w-3xl flex flex-col shadow-2xl overflow-hidden" style="max-height: calc(100vh - 4rem);">
        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200 shrink-0 bg-white">
            <div>
                <p id="team-roster-title" class="text-base font-semibold text-gray-900">Team list</p>
                <p id="team-roster-subtitle" class="text-xs text-gray-700 mt-0.5">—</p>
            </div>
            <div class="flex items-center gap-2">
                <a id="team-roster-download" href="#"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-xs font-semibold transition">
                    <x-lucide-download class="w-4 h-4" />
                    Download CSV
                </a>
                <button type="button" id="team-roster-close"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-600">
                    <x-lucide-x class="w-5 h-5" />
                </button>
            </div>
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto px-6 py-4 bg-white">
            <div id="team-roster-loading" class="hidden text-center text-sm text-gray-600 py-10">Loading…</div>
            <div id="team-roster-empty" class="hidden text-center text-sm text-gray-600 py-10">No members to show.</div>
            <table id="team-roster-table" class="hidden w-full text-sm">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th class="text-left px-3 py-2 font-semibold w-14">S.No.</th>
                        <th class="text-left px-3 py-2 font-semibold">ADN No.</th>
                        <th class="text-left px-3 py-2 font-semibold">Name</th>
                        <th class="text-left px-3 py-2 font-semibold">State</th>
                        <th class="text-left px-3 py-2 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody id="team-roster-tbody" class="divide-y divide-gray-100"></tbody>
            </table>
        </div>
    </div>
</dialog>

<script>
(function () {
    const modal      = document.getElementById('team-roster-modal');
    if (!modal) return;
    const titleEl    = document.getElementById('team-roster-title');
    const subEl      = document.getElementById('team-roster-subtitle');
    const dlEl       = document.getElementById('team-roster-download');
    const closeEl    = document.getElementById('team-roster-close');
    const loadingEl  = document.getElementById('team-roster-loading');
    const emptyEl    = document.getElementById('team-roster-empty');
    const tableEl    = document.getElementById('team-roster-table');
    const tbodyEl    = document.getElementById('team-roster-tbody');

    const STATUS_PILL = {
        'Active':   'bg-green-50 text-green-700 border-green-200',
        'Pending':  'bg-amber-50 text-amber-700 border-amber-200',
        'Blocked':  'bg-red-50 text-red-700 border-red-200',
        'Terminated': 'bg-gray-100 text-gray-600 border-gray-200',
        'Rejected': 'bg-amber-50 text-amber-700 border-amber-200',
    };

    const escapeHtml = (s) => String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    function openModal(scope) {
        loadingEl.classList.remove('hidden');
        emptyEl.classList.add('hidden');
        tableEl.classList.add('hidden');
        tbodyEl.innerHTML = '';
        titleEl.textContent = 'Loading…';
        subEl.textContent = '—';
        dlEl.setAttribute('href', `/dashboard/team-roster/${scope}/download`);
        modal.showModal();

        fetch(`/dashboard/team-roster/${scope}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => {
                loadingEl.classList.add('hidden');
                titleEl.textContent = data.label;
                subEl.textContent   = `${data.rows.length} ${data.rows.length === 1 ? 'member' : 'members'}`;
                if (data.rows.length === 0) {
                    emptyEl.classList.remove('hidden');
                    return;
                }
                tableEl.classList.remove('hidden');
                tbodyEl.innerHTML = data.rows.map((row, i) => {
                    const cls = STATUS_PILL[row.status] || 'bg-gray-100 text-gray-600 border-gray-200';
                    return `<tr>
                        <td class="px-3 py-2 text-gray-700">${i + 1}</td>
                        <td class="px-3 py-2 font-mono text-gray-900">${escapeHtml(row.adn)}</td>
                        <td class="px-3 py-2 text-gray-900">${escapeHtml(row.name)}</td>
                        <td class="px-3 py-2 text-gray-800">${escapeHtml(row.state)}</td>
                        <td class="px-3 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${cls}">${escapeHtml(row.status)}</span></td>
                    </tr>`;
                }).join('');
            })
            .catch(() => {
                loadingEl.classList.add('hidden');
                emptyEl.classList.remove('hidden');
                emptyEl.textContent = 'Could not load the list. Please try again.';
            });
    }

    document.querySelectorAll('[data-team-roster]').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.getAttribute('data-team-roster')));
    });
    closeEl.addEventListener('click', () => modal.close());
    // Click outside the inner card (i.e. directly on the dialog element)
    // dismisses; Escape key is handled natively by <dialog>.
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });
})();
</script>
@endif
