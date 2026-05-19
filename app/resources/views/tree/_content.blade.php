{{-- Reusable tree-canvas content. Used by both the distributor's
     /tree (binary.blade.php) and the admin's /admin/tree/{id} view.

     Required vars:
       $self                — root distributor for the rendered tree
       $childByParentSide   — id => ['L' => Distributor, 'R' => Distributor]
       $maxDepth            — int (depth filter currently applied)
       $totalDescendants    — int
       $maxObservedDepth    — int

     Optional vars (with defaults):
       $contextTitle        — page heading
       $contextSubtitlePre  — text before the highlighted span
       $contextSubtitleHi   — highlighted middle span (brand-blue)
       $contextSubtitlePost — text after the highlighted span
       $showSponsorshipLink — bool, show "direct referrals on a separate page" link
       $adminContext        — bool, applies the admin styling tweaks --}}
@php
    $contextTitle        = $contextTitle        ?? 'My binary tree';
    $contextSubtitlePre  = $contextSubtitlePre  ?? 'Showing your placement and descendants up to ';
    $contextSubtitleHi   = $contextSubtitleHi   ?? null;
    $contextSubtitlePost = $contextSubtitlePost ?? '';
    $showSponsorshipLink = $showSponsorshipLink ?? true;
    $adminContext        = $adminContext        ?? false;
    $expandAllUrl        = request()->fullUrlWithQuery(['levels' => max(1, $maxObservedDepth)]);
    $collapseAllUrl      = request()->fullUrlWithQuery(['levels' => 1]);
@endphp

<div class="mb-4 flex flex-wrap items-end justify-between gap-3 sm:gap-4">
    <div class="min-w-0">
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 mb-1">{{ $contextTitle }}</h1>
        <p class="text-xs sm:text-sm text-gray-600">
            {{ $contextSubtitlePre }}{{ $maxDepth }} {{ $maxDepth === 1 ? 'level' : 'levels' }} deep.
            @if($showSponsorshipLink)
                Direct referrals (sponsorship) are on a
                <a href="{{ route('tree.sponsorship') }}" class="text-brand-600 underline underline-offset-2">separate page</a>.
            @endif
        </p>
    </div>

    <form method="GET" action="{{ request()->url() }}" class="flex items-end gap-2">
        @foreach(request()->query() as $k => $v)
            @if($k !== 'levels' && is_string($v))
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endif
        @endforeach
        <div>
            <label for="levels" class="block text-[11px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Depth</label>
            <input id="levels" name="levels" type="number" min="1" step="1"
                value="{{ $maxDepth }}"
                class="w-16 sm:w-20 rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-mono text-center focus:outline-none focus:ring-2 focus:ring-brand-500">
        </div>
        <button type="submit" class="px-3 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">Apply</button>
    </form>
</div>

<div class="rounded-xl border border-gray-200 bg-white p-2 sm:p-3 mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 sm:gap-x-5">
    @foreach([
        'pending'    => ['dot' => 'bg-yellow-400',  'label' => 'New Member'],
        'active'     => ['dot' => 'bg-leaf-500',    'label' => 'Active'],
        'terminated' => ['dot' => 'bg-red-500',     'label' => 'Inactive'],
        'frozen'     => ['dot' => 'bg-sunrise-500', 'label' => 'Suspended'],
    ] as $key => $cfg)
        <span class="inline-flex items-center gap-1.5 sm:gap-2 text-[11px] sm:text-xs text-gray-700">
            <span class="w-3 h-3 rounded {{ $cfg['dot'] }} ring-1 ring-black/5"></span>
            {{ $cfg['label'] }}
        </span>
    @endforeach

    <span class="sm:ml-auto inline-flex items-center gap-2 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-full bg-brand-50 text-brand-700 text-[11px] sm:text-xs font-semibold border border-brand-100">
        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
        </svg>
        {{ $totalDescendants }} {{ $totalDescendants === 1 ? 'Member' : 'Members' }}
    </span>
</div>

