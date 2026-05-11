@php
    $adminContext = $adminContext ?? false;
    $left  = $childByParentSide[$node->id]['L'] ?? null;
    $right = $childByParentSide[$node->id]['R'] ?? null;
    $isSelf = isset($self) && $self->id === $node->id;
    $title = $isSelf
        ? 'You'
        : ($node->placement_side === 'L'
            ? 'Left leg'
            : ($node->placement_side === 'R' ? 'Right leg' : 'Root'));

    $hasAnyChild = $left !== null || $right !== null;

    // Three rendering states:
    //   1. $level < $maxDepth → always render children (real + frontier empties).
    //   2. $level == $maxDepth AND no real children → render hover-only invite
    //      affordance ABSOLUTELY POSITIONED below the leaf, so layout stays stable
    //      (no canvas resize / shift on hover).
    //   3. $level == $maxDepth AND there are real children below → "more below" hint.
    $renderInlineChildren = $level < $maxDepth;
    $showLeafHoverEmpties = $level === $maxDepth && ! $hasAnyChild;
    $showMoreBelow        = $level === $maxDepth && $hasAnyChild;
@endphp

<div class="flex flex-col items-center {{ $showLeafHoverEmpties ? 'relative' : '' }}"
    @if($showLeafHoverEmpties) data-leaf-wrapper @endif>
    @php
        // Map User.status (enum: pending|active|frozen|terminated) → display
        // colour + label. `bg` is a very light tint applied to the card so
        // status is readable at a glance; `border` matches but a step
        // darker. The self-card still wins visually via a brand-blue ring.
        $statusMap = [
            'pending'    => ['dot' => 'bg-yellow-400',  'bg' => 'bg-yellow-50',  'border' => 'border-yellow-200',  'label' => 'New Member'],
            'active'     => ['dot' => 'bg-leaf-500',    'bg' => 'bg-leaf-50',    'border' => 'border-leaf-200',    'label' => 'Active'],
            'frozen'     => ['dot' => 'bg-sunrise-500', 'bg' => 'bg-sunrise-50', 'border' => 'border-sunrise-200', 'label' => 'Suspended'],
            'terminated' => ['dot' => 'bg-red-500',     'bg' => 'bg-red-50',     'border' => 'border-red-200',     'label' => 'Inactive'],
        ];
        $status     = $node->user?->status ?? 'pending';
        $statusInfo = $statusMap[$status] ?? $statusMap['pending'];
    @endphp
    <div class="relative rounded-xl border {{ $statusInfo['border'] }} {{ $statusInfo['bg'] }} {{ $isSelf ? 'ring-2 ring-brand-300' : '' }} px-2 py-2 text-center min-w-[120px] max-w-[150px] shadow-sm">
        <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full {{ $statusInfo['dot'] }} ring-2 ring-white" title="{{ $statusInfo['label'] }}"></span>

        @php
            // The "show only this person's tree" pivot URL.
            // Admin context: /admin/tree/{id}; distributor context: /tree/{adn}.
            // Plain <a href>'s create real history entries — no JS routing needed
            // for the "tracked in browser history" requirement.
            $pivotUrl = $adminContext
                ? route('admin.tree.show', $node->id)
                : route('tree.binary', $node->adn);
        @endphp
        <div class="absolute top-1.5 left-1.5" data-node-menu>
            <button type="button" data-node-menu-trigger
                onclick="event.stopPropagation(); toggleNodeMenu(this);"
                title="More actions"
                class="w-4 h-4 inline-flex items-center justify-center rounded text-gray-400 hover:text-brand-700 hover:bg-white/80 transition-colors leading-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z"/>
                </svg>
            </button>
            <div data-node-menu-panel hidden
                class="absolute left-0 top-full mt-1 min-w-[180px] rounded-lg bg-white shadow-lg ring-1 ring-gray-200 z-50 text-left">
                <a href="{{ $pivotUrl }}"
                    class="block px-3 py-2 text-[11px] text-gray-700 hover:bg-brand-50 hover:text-brand-700 rounded-lg">
                    <span class="block font-semibold">Show only this person's tree</span>
                    <span class="block text-[10px] text-gray-400 mt-0.5">Hide siblings and parent; root here</span>
                </a>
                @if($adminContext)
                <a href="{{ route('admin.distributors.show', $node->id) }}"
                    class="block px-3 py-2 text-[11px] text-gray-700 hover:bg-brand-50 hover:text-brand-700 rounded-b-lg border-t border-gray-100">
                    <span class="block font-semibold">View profile</span>
                    <span class="block text-[10px] text-gray-400 mt-0.5">Open the distributor profile</span>
                </a>
                @endif
            </div>
        </div>
        <p class="text-[10px] uppercase tracking-wider {{ $isSelf ? 'text-brand-600 font-semibold' : 'text-gray-400' }}">{{ $title }}</p>
        @php $fullName = $node->user?->full_name; @endphp
        @if($fullName)
            <p class="text-[11px] text-gray-700 font-medium leading-tight mt-0.5 truncate" title="{{ $fullName }}">{{ $fullName }}</p>
        @endif
        <div class="flex items-center justify-center gap-1 mt-0.5">
            <span class="font-mono font-bold text-brand-600 tracking-wider text-[12px] leading-tight">{{ $node->adn }}</span>
            <button type="button"
                data-copy-adn="{{ $node->adn }}"
                onclick="copyAdn(this); event.stopPropagation();"
                title="Copy ADN"
                class="text-gray-400 hover:text-brand-600 transition-colors p-0.5 rounded leading-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z" />
                </svg>
            </button>
        </div>
        @if($adminContext)
            <p class="text-[10px] text-gray-500 mt-0.5">Level {{ $node->depth }}</p>
        @endif
    </div>

    @if($renderInlineChildren)
        {{-- Children row. Connectors:
             container::before — vertical from parent's bottom down to the horizontal (h-4 ends EXACTLY at the horizontal)
             container::after  — horizontal at top-4
             column::before    — vertical from horizontal down to each child card --}}
        <div class="relative pt-8 grid grid-cols-2 gap-0 w-full
            before:content-[''] before:absolute before:top-0 before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-4 before:bg-slate-300
            after:content-[''] after:absolute after:top-4 after:left-1/4 after:right-1/4 after:h-[2px] after:bg-slate-300">

            <div class="relative pt-4 flex justify-center px-0.5
                before:content-[''] before:absolute before:top-[-1rem] before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-8 before:bg-slate-300">
                @if($left)
                    @include('tree._binary-node', [
                        'node'              => $left,
                        'level'             => $level + 1,
                        'maxDepth'          => $maxDepth,
                        'childByParentSide' => $childByParentSide,
                        'adminContext'      => $adminContext,
                    ])
                @else
                    @include('tree._empty-slot', ['parent' => $node, 'side' => 'L'])
                @endif
            </div>

            <div class="relative pt-4 flex justify-center px-0.5
                before:content-[''] before:absolute before:top-[-1rem] before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-8 before:bg-slate-300">
                @if($right)
                    @include('tree._binary-node', [
                        'node'              => $right,
                        'level'             => $level + 1,
                        'maxDepth'          => $maxDepth,
                        'childByParentSide' => $childByParentSide,
                        'adminContext'      => $adminContext,
                    ])
                @else
                    @include('tree._empty-slot', ['parent' => $node, 'side' => 'R'])
                @endif
            </div>
        </div>
    @elseif($showLeafHoverEmpties)
        {{-- Hover-only invite affordance, absolute-positioned so layout stays
             stable. The popover's open/close is driven by JS in binary.blade.php
             with an 800ms close delay AND mouseenter on the popover itself
             cancels the timer — so the user can move the cursor in any
             direction (including the gap between the card and popover) and
             still have time to land on the buttons. --}}
        <div data-leaf-popover
            class="absolute top-full left-1/2 -translate-x-1/2 mt-2 z-20 hidden">
            <div class="relative rounded-xl border border-brand-200 bg-white shadow-lg p-2 flex gap-2 whitespace-nowrap
                before:content-[''] before:absolute before:bottom-full before:left-1/2 before:-translate-x-1/2 before:border-4 before:border-transparent before:border-b-brand-200">
                <button type="button"
                    data-invite-parent="{{ $node->adn }}"
                    data-invite-side="L"
                    data-invite-side-label="left"
                    onclick="openInviteModal(this)"
                    class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-[11px] text-gray-500 bg-gray-50/50 transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700 min-w-[110px]">
                    <span class="block">Invite (left)</span>
                    <span class="block text-[10px] text-gray-400 mt-0.5">click to invite</span>
                </button>
                <button type="button"
                    data-invite-parent="{{ $node->adn }}"
                    data-invite-side="R"
                    data-invite-side-label="right"
                    onclick="openInviteModal(this)"
                    class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-[11px] text-gray-500 bg-gray-50/50 transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700 min-w-[110px]">
                    <span class="block">Invite (right)</span>
                    <span class="block text-[10px] text-gray-400 mt-0.5">click to invite</span>
                </button>
            </div>
        </div>
    @elseif($showMoreBelow)
        <div class="mt-2 text-[10px] text-gray-400 italic">
            ↓ more below — increase depth filter to expand
        </div>
    @endif
</div>
