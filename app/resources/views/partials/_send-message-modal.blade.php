{{-- Send-Message modal — opens from the tree-card menu's "Send Message"
     item. AJAX submit; the user stays on the tree page. On success the
     modal closes and a toast confirms. On error the message stays
     visible inside the modal so the user can edit and retry. --}}
<div id="sendMessageModal"
     class="fixed inset-0 z-[70] hidden"
     role="dialog"
     aria-modal="true"
     aria-labelledby="sendMessageModalTitle">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" data-modal-backdrop></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl pointer-events-auto">
            <div class="px-6 pt-5 pb-3 border-b border-gray-100">
                <h2 id="sendMessageModalTitle" class="text-xs uppercase tracking-wider font-semibold text-gray-500">Send Message</h2>
                <p class="text-base font-semibold text-gray-900 mt-1 truncate" data-recipient-name>—</p>
                <button type="button" data-modal-close
                    class="absolute top-3 right-3 w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                    aria-label="Close">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <form id="sendMessageForm" class="px-6 py-5">
                <label for="sendMessageBody" class="block text-xs font-semibold text-gray-700 mb-1.5">Message</label>
                <textarea id="sendMessageBody"
                    name="body"
                    rows="5"
                    required
                    maxlength="4000"
                    placeholder="Write your message…"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm resize-y focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>

                <div class="mt-1 flex items-center justify-between text-[10px] text-gray-400">
                    <span>Cmd/Ctrl + Enter to send</span>
                    <span data-char-count>0 / 4000</span>
                </div>

                <div data-modal-error class="mt-3 hidden rounded-md bg-red-50 border border-red-200 p-2 text-xs text-red-700"></div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" data-modal-close
                        class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                        data-submit-btn
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 disabled:bg-gray-300 text-white text-sm font-semibold transition-colors">
                        <span data-submit-label>Send</span>
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const modal     = document.getElementById('sendMessageModal');
    if (!modal) return;
    const form      = modal.querySelector('#sendMessageForm');
    const textarea  = modal.querySelector('#sendMessageBody');
    const nameSlot  = modal.querySelector('[data-recipient-name]');
    const errorSlot = modal.querySelector('[data-modal-error]');
    const submitBtn = modal.querySelector('[data-submit-btn]');
    const submitLbl = modal.querySelector('[data-submit-label]');
    const charCount = modal.querySelector('[data-char-count]');
    const closeBtns = modal.querySelectorAll('[data-modal-close]');
    const backdrop  = modal.querySelector('[data-modal-backdrop]');

    let recipientId   = null;
    let recipientName = '';

    const reset = () => {
        textarea.value = '';
        errorSlot.classList.add('hidden');
        errorSlot.textContent = '';
        submitBtn.disabled = false;
        submitLbl.textContent = 'Send';
        charCount.textContent = '0 / 4000';
    };

    const open = (userId, displayName) => {
        recipientId = userId;
        recipientName = displayName || ('distributor #'+userId);
        nameSlot.textContent = recipientName;
        reset();
        modal.classList.remove('hidden');
        setTimeout(() => textarea.focus(), 50);
    };

    const close = () => {
        modal.classList.add('hidden');
        recipientId = null;
        recipientName = '';
    };

    textarea.addEventListener('input', () => {
        charCount.textContent = textarea.value.length + ' / 4000';
    });

    textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-send-message]');
        if (!trigger) return;
        e.preventDefault();
        e.stopPropagation();
        // Close any open node menu first so the dropdown doesn't
        // visually overlap the modal.
        document.querySelectorAll('[data-node-menu-panel]').forEach((p) => { p.hidden = true; });
        open(trigger.dataset.sendMessage, trigger.dataset.sendMessageName || '');
    });

    backdrop.addEventListener('click', close);
    closeBtns.forEach((b) => b.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) close();
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!recipientId) return;

        const body = textarea.value.trim();
        if (body === '') {
            errorSlot.textContent = 'Please type a message.';
            errorSlot.classList.remove('hidden');
            return;
        }

        submitBtn.disabled = true;
        submitLbl.textContent = 'Sending…';
        errorSlot.classList.add('hidden');

        try {
            const fd = new FormData();
            fd.append('body', body);
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

            const resp = await fetch(`/messages/${encodeURIComponent(recipientId)}`, {
                method: 'POST',
                body: fd,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (resp.ok) {
                const sentTo = recipientName;
                close();
                if (typeof window.showToast === 'function') {
                    window.showToast(`Message sent to ${sentTo}.`, 'success');
                }
                return;
            }

            if (resp.status === 422) {
                const data = await resp.json().catch(() => ({}));
                const msg = data?.errors?.body?.[0] || data?.message || 'Validation failed.';
                errorSlot.textContent = msg;
                errorSlot.classList.remove('hidden');
            } else if (resp.status === 401 || resp.status === 419) {
                errorSlot.textContent = 'Your session expired. Please refresh the page.';
                errorSlot.classList.remove('hidden');
            } else {
                errorSlot.textContent = `Could not send the message (HTTP ${resp.status}).`;
                errorSlot.classList.remove('hidden');
            }
        } catch (err) {
            errorSlot.textContent = 'Network error — could not reach the server.';
            errorSlot.classList.remove('hidden');
        } finally {
            submitBtn.disabled = false;
            submitLbl.textContent = 'Send';
        }
    });
})();
</script>
