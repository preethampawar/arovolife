@php
    /**
     * Sponsorship (direct-referral) tree node.
     *
     * Identical card markup to _binary-node.blade.php so the visual feel
     * is consistent across the two modes. The structural difference is
     * children: a sponsor can have 0..N directly-introduced distributors,
     * not 2 binary slots — so they're rendered as a flex row whose width
     * grows naturally with the number of children, instead of a
     * grid-cols-2 binary L/R split.
     */
    $adminContext = $adminContext ?? false;
    $children     = $childrenByParent[$node->id] ?? [];
    $isSelf       = isset($self) && $self->id === $node->id;
    $title        = $isSelf ? 'You' : 'Direct';

    // Children rendered as long as we haven't hit the depth cap. Sponsorship
    // has no "frontier empty slot" concept (you don't pre-allocate two
    // sides), so the binary partial's hover-popover affordance doesn't
    // apply here.
    $renderInlineChildren = $level < $maxDepth && count($children) > 0;
    $showMoreBelow        = $level === $maxDepth && count($children) > 0;
    $childCount           = count($children);
@endphp

<div class="flex flex-col items-center">
    @php
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
        <span class="absolute top-1.5 left-1.5 w-2 h-2 rounded-full {{ $statusInfo['dot'] }} ring-2 ring-white" title="{{ $statusInfo['label'] }}"></span>

        @php
            // Pivot stays in sponsorship mode — clicking "show only this
            // person's tree" should re-root the sponsorship view, not jump
            // sideways into the binary view.
            $pivotUrl = $adminContext
                ? route('admin.tree.show', $node->id)
                : route('tree.sponsorship', ['adn' => $node->adn]);
        @endphp
        <div class="absolute top-1.5 right-1.5" data-node-menu>
            <button type="button" data-node-menu-trigger
                onclick="event.stopPropagation(); toggleNodeMenu(this);"
                title="More actions"
                class="w-4 h-4 inline-flex items-center justify-center rounded text-gray-400 hover:text-brand-700 hover:bg-white/80 transition-colors leading-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z"/>
                </svg>
            </button>
            <div data-node-menu-panel hidden
                class="absolute right-0 top-full mt-1 min-w-[180px] rounded-lg bg-white shadow-lg ring-1 ring-gray-200 z-50 text-left">
                <a href="{{ $pivotUrl }}"
                    class="block px-3 py-2 text-[11px] text-gray-700 hover:bg-brand-50 hover:text-brand-700 rounded-lg">
                    <span class="block font-semibold">Show only this person's tree</span>
                    <span class="block text-[10px] text-gray-400 mt-0.5">Hide ancestors; root here</span>
                </a>
                @if($adminContext)
                <a href="{{ route('admin.distributors.show', $node->id) }}"
                    class="block px-3 py-2 text-[11px] text-gray-700 hover:bg-brand-50 hover:text-brand-700 border-t border-gray-100">
                    <span class="block font-semibold">View profile</span>
                    <span class="block text-[10px] text-gray-400 mt-0.5">Open the distributor profile</span>
                </a>
                @if($node->user_id && auth()->id() !== (int) $node->user_id)
                <form method="POST" action="{{ route('admin.impersonate.start', $node->user_id) }}" class="block">
                    @csrf
                    <button type="submit"
                        class="block w-full text-left px-3 py-2 text-[11px] text-sunrise-700 hover:bg-sunrise-50 rounded-b-lg border-t border-gray-100">
                        <span class="block font-semibold">Impersonate</span>
                        <span class="block text-[10px] text-sunrise-600/70 mt-0.5">Log in as this distributor for support</span>
                    </button>
                </form>
                @endif
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
        @if($showMoreBelow)
            <p class="text-[10px] text-gray-400 mt-1 italic">+{{ count($children) }} more below</p>
        @endif
    </div>

    @if($renderInlineChildren)
        {{-- Connectors:
             container::before — vertical from parent's bottom down to bus
             container::after  — horizontal bus spanning first child's
                                 centre to last child's centre. With equal-
                                 width flex-1 columns, child i's centre sits
                                 at (i + 0.5) / N → bus left/right inset =
                                 50% / N. For 1 child the bus is zero-width
                                 (invisible) which is correct.
             column::before    — vertical from bus down to each child card --}}
        <div class="relative pt-8 flex items-start w-full
            before:content-[''] before:absolute before:top-0 before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-4 before:bg-slate-300"
            @if($childCount > 1)
            style="--bus-inset: calc(50% / {{ $childCount }});"
            @endif
        >
            @if($childCount > 1)
                <span class="absolute top-4 h-[2px] bg-slate-300"
                    style="left: var(--bus-inset); right: var(--bus-inset);"></span>
            @endif

            @foreach($children as $child)
                <div class="relative pt-4 flex justify-center px-3 flex-1
                    before:content-[''] before:absolute before:top-[-1rem] before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-8 before:bg-slate-300">
                    @include('tree._sponsorship-node', [
                        'node'              => $child,
                        'level'             => $level + 1,
                        'maxDepth'          => $maxDepth,
                        'childrenByParent'  => $childrenByParent,
                        'adminContext'      => $adminContext,
                    ])
                </div>
            @endforeach
        </div>
    @endif
</div>