@php
    if ($maxObservedDepth === 0) {
        $stateLabel = 'No downline';
        $stateClass = 'bg-gray-100 text-gray-600 border-gray-200';
    } elseif ($maxDepth >= $maxObservedDepth) {
        $stateLabel = 'Expanded';
        $stateClass = 'bg-leaf-50 text-leaf-700 border-leaf-200';
    } elseif ($maxDepth <= 1) {
        $stateLabel = 'Collapsed';
        $stateClass = 'bg-sunrise-50 text-sunrise-700 border-sunrise-200';
    } else {
        $stateLabel = 'Partial — depth '.$maxDepth.' of '.$maxObservedDepth;
        $stateClass = 'bg-brand-50 text-brand-700 border-brand-200';
    }
@endphp

<div class="flex flex-wrap items-center gap-2 mb-3 text-xs">
    <a href="{{ $expandAllUrl }}"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border {{ $maxDepth >= max(1, $maxObservedDepth) ? 'border-leaf-300 bg-leaf-50 text-leaf-700' : 'border-gray-300 bg-white hover:bg-gray-50 text-gray-700' }} transition-colors">
        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25 12 15.75 4.5 8.25"/>
        </svg>
        Expand All
    </a>
    <a href="{{ $collapseAllUrl }}"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border {{ $maxDepth <= 1 ? 'border-sunrise-300 bg-sunrise-50 text-sunrise-700' : 'border-gray-300 bg-white hover:bg-gray-50 text-gray-700' }} transition-colors">
        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5"/>
        </svg>
        Collapse All
    </a>

    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border {{ $stateClass }} text-[11px] font-semibold">
        <span class="w-1.5 h-1.5 rounded-full bg-current opacity-75"></span>
        {{ $stateLabel }}
    </span>

    <span class="mx-1 h-5 w-px bg-gray-200 hidden md:inline-block"></span>

    <span class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white p-0.5">
        <button type="button" onclick="treeZoom(-0.1)" class="w-7 h-7 rounded-md hover:bg-gray-50 text-gray-700 font-semibold inline-flex items-center justify-center transition-colors" title="Zoom out">−</button>
        <span id="treeZoomLabel" class="text-gray-700 font-mono w-11 text-center text-[11px]">100%</span>
        <button type="button" onclick="treeZoom(+0.1)" class="w-7 h-7 rounded-md hover:bg-gray-50 text-gray-700 font-semibold inline-flex items-center justify-center transition-colors" title="Zoom in">+</button>
        <button type="button" onclick="treeFit()" class="px-2 h-7 rounded-md hover:bg-gray-50 text-gray-700 text-[11px] font-semibold transition-colors" title="Fit to view">Fit</button>
    </span>

    <span class="mx-1 h-5 w-px bg-gray-200 hidden md:inline-block"></span>

    <button type="button" id="treeMinimapBtn" onclick="toggleMinimap()" class="hidden md:inline-flex items-center gap-1.5 px-3 h-8 rounded-lg border border-leaf-200 bg-leaf-50 hover:bg-leaf-100 text-leaf-700 font-semibold transition-colors">
        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m0-8.25-4.5 1.5v8.25l4.5-1.5M9 6.75l6 1.5m0 0v8.25m0-8.25 4.5-1.5v8.25l-4.5 1.5m0 0L9 15"/></svg>
        <span id="treeMinimapLabel">Minimap</span>
    </button>
    <button type="button" id="treeFullscreenBtn" onclick="toggleFullscreen()" class="hidden md:inline-flex items-center gap-1.5 px-3 h-8 rounded-lg bg-leaf-500 hover:bg-leaf-600 text-white font-semibold transition-colors">
        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15m11.25 5.25v-4.5m0 4.5h-4.5m4.5 0L15 15m5.25-11.25h-4.5m4.5 0v4.5m0-4.5L15 9"/></svg>
        <span id="treeFullscreenLabel">Full Screen</span>
    </button>

    <span class="hidden md:inline ml-auto text-gray-400 text-[11px]">Drag to pan · Cmd/Ctrl + scroll to zoom</span>
</div>

