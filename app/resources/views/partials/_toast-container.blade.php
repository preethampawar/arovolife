{{-- Global toast container.
     Mount once per page; any JS can call `window.showToast(message, type)`
     to push a toast. Types: 'success' (leaf), 'error' (red), 'info' (brand).
     Toasts auto-dismiss after ~3.5 s with a slide-out animation.

     Lives top-right on >= sm so it doesn't cover mobile thumb-reach;
     on mobile (<sm) it slides up from the bottom for the same reason
     (most touches happen at the bottom of the screen).  --}}
<div id="toastContainer"
     class="fixed z-[80] flex flex-col gap-2 pointer-events-none
            top-4 right-4 max-w-sm
            sm:items-end"
     aria-live="polite"
     aria-atomic="true">
</div>

<template id="toastTemplate">
    <div class="toast pointer-events-auto rounded-lg shadow-lg ring-1 px-4 py-3 text-sm flex items-start gap-3
                transform translate-x-2 opacity-0 transition-all duration-200 ease-out"
         role="status">
        <span class="shrink-0 mt-0.5" data-toast-icon></span>
        <p class="flex-1 leading-snug" data-toast-message></p>
        <button type="button"
                class="shrink-0 -my-1 -mr-1 px-1 text-current opacity-60 hover:opacity-100 transition-opacity"
                aria-label="Dismiss"
                data-toast-dismiss>
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
</template>

<script>
(() => {
    const container = document.getElementById('toastContainer');
    const template  = document.getElementById('toastTemplate');
    if (!container || !template) return;

    const THEMES = {
        success: {
            classes: 'bg-leaf-50 text-leaf-800 ring-leaf-200',
            iconSvg: '<svg class="w-5 h-5 text-leaf-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>',
        },
        error: {
            classes: 'bg-red-50 text-red-800 ring-red-200',
            iconSvg: '<svg class="w-5 h-5 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>',
        },
        info: {
            classes: 'bg-brand-50 text-brand-800 ring-brand-200',
            iconSvg: '<svg class="w-5 h-5 text-brand-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>',
        },
    };

    /**
     * Push a toast.
     * @param {string} message
     * @param {'success'|'error'|'info'} [type='info']
     * @param {number} [ttlMs=3500]
     */
    window.showToast = (message, type = 'info', ttlMs = 3500) => {
        const theme = THEMES[type] ?? THEMES.info;
        const node = template.content.firstElementChild.cloneNode(true);
        node.classList.add(...theme.classes.split(' '));
        node.querySelector('[data-toast-icon]').innerHTML = theme.iconSvg;
        node.querySelector('[data-toast-message]').textContent = message;

        const dismiss = () => {
            node.classList.add('opacity-0', 'translate-x-2');
            setTimeout(() => node.remove(), 200);
        };
        node.querySelector('[data-toast-dismiss]').addEventListener('click', dismiss);

        container.appendChild(node);
        // Trigger the slide-in transition on the next frame.
        requestAnimationFrame(() => {
            node.classList.remove('opacity-0', 'translate-x-2');
        });

        if (ttlMs > 0) setTimeout(dismiss, ttlMs);
    };
})();
</script>
