{{-- Admin theme FOUC preventer. Reads the saved admin theme from
     localStorage and stamps `dark` on <html> BEFORE first paint, so an
     admin who chose dark never sees a light frame flash first.

     Light is the default: the class is added only for the exact value
     'dark'. Anything else — absent, corrupt, or a private-browsing
     throw — leaves the console light.

     Include this in the <head> of the admin layout ONLY. The toggle
     that writes the key lives in the admin header; no public,
     shop, wizard or distributor page reads or sets it. --}}
<script>
    (() => {
        try {
            if (localStorage.getItem('arovolife_admin_theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) { /* private-browsing — stay light */ }
    })();
</script>
