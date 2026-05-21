{{-- Globally-mounted modal shell for the tree-view "Details" menu.

     The modal is rendered once at the bottom of the tree view (via
     tree/_content.blade.php). Each card's "Details" menu item carries
     a `data-distributor-id` attribute; the JS below fetches the
     /distributors/{id}/id-card-panel HTML and injects it into the
     modal body.

     Closes on: backdrop click, Escape, X button. No persistent state. --}}
<div id="distributorDetailsModal"
    class="fixed inset-0 z-[60] hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="distributorDetailsModalTitle">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" data-modal-backdrop></div>
    <div class="absolute inset-0 flex items-start justify-center p-4 sm:p-8 overflow-y-auto pointer-events-none">
        <div class="relative w-full max-w-3xl bg-white rounded-2xl shadow-2xl pointer-events-auto">
            <div class="px-6 pt-5 pb-3 border-b border-gray-100 flex items-center justify-between">
                <h2 id="distributorDetailsModalTitle" class="text-sm uppercase tracking-wider font-semibold text-gray-500">
                    Distributor Details
                </h2>
                <button type="button" data-modal-close
                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                    aria-label="Close">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="px-6 py-5" data-modal-body>
                {{-- Initial state: skeleton. JS swaps this for the
                     /distributors/{id}/id-card-panel HTML on open. --}}
                <div class="animate-pulse">
                    <div class="h-4 bg-gray-200 rounded w-1/3 mb-3"></div>
                    <div class="h-6 bg-gray-200 rounded w-1/2 mb-6"></div>
                    <div class="space-y-2">
                        @for($i = 0; $i < 6; $i++)
                            <div class="h-3 bg-gray-100 rounded w-full"></div>
                        @endfor
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const modal     = document.getElementById('distributorDetailsModal');
    if (! modal) return;
    const body      = modal.querySelector('[data-modal-body]');
    const backdrop  = modal.querySelector('[data-modal-backdrop]');
    const closeBtns = modal.querySelectorAll('[data-modal-close]');

    const SKELETON_HTML = body.innerHTML;

    const open = async (distributorId) => {
        modal.classList.remove('hidden');
        body.innerHTML = SKELETON_HTML;
        try {
            const resp = await fetch(`/distributors/${encodeURIComponent(distributorId)}/id-card-panel`, {
                headers: { 'Accept': 'text/html' },
                credentials: 'same-origin',
            });
            if (resp.status === 403) {
                body.innerHTML = '<p class="text-sm text-amber-700">You do not have access to view this distributor\'s details.</p>';
                return;
            }
            if (! resp.ok) {
                body.innerHTML = '<p class="text-sm text-red-700">Could not load distributor details (HTTP ' + resp.status + ').</p>';
                return;
            }
            body.innerHTML = await resp.text();
        } catch (e) {
            body.innerHTML = '<p class="text-sm text-red-700">Could not load distributor details (network error).</p>';
        }
    };

    const close = () => {
        modal.classList.add('hidden');
        // Defer the skeleton swap so the close transition feels clean.
        setTimeout(() => { body.innerHTML = SKELETON_HTML; }, 200);
    };

    // Open: any element with data-open-distributor-details="{id}" triggers it.
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-open-distributor-details]');
        if (! trigger) return;
        e.preventDefault();
        e.stopPropagation();
        // Close any open node menu before opening the modal so the
        // dropdown doesn't visually overlap the dialog.
        document.querySelectorAll('[data-node-menu-panel]').forEach((p) => { p.hidden = true; });
        const id = trigger.dataset.openDistributorDetails;
        if (id) open(id);
    });

    backdrop.addEventListener('click', close);
    closeBtns.forEach((btn) => btn.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && ! modal.classList.contains('hidden')) close();
    });
})();
</script>
