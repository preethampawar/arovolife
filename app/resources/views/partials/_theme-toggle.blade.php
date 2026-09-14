{{-- Size, radius and colour all live in the default `class` so a caller can
     replace them outright — merging would leave two h-* or two rounded-*
     utilities fighting over source order. --}}
@props(['class' => 'h-9 w-9 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900'])

{{-- Light/dark toggle. Purely a display preference stored per browser in
     localStorage; there is no server state and therefore no route or gate —
     the control inherits whatever middleware its page already has.

     Both icons are rendered and CSS shows exactly one (app.css,
     [data-theme-icon]), so the right icon is correct from the pre-paint
     script on, with no flash and no JS class juggling.

     Self-contained on purpose: the script is inline and guards against
     running twice, so any of the app's twenty-odd standalone documents can
     drop this in wherever its header happens to be, without also having to
     remember a script tag at the bottom. --}}
<button type="button"
        data-theme-toggle
        aria-pressed="false"
        title="Toggle dark mode"
        {{ $attributes->class(['inline-flex shrink-0 items-center justify-center transition-colors', $class]) }}>
    <span class="sr-only">Toggle dark mode</span>
    <span data-theme-icon="light" aria-hidden="true">{{ svg('lucide-moon', 'w-[18px] h-[18px]') }}</span>
    <span data-theme-icon="dark" aria-hidden="true">{{ svg('lucide-sun', 'w-[18px] h-[18px]') }}</span>
</button>

@once
<script>
    (() => {
        const root = document.documentElement;
        const sync = () => document.querySelectorAll('[data-theme-toggle]').forEach(
            (b) => b.setAttribute('aria-pressed', root.classList.contains('dark') ? 'true' : 'false'),
        );

        // Delegated, so a toggle rendered later in the document — or more than
        // one on the same page — needs no extra wiring.
        document.addEventListener('click', (e) => {
            if (! e.target.closest('[data-theme-toggle]')) return;
            const dark = root.classList.toggle('dark');
            try { localStorage.setItem('arovolife_theme', dark ? 'dark' : 'light'); } catch (err) { /* ignore */ }
            sync();
        });

        sync();
    })();
</script>
@endonce
