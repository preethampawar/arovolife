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
                class="w-4 h-4 inline-flex items-center justify-center rounded text-gray-500 hover:text-brand-700 hover:bg-white/80 transition-colors leading-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z"/>
                </svg>
            </button>
            {{-- right-0 anchors the panel to the right edge of the trigger so
                 it opens leftward, keeping it inside the card / viewport
                 instead of clipping past the right boundary. --}}
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
                        <span class="block text-[11px] text-gray-600 mt-0.5">Hide siblings and parent; root here</span>
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

        {{-- Compact 6-field summary (Name + ID already shown above as
             the card header; the panel here is the remaining 6 from the
             8-field spec). Phase-2+ placeholders render as `—`. --}}
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
                    class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-[11px] text-gray-700 bg-gray-50/50 transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700 min-w-[110px]">
                    <span class="block">Invite (left)</span>
                    <span class="block text-[11px] text-gray-600 mt-0.5">click to invite</span>
                </button>
                <button type="button"
                    data-invite-parent="{{ $node->adn }}"
                    data-invite-side="R"
                    data-invite-side-label="right"
                    onclick="openInviteModal(this)"
                    class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-[11px] text-gray-700 bg-gray-50/50 transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700 min-w-[110px]">
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