<div id="treeFrame" class="relative">
    <div id="treeViewport"
        class="relative rounded-2xl border border-gray-200 bg-white overflow-auto h-[60vh] sm:h-[72vh] min-h-[400px] sm:min-h-[480px] cursor-grab touch-pan-x touch-pan-y"
        style="background-image: radial-gradient(circle at 1px 1px, rgba(15,23,42,0.06) 1px, transparent 0); background-size: 18px 18px;">
        <div id="treeStage" class="inline-block min-w-full min-h-full">
            <div id="treeCanvas" class="inline-block min-w-max p-8 origin-top-left">
                @include('tree._binary-node', [
                    'node'              => $self,
                    'level'             => 0,
                    'maxDepth'          => $maxDepth,
                    'childByParentSide' => $childByParentSide,
                    'adminContext'      => $adminContext,
                ])
            </div>
        </div>
    </div>

    {{-- Minimap: click-to-jump anywhere, or drag the blue rectangle to pan.
         Bumped to 280x200 for an easier hit target on the indicator (which
         can get very thin/short on wide-aspect trees), with a min-size on
         the indicator itself so the user always has at least 24x24px of
         drag handle even when zoomed all the way out. --}}
    <aside id="treeMinimap" class="hidden absolute bottom-3 right-3 z-30 rounded-lg border border-gray-300 bg-white shadow-lg overflow-hidden cursor-crosshair select-none" style="width: 280px; height: 200px;">
        <div class="absolute inset-0 overflow-hidden">
            <div id="minimapContent" class="absolute top-0 left-0 origin-top-left pointer-events-none"></div>
            <div id="minimapViewport"
                class="absolute border-2 border-brand-500 bg-brand-500/20 rounded-sm cursor-grab hover:bg-brand-500/30 transition-colors shadow-[0_0_0_1px_rgba(255,255,255,0.6)]"
                style="left:0; top:0; width:50px; height:40px; min-width:24px; min-height:24px; touch-action:none;"
                aria-label="Drag to pan, or click anywhere on the minimap to jump"></div>
        </div>
        <div class="absolute top-0 left-0 right-0 px-2 py-1 bg-gradient-to-b from-white/95 to-transparent text-[9px] uppercase tracking-wider text-gray-500 font-semibold pointer-events-none">Minimap · drag rectangle or click to jump</div>
    </aside>

    <div id="treeFsToolbar" class="hidden absolute top-3 right-3 z-40 rounded-xl bg-white/95 backdrop-blur shadow-lg border border-gray-200 px-2 py-1.5 flex items-center gap-1.5">
        <button type="button" onclick="treeZoom(-0.1)" class="w-7 h-7 rounded-md hover:bg-gray-100 text-gray-700 font-semibold inline-flex items-center justify-center transition-colors" title="Zoom out">−</button>
        <span class="text-[11px] text-gray-700 font-mono w-10 text-center" id="treeFsZoomLabel">100%</span>
        <button type="button" onclick="treeZoom(+0.1)" class="w-7 h-7 rounded-md hover:bg-gray-100 text-gray-700 font-semibold inline-flex items-center justify-center transition-colors" title="Zoom in">+</button>
        <button type="button" onclick="treeZoomReset()" class="px-2 h-7 rounded-md hover:bg-gray-100 text-gray-700 text-[11px] transition-colors" title="Reset zoom">Reset</button>
        <span class="mx-1 h-5 w-px bg-gray-200"></span>
        <button type="button" onclick="toggleMinimap()" class="inline-flex items-center gap-1.5 px-2 h-7 rounded-md hover:bg-leaf-50 text-leaf-700 text-[11px] font-semibold transition-colors" title="Toggle minimap">
            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m0-8.25-4.5 1.5v8.25l4.5-1.5M9 6.75l6 1.5m0 0v8.25m0-8.25 4.5-1.5v8.25l-4.5 1.5m0 0L9 15"/></svg>
            Map
        </button>
        <span class="mx-1 h-5 w-px bg-gray-200"></span>
        <button type="button" onclick="toggleFullscreen()" class="inline-flex items-center gap-1.5 px-2.5 h-7 rounded-md bg-leaf-500 hover:bg-leaf-600 text-white text-[11px] font-semibold transition-colors" title="Exit full screen (Esc)">
            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25"/></svg>
            Exit
        </button>
    </div>
</div>

