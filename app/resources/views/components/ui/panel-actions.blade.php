@props([
    'generatedAt',
    'title',
])

{{-- The header furniture every dashboard section card carries: when the
     figures were taken, and the button the shell's delegated handler turns
     into a refresh of this panel alone (it looks for [data-panel-refresh]). --}}
<span class="text-[11px] tabular-nums text-gray-500">as of {{ $generatedAt->format('H:i') }}</span>
<button type="button" data-panel-refresh
        class="inline-flex items-center rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
        aria-label="Refresh {{ $title }}">
    {{ svg('lucide-refresh-cw', 'w-3.5 h-3.5') }}
</button>
