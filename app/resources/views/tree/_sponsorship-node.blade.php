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

    // Rank + personal BV for this card, resolved for the whole canvas at once
    // in _content.blade.php (R-65). Null rows render "—".
    $cardStats = $cardStatsById[$node->id] ?? null;
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
                class="w-4 h-4 inline-flex items-center justify-center rounded text-gray-600 hover:text-brand-800 hover:bg-white/80 transition-colors leading-none">
                <x-lucide-ellipsis-vertical class="w-3.5 h-3.5" />
            </button>
            <div data-node-menu-panel hidden
                class="absolute right-0 top-full mt-1 min-w-[180px] rounded-lg bg-white shadow-lg ring-1 ring-gray-200 z-50 text-left overflow-hidden">
                {{-- Menu order — same for admin + distributor:
                     1. Show only this person's tree
                     2. Send Message
                     3. Details
                     4. View profile      (admin context only)
                     5. Impersonate       (admin context only)
                --}}
                <a href="{{ $pivotUrl }}"
                    class="flex items-start gap-2 px-3 py-2 text-xs text-gray-800 hover:bg-brand-50 hover:text-brand-800 border-b border-gray-100">
                    <x-lucide-expand class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-600" />
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold">Show only this person's tree</span>
                        <span class="block text-[11px] text-gray-600 mt-0.5">Hide ancestors; root here</span>
                    </span>
                </a>
                @if($node->user_id && auth()->id() !== (int) $node->user_id)
                <button type="button"
                    data-send-message="{{ $node->user_id }}"
                    data-send-message-name="{{ $node->user?->full_name ?: ('Distributor '.$node->adn) }}"
                    class="flex items-start gap-2 w-full text-left px-3 py-2 text-xs text-gray-800 hover:bg-brand-50 hover:text-brand-800 border-b border-gray-100">
                    <x-lucide-send class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-600" />
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold">Send Message</span>
                        <span class="block text-[11px] text-gray-600 mt-0.5">Open a quick message popup</span>
                    </span>
                </button>
                @endif
                <button type="button"
                    data-open-distributor-details="{{ $node->id }}"
                    class="flex items-start gap-2 w-full text-left px-3 py-2 text-xs text-gray-800 hover:bg-brand-50 hover:text-brand-800 {{ $adminContext ? 'border-b border-gray-100' : '' }}">
                    <x-lucide-id-card class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-600" />
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold">Details</span>
                        <span class="block text-[11px] text-gray-600 mt-0.5">Full ID-card panel in a popup</span>
                    </span>
                </button>
                @if($adminContext)
                <a href="{{ route('admin.distributors.show', $node->id) }}"
                    class="flex items-start gap-2 px-3 py-2 text-xs text-gray-800 hover:bg-brand-50 hover:text-brand-800 border-b border-gray-100">
                    <x-lucide-circle-user-round class="w-3.5 h-3.5 mt-0.5 shrink-0 text-gray-600" />
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold">View profile</span>
                        <span class="block text-[11px] text-gray-600 mt-0.5">Open the distributor profile</span>
                    </span>
                </a>
                @if($node->user_id && auth()->id() !== (int) $node->user_id)
                <form method="POST" action="{{ route('admin.impersonate.start', $node->user_id) }}" class="block">
                    @csrf
                    <button type="submit"
                        class="flex items-start gap-2 w-full text-left px-3 py-2 text-xs text-sunrise-700 hover:bg-sunrise-50">
                        <x-lucide-users class="w-3.5 h-3.5 mt-0.5 shrink-0 text-sunrise-500" />
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
            <span class="font-mono font-bold text-brand-700 tracking-wider text-[12px] leading-tight">{{ $node->adn }}</span>
            <button type="button"
                data-copy-adn="{{ $node->adn }}"
                onclick="copyAdn(this); event.stopPropagation();"
                title="Copy ADN"
                class="text-gray-600 hover:text-brand-800 transition-colors p-0.5 rounded leading-none">
                <x-lucide-file-text class="w-3 h-3" />
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
            <dd class="text-right text-gray-800">
                @if($cardStats['highest_rank'] ?? null)
                    {{ $cardStats['highest_rank'] }}
                @else
                    <span class="text-gray-600">—</span>
                @endif
            </dd>

            <dt class="text-gray-800 font-medium">Current Rank</dt>
            <dd class="text-right text-gray-800">
                @if($cardStats['current_rank'] ?? null)
                    {{ $cardStats['current_rank'] }}
                @else
                    <span class="text-gray-600">—</span>
                @endif
            </dd>

            <dt class="text-gray-800 font-medium">Personal BV</dt>
            <dd class="text-right text-gray-800">
                @if($cardStats['total_personal_bv'] ?? null)
                    {{ $cardStats['total_personal_bv'] }}
                @else
                    <span class="text-gray-600">—</span>
                @endif
            </dd>
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