<div id="inviteModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="inviteHeader" onclick="if (event.target === this) closeInviteModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full">
        <div class="flex items-start justify-between p-5 border-b border-gray-100">
            <div>
                <p class="text-[10px] uppercase tracking-wider text-brand-700 font-semibold mb-0.5">Referral link</p>
                <h3 id="inviteHeader" class="text-base font-bold text-gray-900">Invite someone</h3>
            </div>
            <button type="button" onclick="closeInviteModal()" class="text-gray-400 hover:text-gray-700 transition-colors text-2xl leading-none w-8 h-8 flex items-center justify-center rounded-md hover:bg-gray-100" aria-label="Close">×</button>
        </div>
        <div class="p-5">
            <p class="text-sm text-slate-600 mb-4 leading-relaxed">
                Anyone joining via this link is sponsored by
                <span class="font-mono font-semibold text-brand-700">{{ $self->adn }}</span>
                and placed at
                <span id="invitePlacement" class="font-mono font-semibold text-brand-700"></span>.
            </p>
            <div class="flex items-stretch gap-2 mb-3">
                <input id="inviteUrl" type="text" readonly value="" class="flex-1 min-w-0 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-xs font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500" onclick="this.select()">
                <button type="button" id="inviteCopyBtn" onclick="copyInviteUrl()" class="px-4 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">Copy</button>
            </div>
            <p class="text-[11px] text-slate-500 leading-relaxed">If the slot fills before they register, the link will redirect them to Contact Us.</p>
        </div>
    </div>
</div>

<script>
(() => {
    const viewport = document.getElementById('treeViewport');
    const stage    = document.getElementById('treeStage');
    const canvas   = document.getElementById('treeCanvas');
    const label    = document.getElementById('treeZoomLabel');
    const fsLabel  = document.getElementById('treeFsZoomLabel');
    let naturalW = 0, naturalH = 0;
    const measureNatural = () => { canvas.style.transform = ''; naturalW = canvas.offsetWidth; naturalH = canvas.offsetHeight; };
    measureNatural();
    // Zoom range: 5% to 200%. The 5% floor is intentional — on very wide
    // trees (16+ leaves at depth 4) the user needs to be able to zoom out
    // enough to see the whole shape without resorting to the minimap.
    const MIN_SCALE = 0.05, MAX_SCALE = 2.0;
    let scale = 1;
    const setScale = (s) => {
        scale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, s));
        canvas.style.transformOrigin = 'top left';
        canvas.style.transform = `scale(${scale})`;
        stage.style.width  = (naturalW * scale) + 'px';
        stage.style.height = (naturalH * scale) + 'px';
        const txt = Math.round(scale * 100) + '%';
        label.textContent = txt;
        if (fsLabel) fsLabel.textContent = txt;
        if (window._minimapRefresh) window._minimapRefresh();
    };
    // Button step is adaptive: 5% increments below 30%, 10% above. Keeps
    // the - / + buttons usable when the floor was widened to 5% — without
    // adaptive stepping you'd otherwise overshoot past the readable range
    // in two clicks.
    const stepFor = (current) => current <= 0.3 ? 0.05 : 0.1;
    const fitToView = () => {
        if (naturalW <= 0) measureNatural();
        const vw = viewport.clientWidth - 4;
        const fit = vw / Math.max(naturalW, 1);
        setScale(Math.max(MIN_SCALE, Math.min(1, fit)));
        requestAnimationFrame(() => {
            const stageW = stage.offsetWidth;
            viewport.scrollLeft = Math.max(0, (stageW - viewport.clientWidth) / 2);
            viewport.scrollTop  = 0;
        });
    };
    // The buttons pass +/-0.1 historically. When the user is zoomed out
    // below 30% (post-MIN_SCALE drop), translate that into the adaptive
    // step so each click moves in 5% increments instead of overshooting.
    window.treeZoom      = (delta) => {
        const step = stepFor(scale);
        const sign = delta === 0 ? 0 : (delta > 0 ? 1 : -1);
        setScale(scale + sign * step);
    };
    window.treeZoomReset = ()      => setScale(1);
    window.treeFit       = ()      => fitToView();
    requestAnimationFrame(fitToView);
    viewport.addEventListener('wheel', (e) => { if (e.ctrlKey || e.metaKey) { e.preventDefault(); setScale(scale + (e.deltaY < 0 ? 0.05 : -0.05)); } }, { passive: false });
    let dragging = false, startX = 0, startY = 0, startLeft = 0, startTop = 0;
    viewport.addEventListener('mousedown', (e) => {
        if (e.target.closest('input, button, a, summary, select, textarea, label')) return;
        dragging = true; viewport.style.cursor = 'grabbing';
        startX = e.pageX; startY = e.pageY;
        startLeft = viewport.scrollLeft; startTop = viewport.scrollTop;
        e.preventDefault();
    });
    window.addEventListener('mouseup',   () => { dragging = false; viewport.style.cursor = 'grab'; });
    window.addEventListener('mousemove', (e) => { if (!dragging) return; viewport.scrollLeft = startLeft - (e.pageX - startX); viewport.scrollTop  = startTop  - (e.pageY - startY); });
    viewport.addEventListener('scroll', () => { if (window._minimapRefresh) window._minimapRefresh(); });
})();

