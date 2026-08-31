@php
    $adminContext = $adminContext ?? false;
    $left  = $childByParentSide[$node->id]['L'] ?? null;
    $right = $childByParentSide[$node->id]['R'] ?? null;
    $isSelf = isset($self) && $self->id === $node->id;
    $title = $isSelf
        ? 'You'
        : ($node->placement_side === 'L'
            ? 'Left group'
            : ($node->placement_side === 'R' ? 'Right group' : 'Root'));

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

    // Depth-scaled horizontal padding around each child subtree. Shallow
    // levels stay tight (their subtrees are already spread wide); deeper
    // levels — where siblings would otherwise crowd together — get more
    // breathing room. Static literals so Tailwind's JIT picks them up.
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

<div class="flex flex-col items-center {{ $showLeafHoverEmpties ? 'relative' : '' }}"
    @if($showLeafHoverEmpties) data-leaf-wrapper @endif>
    @php
        // Single source of truth for the user.status → presentation
        // mapping. The same theme tokens power the verification pill on
        // the dashboard and inside the Details popup — see
        // User::statusTheme(). $node->user is non-null by schema
        // (distributors.user_id NOT NULL with an FK to users.id).
        $theme = $node->user->statusTheme();
    @endphp
    <div data-node-adn="{{ $node->adn }}" data-node-id="{{ $node->id }}"
        class="relative rounded-xl border {{ $theme['border'] }} {{ $theme['bg'] }} {{ $isSelf ? 'ring-2 ring-brand-300' : '' }} px-2 py-2 text-center min-w-[150px] max-w-[168px] shadow-sm transition-shadow">
        {{-- Status dot moved to top-LEFT so the 3-dots "more actions" menu
             can occupy the top-RIGHT corner, which is the conventional
             location and where the user expects it. Hovering the dot reveals
             a styled popover with the human status label (Active / New Member
             / Suspended / Rejected / Closed) — see User::statusTheme(). --}}
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
            // The "show only this person's tree" pivot URL.
            // Admin context: /admin/tree/{id}; distributor context: /tree/{adn}.
            // Plain <a href>'s create real history entries — no JS routing needed
            // for the "tracked in browser history" requirement.
            $pivotUrl = $adminContext
                ? route('admin.tree.show', $node->id)
                : route('tree.binary', $node->adn);
        @endphp
        <div class="absolute top-1.5 right-1.5" data-node-menu>
            <button type="button" data-node-menu-trigger
                onclick="event.stopPropagation(); toggleNodeMenu(this);"
                title="More actions"
                class="w-4 h-4 inline-flex items-center justify-center rounded text-gray-600 hover:text-brand-800 hover:bg-white/80 transition-colors leading-none">
                <x-lucide-ellipsis-vertical class="w-3.5 h-3.5" />
            </button>
            {{-- right-0 anchors the panel to the right edge of the trigger so
                 it opens leftward, keeping it inside the card / viewport
                 instead of clipping past the right boundary. --}}
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
                        <span class="block text-[11px] text-gray-600 mt-0.5">Hide siblings and parent; root here</span>
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
                {{-- Impersonate: only inside admin context, only when the
                     target is not the currently-logged-in admin (defensive
                     — admins don't have a distributor row, but the guard
                     mirrors the one on the admin distributor profile page),
                     and only when the node has a user_id (some legacy /
                     synthetic rows might not). --}}
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

        {{-- Compact 6-field summary (Name + ID already shown above as
             the card header; the panel here is the remaining 6 from the
             8-field spec). --}}
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
    </div>

    @if($renderInlineChildren)
        {{-- Children row. Connectors:
             container::before — vertical from parent's bottom down to the horizontal (h-4 ends EXACTLY at the horizontal)
             container::after  — horizontal at top-4
             column::before    — vertical from horizontal down to each child card --}}
        <div class="relative pt-6 grid grid-cols-2 gap-0 w-full
            before:content-[''] before:absolute before:top-0 before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-3 before:bg-slate-500
            after:content-[''] after:absolute after:top-3 after:left-1/4 after:right-1/4 after:h-[2px] after:bg-slate-500">

            {{-- px-{N} = horizontal padding around each child subtree. The
                 grid is recursive, so this padding cascades: leaves at the
                 deepest level get 2*N px between siblings; upper levels
                 inherit additional whitespace because every ancestor wraps
                 its children with the same padding, so two adjacent subtrees
                 are separated by their own padding PLUS their children's
                 padding all the way down. px-3 (12px each side, 24px gap)
                 is the minimum that keeps the densest level (16 leaves at
                 depth 4) from touching at 100% zoom. --}}
            <div class="relative pt-3 flex justify-center {{ $childPad }}
                before:content-[''] before:absolute before:top-[-0.75rem] before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-6 before:bg-slate-500">
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

            {{-- px-{N} = horizontal padding around each child subtree. The
                 grid is recursive, so this padding cascades: leaves at the
                 deepest level get 2*N px between siblings; upper levels
                 inherit additional whitespace because every ancestor wraps
                 its children with the same padding, so two adjacent subtrees
                 are separated by their own padding PLUS their children's
                 padding all the way down. px-3 (12px each side, 24px gap)
                 is the minimum that keeps the densest level (16 leaves at
                 depth 4) from touching at 100% zoom. --}}
            <div class="relative pt-3 flex justify-center {{ $childPad }}
                before:content-[''] before:absolute before:top-[-0.75rem] before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-6 before:bg-slate-500">
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
                    class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-[11px] text-gray-700 bg-gray-50/50 transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-800 min-w-[110px]">
                    <span class="block">Invite (left)</span>
                    <span class="block text-[11px] text-gray-600 mt-0.5">click to invite</span>
                </button>
                <button type="button"
                    data-invite-parent="{{ $node->adn }}"
                    data-invite-side="R"
                    data-invite-side-label="right"
                    onclick="openInviteModal(this)"
                    class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-[11px] text-gray-700 bg-gray-50/50 transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-800 min-w-[110px]">
                    <span class="block">Invite (right)</span>
                    <span class="block text-[11px] text-gray-600 mt-0.5">click to invite</span>
                </button>
            </div>
        </div>
    @elseif($showMoreBelow)
        <div class="mt-2 text-[11px] text-gray-600 italic">
            ↓ more below — increase depth filter to expand
        </div>
    @endif
</div>
