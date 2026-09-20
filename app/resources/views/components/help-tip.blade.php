@props(['text', 'light' => false, 'interactive' => true])
{{-- Info icon with a hover/focus tooltip. Usage: <x-help-tip text="..." /> --}}
{{-- The icon carries no focus utilities of its own: app.css gives the admin
     shell one :focus-visible treatment, and the browser default covers the
     rest. A ring here as well would double-draw inside the console. --}}
{{-- Pass light (boolean) when the icon sits on a dark/gradient background. --}}
{{-- Pass interactive="false" when the icon already sits inside another interactive
     element (a card rendered as role="button" or an <a>): a focusable/aria-hidden
     control nested in one is still exposed to assistive tech, so it renders as a
     plain decorative span instead. Fold the tip text into the ancestor's own
     aria-label so screen-reader users still get it; mouse hover still shows it here. --}}
{{-- The popup is repositioned to fixed viewport coordinates on show so it is never
     clipped by overflow-x-auto table wrappers or overflow-hidden cards. --}}
<span class="relative inline-flex items-center align-middle ml-1" data-help-tip>
    @if($interactive)
    <button type="button" tabindex="0" aria-label="More information"
        class="inline-flex h-4 w-4 items-center justify-center rounded-full transition-colors
               {{ $light ? 'text-white/80 hover:text-white' : 'text-gray-500 hover:text-gray-700' }}">
        {{ svg('lucide-circle-help', 'w-3.5 h-3.5') }}
    </button>
    @else
    <span aria-hidden="true"
        class="inline-flex h-4 w-4 items-center justify-center rounded-full
               {{ $light ? 'text-white/80' : 'text-gray-500' }}">
        {{ svg('lucide-circle-help', 'w-3.5 h-3.5') }}
    </span>
    @endif
    {{-- Width stays fixed: the positioning script measures offsetWidth to keep
         the tip inside the viewport, and an intrinsic width would make that
         measurement depend on where the tip happens to sit.

         whitespace-normal / text-left are not defaults here: a table header
         sets whitespace-nowrap and its own alignment, and the tip inherits
         both — which ran every sentence onto one line straight out of the
         240px box. break-words catches the long unbroken token (a URL, an
         ADN) that would do the same on its own.

         Hoverable, so the text can be read at leisure and selected: while
         hidden the tip is `invisible`, which already takes it out of the hit
         test, so it never intercepts a click on the row behind it. --}}
    <span role="tooltip"
        class="invisible fixed z-50 w-60 whitespace-normal break-words rounded-xl bg-gray-900 px-3.5 py-2.5 text-left text-xs font-normal normal-case leading-relaxed tracking-normal text-white opacity-0 shadow-xl transition-opacity duration-150">
        {{ $text }}
    </span>
</span>
@once
<script>
(function () {
    var active = null;
    var activeRoot = null;
    var hideTimer = null;

    // The tip is placed at fixed viewport coordinates a few pixels off the
    // icon, so the pointer leaves the trigger on its way to the tip. Hiding is
    // therefore deferred: re-entering either the icon or the tip inside the
    // grace period cancels it, which is what makes the text reachable at all.
    var HIDE_DELAY_MS = 220;

    function show(root) {
        var btn = root.querySelector('button, span[aria-hidden]');
        var tip = root.querySelector('[role="tooltip"]');
        if (!btn || !tip) return;
        var r = btn.getBoundingClientRect();
        tip.classList.remove('invisible');
        var w = tip.offsetWidth;
        var h = tip.offsetHeight;
        var left = Math.max(8, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 8));
        var top = r.top - h - 6;
        if (top < 8) top = r.bottom + 6;
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
        tip.classList.add('opacity-100');
        tip.classList.remove('opacity-0');
        active = tip;
        activeRoot = root;
    }

    function hide(root) {
        var tip = root ? root.querySelector('[role="tooltip"]') : active;
        if (!tip) return;
        tip.classList.add('opacity-0', 'invisible');
        tip.classList.remove('opacity-100');
        if (active === tip) {
            active = null;
            activeRoot = null;
        }
    }

    function cancelHide() {
        if (hideTimer !== null) {
            clearTimeout(hideTimer);
            hideTimer = null;
        }
    }

    function scheduleHide(root) {
        cancelHide();
        hideTimer = setTimeout(function () {
            hideTimer = null;
            hide(root);
        }, HIDE_DELAY_MS);
    }

    function hideNow(root) {
        cancelHide();
        hide(root);
    }

    document.addEventListener('mouseover', function (e) {
        var root = e.target.closest ? e.target.closest('[data-help-tip]') : null;
        if (!root) return;
        // Coming back to the icon, or onto the tip itself: keep it open and
        // leave it exactly where it was — re-measuring would make the tip
        // jitter as the pointer travels across it.
        cancelHide();
        if (activeRoot !== root) show(root);
    });
    document.addEventListener('mouseout', function (e) {
        var root = e.target.closest ? e.target.closest('[data-help-tip]') : null;
        if (root && !root.contains(e.relatedTarget)) scheduleHide(root);
    });
    document.addEventListener('focusin', function (e) {
        var root = e.target.closest ? e.target.closest('[data-help-tip]') : null;
        if (root) {
            cancelHide();
            show(root);
        }
    });
    document.addEventListener('focusout', function (e) {
        var root = e.target.closest ? e.target.closest('[data-help-tip]') : null;
        if (root) hideNow(root);
    });
    // Escape closes it the way it closes every other transient layer.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && active) hideNow(activeRoot);
    });
    window.addEventListener('scroll', function () { if (active) hideNow(null); }, true);
    window.addEventListener('resize', function () { if (active) hideNow(null); });
})();
</script>
@endonce
