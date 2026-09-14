{{-- Theme FOUC preventer. Reads the saved theme from localStorage and stamps
     `dark` on <html> BEFORE first paint, so someone who chose dark never sees
     a light frame flash first.

     Light is the default for anyone who has never chosen: the class is added
     only for the exact value 'dark'. Anything else — absent, corrupt, or a
     private-browsing throw — leaves the page light. The OS preference is
     deliberately never consulted; `prefers-color-scheme` overriding the app's
     own setting is exactly the bug that made pagination render dark on a light
     page, and one rule is easier to reason about than two.

     Include this in the <head> of every user-facing document. Print surfaces
     (invoice, ID card) and emails leave it out — their own CSS forces white,
     and an email client has no access to this storage anyway.

     `arovolife_admin_theme` is the key this used to live under, when the
     toggle existed only in the admin console. It is read once as a fallback
     so an admin's existing choice survives, and rewritten under the new key
     by the toggle. --}}
<script>
    (() => {
        try {
            const saved = localStorage.getItem('arovolife_theme')
                ?? localStorage.getItem('arovolife_admin_theme');
            if (saved === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) { /* private browsing — stay light */ }
    })();
</script>
