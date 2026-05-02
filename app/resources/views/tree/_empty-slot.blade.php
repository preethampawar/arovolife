@php
    // $parent — the distributor whose L/R slot is empty
    // $side   — 'L' or 'R'
    // $self   — the logged-in distributor (inherited from binary.blade.php)
    $sideLabel = $side === 'L' ? 'left' : 'right';
@endphp

<button type="button"
    data-invite-parent="{{ $parent->adn }}"
    data-invite-side="{{ $side }}"
    data-invite-side-label="{{ $sideLabel }}"
    onclick="openInviteModal(this)"
    class="rounded-xl border border-dashed border-gray-300 px-3 py-2 text-[11px] text-gray-400 text-center bg-gray-50/50 cursor-pointer transition-colors hover:border-brand-400 hover:bg-brand-50/60 hover:text-brand-700 min-w-[140px]">
    <span class="block">Empty ({{ $sideLabel }})</span>
    <span class="block text-[10px] text-gray-400 mt-0.5">click to invite</span>
</button>
