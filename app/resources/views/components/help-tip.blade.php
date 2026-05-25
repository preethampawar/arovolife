@props(['text'])
{{-- Info icon with a hover/focus tooltip. Usage: <x-help-tip text="..." /> --}}
<span class="relative inline-flex items-center group align-middle ml-1">
    <button type="button" tabindex="0" aria-label="More information"
        class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-gray-400 text-[10px] font-bold text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-400">
        i
    </button>
    <span role="tooltip"
        class="pointer-events-none absolute left-1/2 bottom-full z-20 mb-1 w-56 -translate-x-1/2 rounded-lg bg-gray-900 px-3 py-2 text-xs leading-snug text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
        {{ $text }}
    </span>
</span>
