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
@elseif($distributor)
<div class="mb-6">
    <a href="{{ route('dashboard.documents') }}" class="inline-flex items-center text-sm text-brand-600 font-medium hover:underline">
        Manage my KYC documents →
    </a>
</div>
@endif

<div class="mb-8">
    <h1 class="text-2xl font-bold mb-1">Welcome, {{ $user->full_name ?? $user->email }}</h1>
    <p class="text-gray-800 text-sm">
        Status:
        @php
            $accountStatus = $user->accountStatusLabel();
        @endphp
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border {{ $accountStatus['class'] }}">
            {{ $accountStatus['label'] }}
        </span>
    </p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

    @if($distributor)
    {{-- ── Expanded ADN card — spans 2/3 on lg, stats + ID photo side by side. --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 md:col-span-2 lg:col-span-2">
        <div class="flex items-baseline justify-between mb-4 gap-3">
            <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Your ADN</p>
            <span class="font-mono font-bold text-brand-600 tracking-widest text-base">{{ $distributor->adn }}</span>
        </div>

        <a href="{{ route('membership-card.show') }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 mb-4 px-3 py-1.5 rounded-lg border border-brand-300 bg-brand-50 hover:bg-brand-100 text-brand-700 text-xs font-semibold transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8.25V18a2.25 2.25 0 0 0 2.25 2.25h13.5A2.25 2.25 0 0 0 21 18V8.25m-18 0V6a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 6v2.25m-18 0h18M5.25 12h6m-6 3h3"/></svg>
            View &amp; print membership card
        </a>

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
                    <span class="font-medium">{{ $distributor->placement_side === 'L' ? '← Left' : '→ Right' }} leg</span>
                </div>
            </div>
            <div class="mt-4 flex flex-col gap-1.5 text-xs">
                <a href="{{ route('tree.binary') }}" class="text-brand-600 hover:text-brand-700 underline">View my binary tree →</a>
                <a href="{{ route('tree.sponsorship') }}" class="text-brand-600 hover:text-brand-700 underline">View my direct referrals →</a>
                @if((int) $distributor->effective_date->diffInWeekdays(now()) <= 5)
                <a href="{{ route('line-change.show') }}" class="text-brand-600 hover:text-brand-700 underline">Request line-change →</a>
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

    {{-- ── My Team — genealogy + status summary ─────────────────────── --}}
    @if($teamStats !== null)
    <div class="bg-white rounded-2xl border border-gray-200 p-6 col-span-full">
        <div class="flex items-baseline justify-between mb-4 gap-3 flex-wrap">
            <div>
                <p class="text-xs text-gray-700 uppercase tracking-wider mb-1 font-semibold">My Team</p>
                <p class="text-sm text-gray-800">A live view of your binary downline and direct referrals.</p>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <a href="{{ route('tree.binary') }}" class="text-brand-600 hover:text-brand-700 underline">Binary tree →</a>
                <span class="text-gray-500">·</span>
                <a href="{{ route('tree.sponsorship') }}" class="text-brand-600 hover:text-brand-700 underline">Direct referrals →</a>
            </div>
        </div>

        {{-- Top row: the four headline numbers --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="rounded-xl border border-brand-200 bg-brand-50/60 p-4">
                <p class="text-[11px] text-brand-700 uppercase tracking-wider font-semibold mb-1">Total team</p>
                <p class="text-3xl font-bold text-brand-700 leading-none">{{ number_format($teamStats['total_team']) }}</p>
                <p class="text-[11px] text-gray-700 mt-1.5">members in your binary downline</p>
            </div>
            <div class="rounded-xl border border-leaf-200 bg-leaf-50/60 p-4">
                <p class="text-[11px] text-leaf-700 uppercase tracking-wider font-semibold mb-1">Direct referrals</p>
                <p class="text-3xl font-bold text-leaf-700 leading-none">{{ number_format($teamStats['direct_referrals']) }}</p>
                <p class="text-[11px] text-gray-700 mt-1.5">people you personally invited</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="text-[11px] text-gray-700 uppercase tracking-wider font-semibold mb-1">← Left team</p>
                <p class="text-3xl font-bold text-gray-900 leading-none">{{ number_format($teamStats['left_team']) }}</p>
                <p class="text-[11px] text-gray-700 mt-1.5">members under your left leg</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="text-[11px] text-gray-700 uppercase tracking-wider font-semibold mb-1">Right team →</p>
                <p class="text-3xl font-bold text-gray-900 leading-none">{{ number_format($teamStats['right_team']) }}</p>
                <p class="text-[11px] text-gray-700 mt-1.5">members under your right leg</p>
            </div>
        </div>

        {{-- Status breakdown row --}}
        @php
            $statuses = [
                ['key' => 'active',     'label' => 'Active',     'count' => $teamStats['active'],     'cls' => 'bg-green-50 text-green-700 border-green-200',     'dot' => 'bg-green-500'],
                ['key' => 'pending',    'label' => 'Pending',    'count' => $teamStats['pending'],    'cls' => 'bg-amber-50 text-amber-700 border-amber-200',     'dot' => 'bg-amber-500'],
                ['key' => 'frozen',     'label' => 'Blocked',    'count' => $teamStats['frozen'],     'cls' => 'bg-sunrise-50 text-sunrise-800 border-sunrise-200', 'dot' => 'bg-sunrise-500'],
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
    @endif

    {{-- My Referral Link — sponsor-only.
         The dashboard link ships JUST the sponsor ADN; the prospect picks
         their own placement on the /join page (where both sponsor and
         placement get resolved to friendly names before submission).
         Tree-view "invite at this leaf" links keep their full
         sponsor+placement+side encoding — that's a different flow. --}}
    @php
        $inviteUrl = url('/join').'?sponsor='.$distributor->adn;
        $bothFull  = ! $leftOpen && ! $rightOpen;
    @endphp
    <div class="bg-white rounded-2xl border border-brand-200 p-6 col-span-full md:col-span-2 lg:col-span-3">
        <div class="flex items-start justify-between mb-3 gap-4">
            <div>
                <p class="text-xs text-brand-700 uppercase tracking-wider mb-1 font-semibold">My Referral Link</p>
                <p class="text-sm text-gray-800">
                    Share this with anyone you invite. They open the page, see your
                    name as the sponsor, and pick the placement ADN themselves.
                </p>
            </div>
            <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-brand-50 text-brand-700 border border-brand-200">Personal invite</span>
        </div>

        <div class="space-y-2">
            <div class="flex items-stretch gap-2">
                <input type="text" readonly value="{{ $inviteUrl }}"
                    class="flex-1 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-xs font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    onclick="this.select()">
                <button type="button"
                    onclick="navigator.clipboard.writeText('{{ $inviteUrl }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);"
                    class="px-4 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold transition-colors">
                    Copy
                </button>
            </div>

            @if($bothFull)
                <div class="flex items-center gap-2 pt-1 text-[11px] text-sunrise-700">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-sunrise-50 border border-sunrise-200 font-semibold">
                        <span class="w-1.5 h-1.5 rounded-full bg-sunrise-500"></span>Your direct slots are full
                    </span>
                    <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}"
                        class="ml-auto text-brand-600 hover:text-brand-700 underline-offset-2 hover:underline">
                        Invite at a specific deeper slot — open my tree →
                    </a>
                </div>
            @else
                <p class="pt-1 text-[11px] text-gray-700">
                    Want to drop someone at a specific deeper slot instead?
                    <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}" class="text-brand-600 hover:text-brand-700 underline-offset-2 hover:underline">Open my tree →</a>
                </p>
            @endif
        </div>
    </div>

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
