@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')

@if(session('adn_issued'))
<div class="mb-8 rounded-2xl border border-green-200 bg-green-50 p-6">
    <h3 class="text-lg font-bold text-green-700 mb-1">Registration submitted</h3>
    <p class="text-sm text-green-700">
        Welcome to arovolife. Your Distributor Number (ADN) has been issued and your
        30-day cooling-off period begins today. Your KYC documents are under review by
        an admin - you will be notified once approved.
    </p>
</div>
@endif

@if($distributor && $user->status === 'pending')
<div class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 p-6">
    <h3 class="text-base font-semibold text-amber-800 mb-1">KYC under review</h3>
    <p class="text-sm text-amber-800 mb-3">
        An admin is reviewing the PAN, Aadhaar, bank, and address-proof documents you uploaded.
        Most reviews complete within 1–2 business days. You will receive an email when the
        review is done.
    </p>
    <a href="{{ route('dashboard.documents') }}" class="inline-flex items-center text-sm text-amber-900 font-semibold underline hover:no-underline">
        Add or replace documents →
    </a>
</div>
@endif

@php
    $accountStatus = $user->accountStatusLabel();
    $hasDistributorBlock = $distributor !== null;
    $inviteUrl = $hasDistributorBlock ? url('/join').'?sponsor='.$distributor->adn : null;
    $bothFull  = $hasDistributorBlock ? (! $leftOpen && ! $rightOpen) : false;
@endphp