(() => {
    const SELF_ADN = @json($self->adn);
    const REGISTER_BASE = @json(url('/register'));
    const modal       = document.getElementById('inviteModal');
    const headerEl    = document.getElementById('inviteHeader');
    const placementEl = document.getElementById('invitePlacement');
    const urlInput    = document.getElementById('inviteUrl');
    const copyBtn     = document.getElementById('inviteCopyBtn');
    window.openInviteModal = (btn) => {
        const parent = btn.dataset.inviteParent, side = btn.dataset.inviteSide, sideLabel = btn.dataset.inviteSideLabel;
        headerEl.textContent    = `Invite to ${parent} (${sideLabel} leg)`;
        placementEl.textContent = `${parent}.${side}`;
        urlInput.value = `${REGISTER_BASE}?sponsor=${encodeURIComponent(SELF_ADN)}&placement=${encodeURIComponent(parent)}&side=${side}`;
        modal.classList.remove('hidden'); modal.classList.add('flex');
        setTimeout(() => urlInput.focus(), 50);
    };
    window.closeInviteModal = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
    window.copyInviteUrl = () => {
        navigator.clipboard.writeText(urlInput.value).then(() => {
            const orig = copyBtn.innerText; copyBtn.innerText = 'Copied';
            setTimeout(() => copyBtn.innerText = orig, 1200);
        });
    };
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeInviteModal(); });
})();

// Per-node 3-dots menu. Opens the panel sibling, closes any other open
// panel + closes-on-outside-click. Menu items are plain <a href>'s so
// browser history records each subtree pivot — back/forward Just Works.
window.toggleNodeMenu = (btn) => {
    const wrapper = btn.closest('[data-node-menu]');
    if (!wrapper) return;
    const panel = wrapper.querySelector('[data-node-menu-panel]');
    if (!panel) return;
    const wasHidden = panel.hidden;
    // Close every other open menu first.
    document.querySelectorAll('[data-node-menu-panel]').forEach((p) => { p.hidden = true; });
    panel.hidden = ! wasHidden;
};
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-node-menu]')) return;
    document.querySelectorAll('[data-node-menu-panel]').forEach((p) => { p.hidden = true; });
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('[data-node-menu-panel]').forEach((p) => { p.hidden = true; });
    }
});

window.copyAdn = (btn) => {
    const adn = btn.dataset.copyAdn;
    if (!adn) return;
    navigator.clipboard.writeText(adn).then(() => {
        const originalHTML = btn.innerHTML, originalTitle = btn.getAttribute('title');
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>';
        btn.setAttribute('title', 'Copied');
        setTimeout(() => { btn.innerHTML = originalHTML; btn.setAttribute('title', originalTitle ?? 'Copy ADN'); }, 1000);
    });
};

