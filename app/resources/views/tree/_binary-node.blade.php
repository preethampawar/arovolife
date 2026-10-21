@php
    $adminContext = $adminContext ?? false;
    $left  = $childByParentSide[$node->id]['L'] ?? null;
    $right = $childByParentSide[$node->id]['R'] ?? null;
    $isSelf = isset($viewerId) && (int) $viewerId === (int) $node->id;
    $title = $isSelf
        ? 'You'
        : ($node->placement_side === 'L'
            ? 'Left Genos'
            : ($node->placement_side === 'R' ? 'Right Genos' : 'Root'));

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

    // Which side of its parent this node hangs on (null for the canvas
    // root) and the parent's column padding, passed down by the parent so
    // a branching child can draw its own half of the parent's connector.
    $branchSide = $branchSide ?? null;
    $parentPad  = $parentPad ?? null;

    // A node whose children are drawn inline is laid out as two
    // content-sized columns with its card centred on the line between
    // them. Equal-width halves made every level double the width of the
    // longest leg below it, so a 19-level leg needed a canvas far wider
    // than the browser can lay out, and the cards piled on top of each
    // other.
    $branching = $renderInlineChildren;

    // The connector stub a branching child draws reaches across its
    // parent's column padding to meet the sibling's half at the junction.
    // Static literals so Tailwind's JIT picks them up.
    $stubReach = match ($parentPad) {
        'px-0.5' => $branchSide === 'L' ? '-right-0.5' : '-left-0.5',
        'px-1'   => $branchSide === 'L' ? '-right-1' : '-left-1',
        'px-2'   => $branchSide === 'L' ? '-right-2' : '-left-2',
        'px-3'   => $branchSide === 'L' ? '-right-3' : '-left-3',
        'px-4'   => $branchSide === 'L' ? '-right-4' : '-left-4',
        default  => '',
    };

    // A child column draws the connector itself when its child is a single
    // card (a leaf or an empty slot), whose centre is the column's centre.
    $leftBranches  = $left !== null && $level + 1 < $maxDepth;
    $rightBranches = $right !== null && $level + 1 < $maxDepth;

    // Rank + personal BV for this card, resolved for the whole canvas at once
    // in _content.blade.php (R-65). Null rows render "—".
    $cardStats = $cardStatsById[$node->id] ?? null;
@endphp

@if($branching)
{{-- content-start: a shorter subtree is stretched to its taller sibling's
     height; keep that slack below the children instead of in the card row. --}}
<div class="grid grid-cols-[minmax(5.5rem,max-content)_minmax(5.5rem,max-content)] content-start">
    {{-- This node's half of its parent's horizontal connector: from the
         junction above its card out to the edge of the parent's column. --}}
    @if($branchSide === 'L')
        <span class="row-start-1 col-start-2 relative" aria-hidden="true"><span class="absolute top-0 left-0 {{ $stubReach }} h-[2px] bg-slate-500"></span></span>
    @elseif($branchSide === 'R')
        <span class="row-start-1 col-start-1 relative" aria-hidden="true"><span class="absolute top-0 right-0 {{ $stubReach }} h-[2px] bg-slate-500"></span></span>
    @endif
    {{-- Zero-width anchor on the line between the two columns; the card
         overflows it equally on both sides, so it sits centred over the
         junction. Each column is at least 5.5rem, half the widest card, so
         the overflow never reaches a neighbouring subtree. --}}
    <div class="row-start-1 col-start-1 justify-self-end w-0 flex flex-col items-center relative pb-3 {{ $branchSide ? 'pt-5' : '' }}
        {{ $branchSide ? "before:content-[''] before:absolute before:top-0 before:left-0 before:-translate-x-1/2 before:w-[2px] before:h-5 before:bg-slate-500" : '' }}
        after:content-[''] after:absolute after:bottom-0 after:left-0 after:-translate-x-1/2 after:w-[2px] after:h-3 after:bg-slate-500">
@else
<div class="flex flex-col items-center {{ $showLeafHoverEmpties ? 'relative' : '' }}"
    @if($showLeafHoverEmpties) data-leaf-wrapper @endif>
@endif
    @php
        // Single source of truth for the user.status → presentation
        // mapping. The same theme tokens power the verification pill on
        // the dashboard and inside the Details popup — see
        // User::statusTheme(). $node->user is non-null by schema
        // (distributors.user_id NOT NULL with an FK to users.id).
        $theme = $node->user->statusTheme();
    @endphp
    <div data-node-adn="{{ $node->adn }}" data-node-id="{{ $node->id }}" data-node-card
        class="relative rounded-xl border {{ $theme['border'] }} {{ $theme['bg'] }} {{ $isSelf ? 'ring-2 ring-brand-300' : '' }} px-2 py-2 text-center w-max min-w-[150px] max-w-[168px] shadow-sm transition-shadow">
        @include('tree._purchase-mark', ['state' => $cardStats['purchase_state'] ?? null])
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
                @if($node->user_id && auth()->id() !== (int) $node->user_id && ($messagingOn ?? false))
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

    @if($branching)
    </div>{{-- /anchor --}}

    {{-- Children row. Connectors: the anchor's ::after drops from the card
         to the junction; a leaf column draws its own half of the horizontal
         (::after) and the drop to its card (::before); a branching child
         draws both itself (stub + anchor ::before). px-{N} = horizontal
         padding around each child subtree, deeper levels get more. --}}
    @foreach(['L' => $left, 'R' => $right] as $childSide => $child)
        @php $childBranches = $childSide === 'L' ? $leftBranches : $rightBranches; @endphp
        <div class="row-start-2 {{ $childSide === 'L' ? 'col-start-1' : 'col-start-2' }} relative flex items-start justify-center {{ $childPad }}
            {{ $childBranches ? '' : "pt-5 before:content-[''] before:absolute before:top-0 before:left-1/2 before:-translate-x-1/2 before:w-[2px] before:h-5 before:bg-slate-500 after:content-[''] after:absolute after:top-0 after:h-[2px] after:bg-slate-500" }}
            {{ $childBranches ? '' : ($childSide === 'L' ? 'after:left-1/2 after:right-0' : 'after:left-0 after:right-1/2') }}">
            @if($child)
                @include('tree._binary-node', [
                    'node'              => $child,
                    'level'             => $level + 1,
                    'maxDepth'          => $maxDepth,
                    'childByParentSide' => $childByParentSide,
                    'adminContext'      => $adminContext,
                    'branchSide'        => $childSide,
                    'parentPad'         => $childPad,
                ])
            @else
                @include('tree._empty-slot', ['parent' => $node, 'side' => $childSide])
            @endif
        </div>
    @endforeach
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
