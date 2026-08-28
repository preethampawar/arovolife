@props(['text', 'light' => false])
{{-- Info icon with a hover/focus tooltip. Usage: <x-help-tip text="..." /> --}}
{{-- Pass light (boolean) when the icon sits on a dark/gradient background. --}}
{{-- The popup is repositioned to fixed viewport coordinates on show so it is never
     clipped by overflow-x-auto table wrappers or overflow-hidden cards. --}}
<span class="relative inline-flex items-center align-middle ml-1" data-help-tip>
    <button type="button" tabindex="0" aria-label="More information"
        class="inline-flex h-4 w-4 items-center justify-center rounded-full border text-[10px] font-bold focus:outline-none focus:ring-2 focus:ring-brand-400
               {{ $light ? 'border-white/60 text-white/90 hover:bg-white/10' : 'border-gray-400 text-gray-600 hover:bg-gray-100' }}">
        i
    </button>
    <span role="tooltip"
        class="pointer-events-none invisible fixed z-50 w-56 rounded-lg bg-gray-900 px-3 py-2 text-xs leading-snug text-white opacity-0 shadow-lg transition-opacity duration-150">
        {{ $text }}
    </span>
</span>
@once
<script>
(function () {
    var active = null;

    function show(root) {
        var btn = root.querySelector('button');
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
    }

    function hide(root) {
        var tip = root ? root.querySelector('[role="tooltip"]') : active;
        if (!tip) return;
        tip.classList.add('opacity-0', 'invisible');
        tip.classList.remove('opacity-100');
        if (active === tip) active = null;
    }

    document.addEventListener('mouseover', function (e) {
        var root = e.target.closest('[data-help-tip]');
        if (root && !root.contains(e.relatedTarget)) show(root);
    });
    document.addEventListener('mouseout', function (e) {
        var root = e.target.closest('[data-help-tip]');
        if (root && !root.contains(e.relatedTarget)) hide(root);
    });
    document.addEventListener('focusin', function (e) {
        var root = e.target.closest('[data-help-tip]');
        if (root) show(root);
    });
    document.addEventListener('focusout', function (e) {
        var root = e.target.closest('[data-help-tip]');
        if (root) hide(root);
    });
    window.addEventListener('scroll', function () { if (active) hide(null); }, true);
    window.addEventListener('resize', function () { if (active) hide(null); });
})();
</script>
@endonce
