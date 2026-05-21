{{-- A11y: Font-size FOUC preventer. Reads the saved root font-size
     percentage from localStorage and sets `documentElement.style.fontSize`
     BEFORE first paint, so a user who picked 130% in the topnav adjuster
     doesn't see the page render at 100% and snap larger after JS loads.

     Include this in the <head> of every layout / standalone page that
     also renders `partials.public-topnav` (where the adjuster lives).
     The full apply + click-wiring logic lives at the bottom of the
     topnav partial; this snippet only handles the early paint. --}}
<script>
    (() => {
        try {
            const raw = parseInt(localStorage.getItem('arovolife_root_font_size_pct') || '100', 10);
            const pct = [90, 100, 115, 130].includes(raw) ? raw : 100;
            document.documentElement.style.fontSize = pct + '%';
        } catch (e) { /* private-browsing / SSR — fall back to 100% */ }
    })();
</script>
