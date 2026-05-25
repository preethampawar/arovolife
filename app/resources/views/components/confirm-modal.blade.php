{{-- Reusable confirmation modal. Include ONCE per page (e.g. at the bottom of
     a view). Mark any form needing confirmation with:
       <form ... data-confirm="Proceed?" data-confirm-title="..." data-confirm-impact="...">
     The script blocks the native submit, shows this modal, and submits only
     after the user confirms. --}}
<div id="confirm-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <h2 id="confirm-modal-title" class="text-base font-semibold text-gray-900 mb-2">Please confirm</h2>
        <p id="confirm-modal-message" class="text-sm text-gray-700 mb-2"></p>
        <p id="confirm-modal-impact" class="text-xs text-gray-500 mb-5 leading-relaxed"></p>
        <div class="flex justify-end gap-3">
            <button type="button" id="confirm-modal-cancel"
                class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </button>
            <button type="button" id="confirm-modal-ok"
                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
                Confirm
            </button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('confirm-modal');
    if (!modal) return;
    var titleEl = document.getElementById('confirm-modal-title');
    var msgEl = document.getElementById('confirm-modal-message');
    var impactEl = document.getElementById('confirm-modal-impact');
    var okBtn = document.getElementById('confirm-modal-ok');
    var cancelBtn = document.getElementById('confirm-modal-cancel');
    var pendingForm = null;

    function close() { modal.classList.add('hidden'); modal.classList.remove('flex'); pendingForm = null; }
    function open(form) {
        titleEl.textContent = form.getAttribute('data-confirm-title') || 'Please confirm';
        msgEl.textContent = form.getAttribute('data-confirm') || 'Are you sure?';
        impactEl.textContent = form.getAttribute('data-confirm-impact') || '';
        pendingForm = form;
        modal.classList.remove('hidden'); modal.classList.add('flex');
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmed === 'true') return;
            e.preventDefault();
            open(form);
        });
    });

    okBtn.addEventListener('click', function () {
        if (pendingForm) { pendingForm.dataset.confirmed = 'true'; pendingForm.submit(); }
        close();
    });
    cancelBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
})();
</script>
