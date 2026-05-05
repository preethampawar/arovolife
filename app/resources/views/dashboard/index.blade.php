@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')

@if(session('adn_issued'))
<div class="mb-8 rounded-2xl border border-green-200 bg-green-50 p-6">
    <h3 class="text-lg font-bold text-green-700 mb-1">Registration submitted</h3>
    <p class="text-sm text-green-700">
        Welcome to arovolife. Your Distributor Number (ADN) has been issued and your
        30-day cooling-off period begins today. Your KYC documents are under review by
        an admin — you will be notified once approved.
    </p>
</div>
@endif

@if($distributor && $user->status === 'pending')
<div class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 p-6">
    <h3 class="text-base font-semibold text-amber-800 mb-1">KYC under review</h3>
    <p class="text-sm text-amber-800">
        An admin is reviewing the PAN, Aadhaar, bank, and address-proof documents you uploaded.
        Most reviews complete within 1–2 business days. You will receive an email when the
        review is done.
    </p>
</div>
@endif

<div class="mb-8">
    <h1 class="text-2xl font-bold mb-1">Welcome, {{ $user->full_name ?? $user->email }}</h1>
    <p class="text-gray-600 text-sm">
        Status:
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium
            {{ $user->status === 'active' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">
            {{ ucfirst($user->status) }}
        </span>
    </p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

    {{-- ADN Card --}}
    @if($distributor)
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <p class="text-xs text-gray-500 uppercase tracking-wider mb-2">Your ADN</p>
        <p class="text-3xl font-mono font-bold text-brand-600 tracking-widest">{{ $distributor->adn }}</p>
        <p class="text-xs text-gray-500 mt-2">arovolife Distributor Number</p>
    </div>

    {{-- Placement Info --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Placement</p>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-600">Position</span>
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
        <p class="text-xs text-gray-500 uppercase tracking-wider mb-2">Cooling-Off Period</p>
        @php
            $daysLeft = now()->diffInDays($distributor->cooling_off_end_at, false);
            $isActive = $daysLeft > 0;
        @endphp
        @if($isActive)
        <p class="text-2xl font-bold {{ $daysLeft <= 7 ? 'text-red-700' : 'text-amber-700' }}">
            {{ max(0, (int) $daysLeft) }} days
        </p>
        <p class="text-xs text-gray-500 mt-1">remaining (ends {{ $distributor->cooling_off_end_at->format('d M Y') }})</p>
        <p class="text-xs text-gray-500 mt-3">You may cancel your registration during this window.</p>
        <a href="{{ route('cooling-off.show') }}" class="inline-block mt-3 text-xs text-red-600 hover:text-red-700 underline">
            Cancel registration →
        </a>
        @else
        <p class="text-sm text-gray-600">Cooling-off period expired</p>
        @endif
    </div>

    {{-- ── My Team — genealogy + status summary ─────────────────────── --}}
    @if($teamStats !== null)
    <div class="bg-white rounded-2xl border border-gray-200 p-6 col-span-full">
        <div class="flex items-baseline justify-between mb-4 gap-3 flex-wrap">
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wider mb-1 font-semibold">My Team</p>
                <p class="text-sm text-gray-600">A live view of your binary downline and direct referrals.</p>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <a href="{{ route('tree.binary') }}" class="text-brand-600 hover:text-brand-700 underline">Binary tree →</a>
                <span class="text-gray-300">·</span>
                <a href="{{ route('tree.sponsorship') }}" class="text-brand-600 hover:text-brand-700 underline">Direct referrals →</a>
            </div>
        </div>

        {{-- Top row: the four headline numbers --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="rounded-xl border border-brand-200 bg-brand-50/60 p-4">
                <p class="text-[11px] text-brand-700 uppercase tracking-wider font-semibold mb-1">Total team</p>
                <p class="text-3xl font-bold text-brand-700 leading-none">{{ number_format($teamStats['total_team']) }}</p>
                <p class="text-[11px] text-gray-500 mt-1.5">members in your binary downline</p>
            </div>
            <div class="rounded-xl border border-leaf-200 bg-leaf-50/60 p-4">
                <p class="text-[11px] text-leaf-700 uppercase tracking-wider font-semibold mb-1">Direct referrals</p>
                <p class="text-3xl font-bold text-leaf-700 leading-none">{{ number_format($teamStats['direct_referrals']) }}</p>
                <p class="text-[11px] text-gray-500 mt-1.5">people you personally invited</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold mb-1">← Left team</p>
                <p class="text-3xl font-bold text-gray-900 leading-none">{{ number_format($teamStats['left_team']) }}</p>
                <p class="text-[11px] text-gray-500 mt-1.5">members under your left leg</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold mb-1">Right team →</p>
                <p class="text-3xl font-bold text-gray-900 leading-none">{{ number_format($teamStats['right_team']) }}</p>
                <p class="text-[11px] text-gray-500 mt-1.5">members under your right leg</p>
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
            <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold mb-3">By status</p>
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
                <span class="text-xs text-gray-600 font-medium">Joined this week</span>
                <span class="text-base font-bold text-gray-900">{{ number_format($teamStats['joined_this_week']) }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
                <span class="text-xs text-gray-600 font-medium">Joined this month</span>
                <span class="text-base font-bold text-gray-900">{{ number_format($teamStats['joined_this_month']) }}</span>
            </div>
            <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
                <span class="text-xs text-gray-600 font-medium">Cooling-off active</span>
                <span class="text-base font-bold text-gray-900">{{ number_format($teamStats['cooling_off']) }}</span>
            </div>
        </div>
    </div>
    @endif

    {{-- My Referral Link — slot-aware widget.
         Three states keyed on $leftOpen / $rightOpen which the dashboard
         route computed via PlacementEngine::hasOpenSlot(). --}}
    @php
        $inviteBase  = url('/register').'?sponsor='.$distributor->adn.'&placement='.$distributor->adn;
        $inviteLeft  = $inviteBase.'&side=L';
        $inviteRight = $inviteBase.'&side=R';
        $bothOpen    = $leftOpen && $rightOpen;
        $bothFull    = ! $leftOpen && ! $rightOpen;
        $oneOpen     = ! $bothOpen && ! $bothFull;
        $openSide    = $leftOpen ? 'L' : 'R';
        $closedSide  = $leftOpen ? 'R' : 'L';
        $invitePinned = $bothOpen
            ? $inviteBase
            : ($oneOpen ? $inviteBase.'&side='.$openSide : null);
    @endphp
    <div class="bg-white rounded-2xl border border-brand-200 p-6 col-span-full md:col-span-2 lg:col-span-3">
        <div class="flex items-start justify-between mb-3 gap-4">
            <div>
                <p class="text-xs text-brand-700 uppercase tracking-wider mb-1 font-semibold">My Referral Link</p>
                <p class="text-sm text-gray-600">
                    @if($bothFull)
                        Both your direct slots are filled. To invite more, pick a placement deeper in your tree.
                    @elseif($oneOpen)
                        Your <strong>{{ $closedSide === 'L' ? 'left' : 'right' }}</strong> direct slot is filled — this link routes new joiners to your <strong>{{ $openSide === 'L' ? 'left' : 'right' }}</strong> leg only.
                    @else
                        Share this with anyone you invite. They register through it; you become their sponsor and placement target.
                    @endif
                </p>
            </div>
            @if($bothFull)
                <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-sunrise-50 text-sunrise-700 border border-sunrise-200">
                    <span class="w-1.5 h-1.5 rounded-full bg-sunrise-500"></span>Direct slots full
                </span>
            @else
                <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-[10px] uppercase tracking-wider font-semibold bg-brand-50 text-brand-700 border border-brand-200">Personal invite</span>
            @endif
        </div>

        @if($bothFull)
            {{-- State: both direct slots taken --}}
            <div class="rounded-xl border border-sunrise-200 bg-sunrise-50/60 p-4 flex flex-col sm:flex-row sm:items-center gap-4">
                <div class="shrink-0 w-10 h-10 rounded-full bg-white border border-sunrise-200 flex items-center justify-center text-sunrise-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 0v3.75m0-3.75h3.75M12 12.75H8.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-800 font-medium leading-snug mb-1">Pick any leaf in your tree to invite there.</p>
                    <p class="text-[12px] text-gray-600 leading-snug">Hover any open slot in the tree view, click <em>Invite</em>, and a new referral link is generated for that exact placement.</p>
                </div>
                <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}"
                    class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">
                    Open my tree
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                </a>
            </div>

        @else
            {{-- State: both open OR one open. Show the (pinned) default link.
                 In one-open mode the URL already carries the open side. --}}
            <div class="space-y-2">
                <div class="flex items-stretch gap-2">
                    <input type="text" readonly value="{{ $invitePinned }}"
                        class="flex-1 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-xs font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500"
                        onclick="this.select()">
                    <button type="button"
                        onclick="navigator.clipboard.writeText('{{ $invitePinned }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);"
                        class="px-4 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold transition-colors">
                        Copy
                    </button>
                </div>

                @if($bothOpen)
                    {{-- Both legs available — keep the L/R advanced section. --}}
                    <details class="text-xs text-gray-600">
                        <summary class="cursor-pointer hover:text-brand-700">Advanced — pin a specific leg</summary>
                        <div class="mt-3 space-y-2">
                            <div class="flex items-stretch gap-2">
                                <span class="inline-flex items-center px-2.5 rounded-l-lg bg-brand-50 text-brand-700 text-[10px] font-semibold uppercase tracking-wider border border-r-0 border-brand-200">Left</span>
                                <input type="text" readonly value="{{ $inviteLeft }}" class="flex-1 rounded-r-lg border border-gray-300 bg-gray-50 px-3 py-2 text-xs font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500" onclick="this.select()">
                                <button type="button" onclick="navigator.clipboard.writeText('{{ $inviteLeft }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);" class="px-3 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-800 text-xs font-semibold transition-colors">Copy</button>
                            </div>
                            <div class="flex items-stretch gap-2">
                                <span class="inline-flex items-center px-2.5 rounded-l-lg bg-brand-50 text-brand-700 text-[10px] font-semibold uppercase tracking-wider border border-r-0 border-brand-200">Right</span>
                                <input type="text" readonly value="{{ $inviteRight }}" class="flex-1 rounded-r-lg border border-gray-300 bg-gray-50 px-3 py-2 text-xs font-mono text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500" onclick="this.select()">
                                <button type="button" onclick="navigator.clipboard.writeText('{{ $inviteRight }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1200);" class="px-3 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-800 text-xs font-semibold transition-colors">Copy</button>
                            </div>
                            <p class="text-[11px] text-gray-500 leading-relaxed">
                                Pinned-leg links let you tell the engine which slot to use; if that slot is full the link returns the visitor to Contact Us.
                            </p>
                        </div>
                    </details>
                @else
                    {{-- One open: show the open leg as a chip (already pinned in the
                         default URL above) and the closed leg as disabled. --}}
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-leaf-50 text-leaf-700 text-[11px] font-semibold border border-leaf-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-leaf-500"></span>{{ $openSide === 'L' ? 'Left' : 'Right' }} leg open
                        </span>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-100 text-gray-500 text-[11px] font-semibold border border-gray-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>{{ $closedSide === 'L' ? 'Left' : 'Right' }} leg taken
                        </span>
                        <a href="{{ route('tree.binary', ['levels' => max(1, $maxObservedDepth ?: 1)]) }}"
                            class="ml-auto text-[11px] text-brand-600 hover:text-brand-700 underline-offset-2 hover:underline">
                            Want to invite deeper? Open my tree →
                        </a>
                    </div>
                @endif
            </div>
        @endif
    </div>

    @else
    {{-- Registration incomplete --}}
    <div class="col-span-full bg-white rounded-2xl border border-amber-200 p-8 text-center">
        <p class="text-amber-700 font-semibold mb-2">Registration not yet complete</p>
        <p class="text-sm text-gray-600 mb-4">Complete your registration to receive your ADN.</p>
        <a href="{{ route('register.orientation') }}"
            class="inline-flex items-center gap-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-2.5 text-sm transition-colors">
            Continue Registration →
        </a>
    </div>
    @endif

</div>

{{-- Phase 1 notice --}}
<div class="mt-10 rounded-xl border border-gray-200 bg-white/50 p-4 text-xs text-gray-400">
    <strong class="text-gray-500">Phase 1 Platform</strong> —
    Product catalogue, orders, commissions and wallet features are coming in later phases.
    For support, email support@arovolife.com.
</div>

@endsection