<div class="mb-8 grid grid-cols-1 md:grid-cols-2 md:items-start gap-4">
    <div>
        @if($hasDistributorBlock)
            <a href="{{ route('dashboard.documents') }}" class="inline-flex items-center text-sm text-brand-600 font-medium hover:underline mb-3">
                Manage my KYC documents →
            </a>
        @endif
        <h1 class="text-2xl font-bold mb-1">Welcome, {{ $user->full_name ?? $user->email }}</h1>
        <p class="text-gray-800 text-sm">
            Status:
            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border {{ $accountStatus['class'] }}">
                {{ $accountStatus['label'] }}
            </span>
        </p>
    </div>

    @if($hasDistributorBlock)
        {{-- Compact referral-link card. Same source as the full panel below
             used to live in — moved here so distributors always see their
             invite URL at the very top of the dashboard. --}}
        <div class="w-full rounded-xl border border-brand-200 bg-brand-50/60 p-4">
            <div class="flex items-center justify-between gap-3 mb-2">
                <p class="text-[11px] text-brand-700 uppercase tracking-wider font-semibold">My Referral Link</p>
                <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-white text-brand-700 border border-brand-200">Personal invite</span>
            </div>
            <div class="flex items-stretch gap-2">
                <input type="text" readonly value="{{ $inviteUrl }}"
                    class="flex-1 min-w-0 rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    onclick="this.select()">
                <button type="button"
                    onclick="navigator.clipboard.writeText('{{ $inviteUrl }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);"
                    class="px-3 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold transition-colors">
                    Copy
                </button>
            </div>
            @if($bothFull)
                <p class="mt-2 text-[11px] text-sunrise-700">
                    <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-sunrise-500"></span>Direct slots full.</span>
                    <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}" class="text-brand-600 hover:text-brand-700 underline-offset-2 hover:underline">Open my tree →</a>
                </p>
            @else
                <p class="mt-2 text-[11px] text-gray-700">
                    Want a specific deeper slot?
                    <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}" class="text-brand-600 hover:text-brand-700 underline-offset-2 hover:underline">Open my tree →</a>
                </p>
            @endif
        </div>
    @endif
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

    @if($distributor)
    {{-- ── Expanded ADN card — spans 2/3 on lg, stats + ID photo side by side. --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 md:col-span-2 lg:col-span-2">
        <div class="flex items-center justify-between gap-3 mb-4">
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Profile Stats</p>
            <a href="{{ route('profile-stats.show') }}" target="_blank" rel="noopener"
                title="Open a printable copy of your profile stats — choose Save as PDF in the print dialog"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-xs font-medium text-gray-700 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                Download PDF
            </a>
        </div>
        @include('partials._id-card-panel', [
            'idCardStats' => $idCardStats,
            'idPhotoUrl'  => $idPhotoUrl,
            'readonly'    => false,
        ])
    </div>

    {{-- ── Right column on lg: Placement (top) + Cooling-Off (bottom) ──── --}}
    <div class="flex flex-col gap-6">
        {{-- Placement --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <p class="text-xs text-gray-700 uppercase tracking-wider mb-3 font-semibold">Placement</p>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-800">Position</span>
                    <span class="font-medium">{{ $distributor->placement_side === 'L' ? '← Left' : '→ Right' }} group</span>
                </div>
            </div>
            @php
                // Line-change is a one-shot, 5-business-day window from
                // effective_date (mirrors LineChangeController::show + the
                // service-side guard in RequestLineChange).
                $lcBusinessDaysSince = (int) $distributor->effective_date->diffInWeekdays(now());
                $lcRemaining         = max(0, 5 - $lcBusinessDaysSince);
                $lcAvailable         = $lcBusinessDaysSince <= 5;
            @endphp
            <div class="mt-4 flex flex-col gap-1.5 text-xs">
                <a href="{{ route('tree.binary') }}" class="text-brand-600 hover:text-brand-700 underline">My Genos →</a>
                <a href="{{ route('tree.sponsorship') }}" class="text-brand-600 hover:text-brand-700 underline">My Referrals →</a>
                <a href="{{ route('orders.index') }}" class="text-brand-600 hover:text-brand-700 underline">My Orders →</a>
                @if($lcAvailable)
                    <a href="{{ route('line-change.show') }}" class="text-brand-600 hover:text-brand-700 underline">
                        Request line-change →
                        <span class="text-gray-500 font-normal">({{ $lcRemaining }} {{ $lcRemaining === 1 ? 'day' : 'days' }} left)</span>
                    </a>
                @else
                    <span class="text-gray-400 cursor-not-allowed line-through"
                          title="The 5-business-day line-change window has ended.">
                        Request line-change
                    </span>
                @endif
            </div>
        </div>

        {{-- Cooling-Off --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <p class="text-xs text-gray-700 uppercase tracking-wider mb-2 font-semibold">Cooling-Off Period</p>
            @php
                $daysLeft = now()->diffInDays($distributor->cooling_off_end_at, false);
                $isActive = $daysLeft > 0;
            @endphp
            @if($isActive)
            <p class="text-2xl font-bold {{ $daysLeft <= 7 ? 'text-red-700' : 'text-amber-700' }}">
                {{ max(0, (int) $daysLeft) }} days
            </p>
            <p class="text-xs text-gray-700 mt-1">remaining (ends {{ $distributor->cooling_off_end_at->format('d M Y') }})</p>
            <p class="text-xs text-gray-700 mt-3">You may cancel your registration during this window.</p>
            <a href="{{ route('cooling-off.show') }}" class="inline-block mt-3 text-xs text-red-600 hover:text-red-700 underline">
                Cancel registration →
            </a>
            @else
            <p class="text-sm text-gray-800">Cooling-off period expired</p>
            @endif
        </div>

        {{-- Messages —— mirrors the topnav bell. Same unread-count source
             (Message::unreadFor), so the badge here and the badge in the
             top-right corner always agree. --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center justify-between gap-2 mb-2">
                <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Messages</p>
                @if($unreadMessagesCount > 0)
                    <span class="inline-flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full bg-brand-500 text-white text-[10px] font-bold leading-none">
                        {{ $unreadMessagesCount > 99 ? '99+' : $unreadMessagesCount }}
                    </span>
                @endif
            </div>

            @if($latestMessage)
                @php
                    $senderName = $latestMessage->fromUser?->full_name
                        ?: ($latestMessage->fromUser?->email ?: 'Unknown sender');
                    $isUnread = $latestMessage->read_at === null;
                @endphp
                <a href="{{ route('messages.show', ['user' => $latestMessage->from_user_id]) }}"
                    class="block group">
                    <p class="text-sm font-semibold text-gray-900 group-hover:text-brand-700 truncate {{ $isUnread ? 'text-brand-700' : '' }}">
                        {{ $senderName }}
                    </p>
                    <p class="text-xs text-gray-800 line-clamp-2 mt-1">{{ $latestMessage->body }}</p>
                    <p class="text-[11px] text-gray-600 mt-1.5">{{ $latestMessage->created_at->diffForHumans() }}</p>
                </a>
                <a href="{{ route('messages.index') }}"
                    class="inline-flex items-center gap-1 mt-3 text-xs text-brand-600 hover:text-brand-700 font-medium">
                    View all messages
                    <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5 15.75 12l-7.5 7.5"/>
                    </svg>
                </a>
            @else
                <p class="text-sm text-gray-700">No messages yet.</p>
                <p class="text-xs text-gray-600 mt-1">Open a card in the tree view and click "Send Message" to start a conversation.</p>
                <a href="{{ route('messages.index') }}"
                    class="inline-flex items-center gap-1 mt-3 text-xs text-brand-600 hover:text-brand-700 font-medium">
                    Open inbox
                    <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5 15.75 12l-7.5 7.5"/>
                    </svg>
                </a>
            @endif
        </div>
    </div>

    {{-- ── Documents — quick-access cards to printable / downloadable assets.
         Only Membership Card is implemented today; the others are Phase 4+
         placeholders, rendered as disabled "Coming soon" so the surface is
         discoverable but the missing wiring is honest. --}}
    <div class="col-span-full">
        <div class="flex items-baseline justify-between mb-3">
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Documents</p>
        </div>

        @php
            $docs = [
                [
                    'title'    => 'arovolife Direct Seller Application',
                    'desc'     => 'Your registration details on file with arovolife — view, print or save as PDF.',
                    'url'      => route('direct-seller-application.show'),
                    'accent'   => 'border-leaf-500',
                    'tile_bg'  => 'bg-leaf-50',
                    'tile_txt' => 'text-leaf-700',
                    'svg'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z"/>',
                ],
                [
                    'title'    => 'Membership Card',
                    'desc'     => 'View, print or download your front-and-back arovolife ID card.',
                    'url'      => route('membership-card.show'),
                    'accent'   => 'border-brand-500',
                    'tile_bg'  => 'bg-brand-50',
                    'tile_txt' => 'text-brand-700',
                    'svg'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 8.25V18a2.25 2.25 0 0 0 2.25 2.25h13.5A2.25 2.25 0 0 0 21 18V8.25m-18 0V6a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 6v2.25m-18 0h18M5.25 12h6m-6 3h3"/>',
                ],
                [
                    'title'    => 'TDS (Tax Statements)',
                    'desc'     => 'Quarterly TDS certificates and the annual Form 26AS reconciliation.',
                    'url'      => route('tax-statements.show'),
                    'accent'   => 'border-amber-500',
                    'tile_bg'  => 'bg-amber-50',
                    'tile_txt' => 'text-amber-700',
                    'svg'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941"/>',
                ],
            ];
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($docs as $doc)
                @if($doc['url'])
                    <a href="{{ $doc['url'] }}" target="_blank" rel="noopener"
                       class="group block rounded-2xl bg-white shadow-sm hover:shadow-lg p-5 border-t-4 {{ $doc['accent'] }} transition-all duration-300 hover:-translate-y-0.5">
                        <div class="flex items-start gap-3 mb-3">
                            <span class="shrink-0 w-10 h-10 rounded-lg {{ $doc['tile_bg'] }} {{ $doc['tile_txt'] }} flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">{!! $doc['svg'] !!}</svg>
                            </span>
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 leading-snug">{{ $doc['title'] }}</p>
                            </div>
                        </div>
                        <p class="text-xs text-gray-600 leading-relaxed mb-3">{{ $doc['desc'] }}</p>
                        <p class="text-xs font-semibold text-brand-700 group-hover:translate-x-0.5 transition-transform inline-flex items-center gap-1">
                            Open →
                        </p>
                    </a>
                @else
                    <div class="block rounded-2xl bg-white shadow-sm p-5 border-t-4 {{ $doc['accent'] }} opacity-80">
                        <div class="flex items-start gap-3 mb-3">
                            <span class="shrink-0 w-10 h-10 rounded-lg {{ $doc['tile_bg'] }} {{ $doc['tile_txt'] }} flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">{!! $doc['svg'] !!}</svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900 leading-snug">{{ $doc['title'] }}</p>
                            </div>
                            <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-gray-100 text-gray-600 border border-gray-200">
                                Coming soon
                            </span>
                        </div>
                        <p class="text-xs text-gray-600 leading-relaxed">{{ $doc['desc'] }}</p>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- ── My Team — genealogy + status summary ─────────────────────── --}}
    @if($teamStats !== null)
    <div class="bg-white rounded-2xl border border-gray-200 p-6 col-span-full">
        <div class="flex items-baseline justify-between mb-4 gap-3 flex-wrap">
            <div>
                <p class="text-xs text-gray-700 uppercase tracking-wider mb-1 font-semibold">My Team</p>
                <p class="text-sm text-gray-800">A live view of your Genos downline and direct referrals.</p>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <a href="{{ route('tree.binary') }}" class="text-brand-600 hover:text-brand-700 underline">Genos →</a>
                <span class="text-gray-500">·</span>
                <a href="{{ route('tree.sponsorship') }}" class="text-brand-600 hover:text-brand-700 underline">Direct referrals →</a>
            </div>
        </div>

        {{-- Top row: the four headline numbers. Each card is a button —
             clicking it opens a modal with the underlying roster (S.No, ADN,
             name, state, status) and a Download CSV button. JSON +
             CSV come from TeamRosterController. --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <button type="button" data-team-roster="total"
                class="text-left rounded-xl border border-brand-200 bg-brand-50/60 p-4 hover:bg-brand-50 hover:border-brand-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-brand-500">
                <p class="text-[11px] text-brand-700 uppercase tracking-wider font-semibold mb-1">Total team</p>
                <p class="text-3xl font-bold text-brand-700 leading-none">{{ number_format($teamStats['total_team']) }}</p>
                <p class="text-[11px] text-gray-700 mt-1.5">members in your Genos downline</p>
            </button>
            <button type="button" data-team-roster="direct"
                class="text-left rounded-xl border border-leaf-200 bg-leaf-50/60 p-4 hover:bg-leaf-50 hover:border-leaf-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-leaf-500">
                <p class="text-[11px] text-leaf-700 uppercase tracking-wider font-semibold mb-1">Direct referrals</p>
                <p class="text-3xl font-bold text-leaf-700 leading-none">{{ number_format($teamStats['direct_referrals']) }}</p>
                <p class="text-[11px] text-gray-700 mt-1.5">people you personally invited</p>
            </button>
            <button type="button" data-team-roster="left"
                class="text-left rounded-xl border border-sky-200 bg-sky-50/60 p-4 hover:bg-sky-50 hover:border-sky-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-sky-500">
                <p class="text-[11px] text-sky-700 uppercase tracking-wider font-semibold mb-1">← Left team</p>
                <p class="text-3xl font-bold text-sky-700 leading-none">{{ number_format($teamStats['left_team']) }}</p>
                <p class="text-[11px] text-gray-700 mt-1.5">members under your left group</p>
            </button>
            <button type="button" data-team-roster="right"
                class="text-left rounded-xl border border-indigo-200 bg-indigo-50/60 p-4 hover:bg-indigo-50 hover:border-indigo-300 hover:shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <p class="text-[11px] text-indigo-700 uppercase tracking-wider font-semibold mb-1">Right team →</p>
                <p class="text-3xl font-bold text-indigo-700 leading-none">{{ number_format($teamStats['right_team']) }}</p>
                <p class="text-[11px] text-gray-700 mt-1.5">members under your right group</p>
            </button>
        </div>

        {{-- Status breakdown row --}}
        @php
            $statuses = [
                ['key' => 'active',     'label' => 'Active',     'count' => $teamStats['active'],     'cls' => 'bg-green-50 text-green-700 border-green-200',     'dot' => 'bg-green-500'],
                ['key' => 'pending',    'label' => 'Pending',    'count' => $teamStats['pending'],    'cls' => 'bg-amber-50 text-amber-700 border-amber-200',     'dot' => 'bg-amber-500'],
                ['key' => 'frozen',     'label' => 'Blocked',    'count' => $teamStats['frozen'],     'cls' => 'bg-red-50 text-red-700 border-red-200', 'dot' => 'bg-red-500'],
                ['key' => 'terminated', 'label' => 'Inactive',   'count' => $teamStats['terminated'], 'cls' => 'bg-gray-100 text-gray-600 border-gray-200',       'dot' => 'bg-gray-400'],
            ];
        @endphp
        <div class="border-t border-gray-100 pt-4">
            <p class="text-[11px] text-gray-700 uppercase tracking-wider font-semibold mb-3">By status</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                @foreach($statuses as $s)
                    <div class="flex items-center justify-between gap-3 rounded-lg border {{ $s['cls'] }} px-3 py-2.5">
                        <span class="inline-flex items-center gap-2 text-xs font-semibold">
                            <span class="w-2 h-2 rounded-full {{ $s['dot'] }}"></span>
                            {{ $s['label'] }}
                        </span>
                        <span class="text-lg font-bold leading-none">{{ number_format($s['count']) }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Activity row --}}
        <div class="border-t border-gray-100 pt-4 mt-4 grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
            <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
                <span class="text-xs text-gray-800 font-medium">Registered this week</span>
                <span class="text-base font-bold text-gray-900">{{ number_format($teamStats['joined_this_week']) }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
                <span class="text-xs text-gray-800 font-medium">Registered this month</span>
                <span class="text-base font-bold text-gray-900">{{ number_format($teamStats['joined_this_month']) }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
                <span class="text-xs text-gray-800 font-medium">Cooling-off active</span>
                <span class="text-base font-bold text-gray-900">{{ number_format($teamStats['cooling_off']) }}</span>
            </div>
        </div>
    </div>

    {{-- Roster modal: shared by all four stat-card buttons. Populated on
         click via /dashboard/team-roster/{scope}; download button hits the
         CSV endpoint with the same scope. Uses a native <dialog> element so
         it always renders in the browser's top layer with a real ::backdrop
         — sidesteps any ancestor stacking-context / transform that would
         otherwise trap a div-based modal. --}}
    <style>
        dialog#team-roster-modal::backdrop { background: rgba(15, 23, 42, 0.6); }
        dialog#team-roster-modal {
            padding: 0;
            border: 0;
            background: transparent;
            width: 100%;
            height: 100%;
            max-width: 100%;
            max-height: 100%;
            margin: 0;
            inset: 0;
        }
        dialog#team-roster-modal[open] {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
    <dialog id="team-roster-modal">
        <div class="bg-white rounded-2xl w-[calc(100vw-2rem)] sm:w-full max-w-3xl flex flex-col shadow-2xl overflow-hidden" style="max-height: calc(100vh - 4rem);">
            <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200 shrink-0 bg-white">
                <div>
                    <p id="team-roster-title" class="text-base font-semibold text-gray-900">Team list</p>
                    <p id="team-roster-subtitle" class="text-xs text-gray-700 mt-0.5">—</p>
                </div>
                <div class="flex items-center gap-2">
                    <a id="team-roster-download" href="#"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                        Download CSV
                    </a>
                    <button type="button" id="team-roster-close"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto px-6 py-4 bg-white">
                <div id="team-roster-loading" class="hidden text-center text-sm text-gray-600 py-10">Loading…</div>
                <div id="team-roster-empty" class="hidden text-center text-sm text-gray-600 py-10">No members to show.</div>
                <table id="team-roster-table" class="hidden w-full text-sm">
                    <thead class="bg-gray-50 text-gray-700">
                        <tr>
                            <th class="text-left px-3 py-2 font-semibold w-14">S.No.</th>
                            <th class="text-left px-3 py-2 font-semibold">ADN No.</th>
                            <th class="text-left px-3 py-2 font-semibold">Name</th>
                            <th class="text-left px-3 py-2 font-semibold">State</th>
                            <th class="text-left px-3 py-2 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody id="team-roster-tbody" class="divide-y divide-gray-100"></tbody>
                </table>
            </div>
        </div>
    </dialog>

    <script>
    (function () {
        const modal      = document.getElementById('team-roster-modal');
        if (!modal) return;
        const titleEl    = document.getElementById('team-roster-title');
        const subEl      = document.getElementById('team-roster-subtitle');
        const dlEl       = document.getElementById('team-roster-download');
        const closeEl    = document.getElementById('team-roster-close');
        const loadingEl  = document.getElementById('team-roster-loading');
        const emptyEl    = document.getElementById('team-roster-empty');
        const tableEl    = document.getElementById('team-roster-table');
        const tbodyEl    = document.getElementById('team-roster-tbody');

        const STATUS_PILL = {
            'Active':   'bg-green-50 text-green-700 border-green-200',
            'Pending':  'bg-amber-50 text-amber-700 border-amber-200',
            'Blocked':  'bg-red-50 text-red-700 border-red-200',
            'Inactive': 'bg-gray-100 text-gray-600 border-gray-200',
            'Rejected': 'bg-amber-50 text-amber-700 border-amber-200',
        };

        const escapeHtml = (s) => String(s ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

        function openModal(scope) {
            loadingEl.classList.remove('hidden');
            emptyEl.classList.add('hidden');
            tableEl.classList.add('hidden');
            tbodyEl.innerHTML = '';
            titleEl.textContent = 'Loading…';
            subEl.textContent = '—';
            dlEl.setAttribute('href', `/dashboard/team-roster/${scope}/download`);
            modal.showModal();

            fetch(`/dashboard/team-roster/${scope}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject(r))
                .then(data => {
                    loadingEl.classList.add('hidden');
                    titleEl.textContent = data.label;
                    subEl.textContent   = `${data.rows.length} ${data.rows.length === 1 ? 'member' : 'members'}`;
                    if (data.rows.length === 0) {
                        emptyEl.classList.remove('hidden');
                        return;
                    }
                    tableEl.classList.remove('hidden');
                    tbodyEl.innerHTML = data.rows.map((row, i) => {
                        const cls = STATUS_PILL[row.status] || 'bg-gray-100 text-gray-600 border-gray-200';
                        return `<tr>
                            <td class="px-3 py-2 text-gray-700">${i + 1}</td>
                            <td class="px-3 py-2 font-mono text-gray-900">${escapeHtml(row.adn)}</td>
                            <td class="px-3 py-2 text-gray-900">${escapeHtml(row.name)}</td>
                            <td class="px-3 py-2 text-gray-800">${escapeHtml(row.state)}</td>
                            <td class="px-3 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${cls}">${escapeHtml(row.status)}</span></td>
                        </tr>`;
                    }).join('');
                })
                .catch(() => {
                    loadingEl.classList.add('hidden');
                    emptyEl.classList.remove('hidden');
                    emptyEl.textContent = 'Could not load the list. Please try again.';
                });
        }

        document.querySelectorAll('[data-team-roster]').forEach(btn => {
            btn.addEventListener('click', () => openModal(btn.getAttribute('data-team-roster')));
        });
        closeEl.addEventListener('click', () => modal.close());
        // Click outside the inner card (i.e. directly on the dialog element)
        // dismisses; Escape key is handled natively by <dialog>.
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });
    })();
    </script>
    @endif

    {{-- Referral link moved up to the page header — see the compact card
         beside the Welcome heading. --}}

    @else
    {{-- Registration incomplete --}}
    <div class="col-span-full bg-white rounded-2xl border border-amber-200 p-8 text-center">
        <p class="text-amber-700 font-semibold mb-2">Registration not yet complete</p>
        <p class="text-sm text-gray-800 mb-4">Complete your registration to receive your ADN.</p>
        <a href="{{ route('register.orientation') }}"
            class="inline-flex items-center gap-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-2.5 text-sm transition-colors">
            Continue Registration →
        </a>
    </div>
    @endif

</div>

{{-- Phase 1 notice --}}
<div class="mt-10 rounded-xl border border-gray-200 bg-white/50 p-4 text-xs text-gray-700">
    <strong class="text-gray-800">Phase 1 Platform</strong> —
    Product catalogue, orders, commissions and wallet features are coming in later phases.
    For support, email support@arovolife.com.
</div>

@endsection
