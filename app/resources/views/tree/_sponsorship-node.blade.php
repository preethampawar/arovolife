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

    // Depth-scaled horizontal padding around each child subtree — shallow
    // levels tight, deeper levels roomier so siblings don't crowd. Static
    // literals for Tailwind's JIT.
    $childPad = match (true) {
        $level <= 1 => 'px-0.5',
        $level === 2 => 'px-1',
        $level === 3 => 'px-2',
        $level === 4 => 'px-3',
        default      => 'px-4',
    };
@endphp

<div class="flex flex-col items-center">
    @php
        // Single source of truth — see User::statusTheme(). Same tokens
        // are used by the binary card and the dashboard / Details
        // popup verification pill.
        $theme = $node->user->statusTheme();
    @endphp

    <div class="relative rounded-xl border {{ $theme['border'] }} {{ $theme['bg'] }} {{ $isSelf ? 'ring-2 ring-brand-300' : '' }} px-2 py-2 text-center min-w-[150px] max-w-[168px] shadow-sm">
        <div class="group absolute top-1.5 left-1.5">
            <span class="block w-2 h-2 rounded-full {{ $theme['dot'] }} ring-2 ring-white cursor-help"></span>
            <div class="pointer-events-none absolute left-0 top-full mt-1.5 z-50 hidden group-hover:block whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-lg">
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full {{ $theme['dot'] }} ring-1 ring-white/40"></span>
                    {{ $theme['card_label'] }}
                </span>
                <span class="absolute bottom-full left-2 border-4 border-transparent border-b-gray-900"></span>
            </div>
        </div>

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
                class="w-4 h-4 inline-flex items-center justify-center rounded text-gray-500 hover:text-brand-700 hover:bg-white/80 transition-colors leading-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z"/>
                </svg>
            </button>
            <div data-node-menu-panel hidden
                class="absolute right-0 top-full mt-1 min-w-[180px] rounded-lg bg-white shadow-lg ring-1 ring-gray-200 z-50 text-left">
                <button type="button"
                    data-open-distributor-details="{{ $node->id }}"
                    class="flex items-start gap-2 w-full text-left px-3 py-2 text-xs text-gray-800 hover:bg-brand-50 hover:text-brand-700 rounded-t-lg border-b border-gray-100">
                    <svg class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z"/>
                    </svg>
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold">Details</span>
                        <span class="block text-[11px] text-gray-600 mt-0.5">Full ID-card panel in a popup</span>
                    </span>
                </button>
                @if($node->user_id && auth()->id() !== (int) $node->user_id)
                <button type="button"
                    data-send-message="{{ $node->user_id }}"
                    data-send-message-name="{{ $node->user?->full_name ?: ('Distributor '.$node->adn) }}"
                    class="flex items-start gap-2 w-full text-left px-3 py-2 text-xs text-gray-800 hover:bg-brand-50 hover:text-brand-700 border-b border-gray-100">
                    <svg class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
                    </svg>
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold">Send Message</span>
                        <span class="block text-[11px] text-gray-600 mt-0.5">Open a quick message popup</span>
                    </span>
                </button>
                @endif
                <a href="{{ $pivotUrl }}"
                    class="flex items-start gap-2 px-3 py-2 text-xs text-gray-800 hover:bg-brand-50 hover:text-brand-700">
                    <svg class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15m11.25 5.25v-4.5m0 4.5h-4.5m4.5 0L15 15m5.25-11.25h-4.5m4.5 0v4.5m0-4.5L15 9"/>
                    </svg>
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold">Show only this person's tree</span>
                        <span class="block text-[11px] text-gray-600 mt-0.5">Hide ancestors; root here</span>
                    </span>
                </a>
                @if($adminContext)
                <a href="{{ route('admin.distributors.show', $node->id) }}"
                    class="flex items-start gap-2 px-3 py-2 text-xs text-gray-800 hover:bg-brand-50 hover:text-brand-700 border-t border-gray-100">
                    <svg class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                    </svg>
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold">View profile</span>
                        <span class="block text-[11px] text-gray-600 mt-0.5">Open the distributor profile</span>
                    </span>
                </a>
                @if($node->user_id && auth()->id() !== (int) $node->user_id)
                <form method="POST" action="{{ route('admin.impersonate.start', $node->user_id) }}" class="block">
                    @csrf
                    <button type="submit"
                        class="flex items-start gap-2 w-full text-left px-3 py-2 text-xs text-sunrise-700 hover:bg-sunrise-50 rounded-b-lg border-t border-gray-100">
                        <svg class="w-3.5 h-3.5 mt-0.5 shrink-0 text-sunrise-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
                        </svg>
                        <span class="flex-1 min-w-0">
                            <span class="block font-semibold">Impersonate</span>
                            <span class="block text-[11px] text-sunrise-700/80 mt-0.5">Log in as this distributor for support</span>
                        </span>
                    </button>
                </form>
                @endif
                @endif
            </div>
        </div>
        <p class="text-[11px] uppercase tracking-wider {{ $isSelf ? 'text-brand-700 font-semibold' : 'text-gray-700 font-medium' }}">{{ $title }}</p>
        @php $fullName = $node->user?->full_name; @endphp
        @if($fullName)
            <p class="text-xs text-gray-800 font-medium leading-tight mt-0.5 truncate" title="{{ $fullName }}">{{ $fullName }}</p>
        @endif
        <div class="flex items-center justify-center gap-1 mt-0.5">
            <span class="font-mono font-bold text-brand-600 tracking-wider text-[12px] leading-tight">{{ $node->adn }}</span>
            <button type="button"
                data-copy-adn="{{ $node->adn }}"
                onclick="copyAdn(this); event.stopPropagation();"
                title="Copy ADN"
                class="text-gray-500 hover:text-brand-600 transition-colors p-0.5 rounded leading-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z" />
                </svg>
            </button>
        </div>

        {{-- Same 6-field summary as the binary card — uses the model's
             verificationLabel()/verificationClass() accessors so the
             label-and-pill mapping stays single-source-of-truth across
             card, dashboard, and popup. --}}
        <dl class="mt-2 pt-2 border-t border-gray-300 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-[11px] text-left">
            <dt class="text-gray-800 font-medium">Region</dt>
            <dd class="text-gray-800 text-right">India</dd>

            <dt class="text-gray-800 font-medium">Status</dt>
            <dd class="text-right">
                <span class="inline-flex items-center px-1.5 py-0 rounded-full text-[10px] font-semibold border {{ $theme['pill'] }}">
                    {{ $theme['pill_label'] }}
                </span>
            </dd>

            <dt class="text-gray-800 font-medium">Activated</dt>
            <dd class="text-right text-gray-800">
                @if($node->user->activated_at)
                    {{ $node->user->activated_at->format('d M Y') }}
                @else
                    <span class="text-gray-600">—</span>
                @endif
            </dd>

            <dt class="text-gray-800 font-medium">Highest Rank</dt>
            <dd class="text-right text-gray-600">—{{-- PHASE_LATER_PLACEHOLDER --}}</dd>

            <dt class="text-gray-800 font-medium">Current Rank</dt>
            <dd class="text-right text-gray-600">—{{-- PHASE_LATER_PLACEHOLDER --}}</dd>

            <dt class="text-gray-800 font-medium">Personal BV</dt>
            <dd class="text-right text-gray-600">—{{-- PHASE_LATER_PLACEHOLDER --}}</dd>
        </dl>

        @if($adminContext)
            <p class="text-[11px] text-gray-800 font-medium mt-1.5">Level {{ $node->depth }}</p>
        @endif
        @if($showMoreBelow)
            <p class="text-[11px] text-gray-600 mt-1 italic">+{{ count($children) }} more below</p>
        @endif
    </div>

    @if($level === 0 && $childCount === 0)
        {{-- Empty-state copy: distributors can legitimately have a populated
             binary downline (via spillover from upline) while having zero
             personal sponsees, which would otherwise show as just a lone
             card with no indication of what's going on. --}}
        <div class="mt-6 max-w-md text-center text-sm text-gray-700">
            <p>You haven't directly introduced any distributors yet.</p>
            <p class="mt-1 text-xs">Share your referral link from the dashboard to start growing your sponsorship tree.</p>
        </div>
    @endif

    @if($renderInlineChildren)
        {{-- Connectors:
             container::before — vertical from parent's bottom down to bus
             span#bus          — horizontal bus spanning first child's centre
                                 to last child's centre. Columns are equal
                                 width via `grid-template-columns: repeat(N,
                                 minmax(0, 1fr))` so child i's centre sits
                                 at (i + 0.5) / N → bus left/right inset =
                                 50% / N. For 1 child the bus is zero-width
                                 (invisible).
             column::before    — vertical from bus down to each child card.

             Important: this uses CSS Grid (not Flex with flex-1) because
             flex-basis-0 children that contain a wide recursive subtree
             will overflow their basis and take their content's intrinsic
             width, leaving the column centres unequal — which would mis-
             align the bus endpoints. Grid with minmax(0,1fr) forces equal
             column widths regardless of content. --}}
        <div class="relative pt-6 grid items-start w-full gap-0
            before:content-[''] before:absolute before:top-0 before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-3 before:bg-slate-500"
            style="grid-template-columns: repeat({{ $childCount }}, minmax(0, 1fr));{{ $childCount > 1 ? ' --bus-inset: calc(50% / '.$childCount.');' : '' }}"
        >
            @if($childCount > 1)
                <span class="absolute top-3 h-[2px] bg-slate-500"
                    style="left: var(--bus-inset); right: var(--bus-inset);"></span>
            @endif

            @foreach($children as $child)
                <div class="relative pt-3 flex justify-center {{ $childPad }}
                    before:content-[''] before:absolute before:top-[-0.75rem] before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-6 before:bg-slate-500">
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