(() => {
    const CLOSE_DELAY_MS = 500;
    document.querySelectorAll('[data-leaf-wrapper]').forEach((wrapper) => {
        const popover = wrapper.querySelector('[data-leaf-popover]');
        if (!popover) return;
        let timer = null;
        const open = () => { if (timer) { clearTimeout(timer); timer = null; } popover.classList.remove('hidden'); };
        const closeSoon = () => { if (timer) clearTimeout(timer); timer = setTimeout(() => { popover.classList.add('hidden'); timer = null; }, CLOSE_DELAY_MS); };
        wrapper.addEventListener('mouseenter', open);
        wrapper.addEventListener('mouseleave', closeSoon);
        popover.addEventListener('mouseenter', open);
        popover.addEventListener('mouseleave', closeSoon);
    });
})();

(() => {
    const aside    = document.getElementById('treeMinimap');
    const content  = document.getElementById('minimapContent');
    const indicator= document.getElementById('minimapViewport');
    const viewport = document.getElementById('treeViewport');
    const stage    = document.getElementById('treeStage');
    const canvas   = document.getElementById('treeCanvas');
    const label    = document.getElementById('treeMinimapLabel');
    const MAP_W = 280, MAP_H = 200;
    let cloned = false, mapScale = 0.1;

    // Pan the main viewport so a given minimap point (fx, fy in [0..1]
    // of the minimap) maps to the centre of the visible area.
    const panToFraction = (fx, fy) => {
        const sw = stage.offsetWidth, sh = stage.offsetHeight;
        if (sw === 0 || sh === 0) return;
        viewport.scrollLeft = Math.max(0, Math.min(sw - viewport.clientWidth,  fx * sw - viewport.clientWidth  / 2));
        viewport.scrollTop  = Math.max(0, Math.min(sh - viewport.clientHeight, fy * sh - viewport.clientHeight / 2));
    };

    const refresh = () => {
        if (aside.classList.contains('hidden') || !cloned) return;
        const sw = stage.offsetWidth, sh = stage.offsetHeight;
        if (sw === 0 || sh === 0) return;
        const fx = viewport.scrollLeft / sw, fy = viewport.scrollTop / sh;
        const fw = viewport.clientWidth / sw, fh = viewport.clientHeight / sh;
        indicator.style.left = (fx * MAP_W) + 'px';
        indicator.style.top  = (fy * MAP_H) + 'px';
        // The CSS min-width / min-height rules guarantee a 24x24 hit
        // target even when the natural projection is smaller (e.g. a wide
        // tree projected into a 280-wide minimap can give a 6px-wide
        // indicator — too thin to grab reliably).
        indicator.style.width  = Math.min(MAP_W, fw * MAP_W) + 'px';
        indicator.style.height = Math.min(MAP_H, fh * MAP_H) + 'px';
    };
    window._minimapRefresh = refresh;

    const cloneCanvas = () => {
        content.innerHTML = '';
        const clone = canvas.cloneNode(true);
        clone.id = 'minimapInner';
        clone.style.transform = ''; clone.style.transition = 'none'; clone.style.minHeight = '';
        clone.querySelectorAll('[data-leaf-popover]').forEach(el => el.remove());
        content.appendChild(clone);
        const cw = clone.offsetWidth, ch = clone.offsetHeight;
        mapScale = (cw === 0 || ch === 0) ? 0.1 : Math.min(MAP_W / cw, MAP_H / ch);
        content.style.transform = `scale(${mapScale})`;
        content.style.transformOrigin = 'top left';
        content.style.width = cw + 'px'; content.style.height = ch + 'px';
        cloned = true; refresh();
    };
    window.toggleMinimap = () => {
        const willOpen = aside.classList.contains('hidden');
        aside.classList.toggle('hidden');
        label.textContent = willOpen ? 'Hide Minimap' : 'Minimap';
        if (willOpen) cloneCanvas();
    };

    // ── Interactions ────────────────────────────────────────────────────
    // Two complementary patterns:
    //   1. Click anywhere on the minimap (NOT on the indicator) → centre
    //      the viewport at that point. Quick fly-to.
    //   2. Drag the indicator → continuously pan the viewport. Fine control.
    let dragging = false;
    let suppressClick = false;
    let dragGrabOffsetPx = { x: 0, y: 0 }; // where inside the indicator the user grabbed

    const onIndicatorPointerDown = (e) => {
        if (e.button !== undefined && e.button !== 0) return; // ignore middle / right
        e.preventDefault();
        e.stopPropagation();
        indicator.setPointerCapture?.(e.pointerId);
        const asideRect = aside.getBoundingClientRect();
        const indRect   = indicator.getBoundingClientRect();
        dragGrabOffsetPx.x = (e.clientX - asideRect.left) - parseFloat(indicator.style.left || '0');
        dragGrabOffsetPx.y = (e.clientY - asideRect.top)  - parseFloat(indicator.style.top  || '0');
        dragging = true;
        indicator.classList.remove('cursor-grab'); indicator.classList.add('cursor-grabbing');
        document.body.style.userSelect = 'none';
    };
    const onIndicatorPointerMove = (e) => {
        if (! dragging) return;
        e.preventDefault();
        const asideRect = aside.getBoundingClientRect();
        const indW = indicator.offsetWidth, indH = indicator.offsetHeight;
        // Where the user wants the top-left of the indicator to land:
        let left = (e.clientX - asideRect.left) - dragGrabOffsetPx.x;
        let top  = (e.clientY - asideRect.top)  - dragGrabOffsetPx.y;
        left = Math.max(0, Math.min(MAP_W - indW, left));
        top  = Math.max(0, Math.min(MAP_H - indH, top));
        // Translate top-left position back into a scroll offset on the main viewport.
        const sw = stage.offsetWidth, sh = stage.offsetHeight;
        viewport.scrollLeft = (left / MAP_W) * sw;
        viewport.scrollTop  = (top  / MAP_H) * sh;
    };
    const onIndicatorPointerUp = (e) => {
        if (! dragging) return;
        dragging = false;
        suppressClick = true;
        // Eat the synthetic click that follows the pointerup so it
        // doesn't reach the aside-level click-to-centre handler.
        setTimeout(() => { suppressClick = false; }, 0);
        indicator.classList.remove('cursor-grabbing'); indicator.classList.add('cursor-grab');
        document.body.style.userSelect = '';
        indicator.releasePointerCapture?.(e.pointerId);
    };

    indicator.addEventListener('pointerdown', onIndicatorPointerDown);
    indicator.addEventListener('pointermove', onIndicatorPointerMove);
    indicator.addEventListener('pointerup', onIndicatorPointerUp);
    indicator.addEventListener('pointercancel', onIndicatorPointerUp);

    aside.addEventListener('click', (e) => {
        if (suppressClick) return;
        // If the click landed on the indicator (and we got here without a
        // drag), still centre — feels natural and harmless.
        const rect = aside.getBoundingClientRect();
        const fx = (e.clientX - rect.left) / rect.width;
        const fy = (e.clientY - rect.top)  / rect.height;
        panToFraction(fx, fy);
    });

    window.addEventListener('resize', () => { if (!aside.classList.contains('hidden')) cloneCanvas(); });
})();

(() => {
    const frame = document.getElementById('treeFrame');
    const label = document.getElementById('treeFullscreenLabel');
    window.toggleFullscreen = () => {
        if (!document.fullscreenElement) (frame.requestFullscreen || frame.webkitRequestFullscreen).call(frame);
        else (document.exitFullscreen || document.webkitExitFullscreen).call(document);
    };
    const fsToolbar = document.getElementById('treeFsToolbar');
    document.addEventListener('fullscreenchange', () => {
        if (document.fullscreenElement === frame) {
            frame.classList.add('bg-white', 'p-4', 'is-fullscreen');
            label.textContent = 'Exit Full Screen';
            document.getElementById('treeViewport').style.height = '96vh';
            fsToolbar.classList.remove('hidden'); fsToolbar.classList.add('flex');
        } else {
            frame.classList.remove('bg-white', 'p-4', 'is-fullscreen');
            label.textContent = 'Full Screen';
            document.getElementById('treeViewport').style.height = '';
            fsToolbar.classList.add('hidden'); fsToolbar.classList.remove('flex');
        }
        if (window.treeFit) requestAnimationFrame(window.treeFit);
        if (window._minimapRefresh) window._minimapRefresh();
    });
})();

(() => {
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { if (window.treeFit) window.treeFit(); }, 150);
    });
})();
</script>
