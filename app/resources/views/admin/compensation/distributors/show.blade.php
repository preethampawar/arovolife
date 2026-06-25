@extends('admin.layouts.admin')
@section('title', 'Compensation — '.$distributor->adn)
@section('heading', 'Distributor Compensation — '.$distributor->adn)

@section('content')

{{-- Header card --}}
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-5 shadow-sm">
    <div class="flex items-start justify-between mb-4">
        <div>
            <p class="text-2xl font-bold text-brand-700 font-mono">{{ $distributor->adn }}</p>
            <p class="text-sm text-gray-700 mt-0.5">
                {{ $distributor->user?->full_name }}
                — <span class="text-green-700 font-medium">{{ ucfirst($distributor->status ?? 'Active') }}</span>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.distributors.show', $distributor) }}"
               class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">← Profile</a>
            <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $distributor->adn]) }}"
               class="px-3 py-1.5 rounded-lg bg-amber-500 text-white text-sm font-medium hover:bg-amber-600">⚠ Manual Controls</a>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="rounded-xl border border-gray-200 p-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 flex items-center gap-1">
                Personal BV
                <x-help-tip text="Lifetime total BV from all personal purchases. Determines your title and max GSB slab." />
            </p>
            <p class="text-lg font-bold text-brand-700 mt-1">@bv($personalBvPaise)</p>
            <p class="text-xs text-purple-600 font-medium">{{ $title->title ?? 'No title yet' }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 flex items-center gap-1">
                Left Group BV today
                <x-help-tip text="Total BV from left Genos subtree today." />
            </p>
            <p class="text-lg font-bold text-green-700 mt-1">@bv($todayBv?->left_bv_paise ?? 0)</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-500">Right Group BV today</p>
            <p class="text-lg font-bold text-green-700 mt-1">@bv($todayBv?->right_bv_paise ?? 0)</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-500 flex items-center gap-1">
                Wallet balance
                <x-help-tip text="Net GSB and MB credits not yet paid out." />
            </p>
            <p class="text-lg font-bold text-blue-700 mt-1">₹{{ number_format($walletBalance / 100, 2) }}</p>
        </div>
    </div>

    {{-- Carry-forward state --}}
    <div class="grid grid-cols-2 gap-3">
        <div class="bg-purple-50 border border-purple-200 rounded-xl p-3">
            <p class="text-[10px] uppercase tracking-wider text-purple-600 flex items-center gap-1">
                Power-side CF
                <x-help-tip text="Stronger Genos leg BV carried into tomorrow. Capped at 4,50,000 BV." />
            </p>
            <p class="text-base font-bold text-purple-700 mt-1">
                @bv($cf?->power_side_bv_paise ?? 0)
                <span class="text-xs text-gray-400 font-normal">/ 4,50,000 cap</span>
            </p>
            @php $pctPower = $cf ? min(100, round($cf->power_side_bv_paise / 45_000_000 * 100, 1)) : 0; @endphp
            <div class="w-full bg-purple-100 rounded-full h-1.5 mt-1.5">
                <div class="bg-purple-500 h-1.5 rounded-full" style="width:{{ $pctPower }}%"></div>
            </div>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-xl p-3">
            <p class="text-[10px] uppercase tracking-wider text-green-600 flex items-center gap-1">
                Slab-1 weaker CF
                <x-help-tip text="Weaker-side BV accumulating toward the first 15,000 BV slab-1 match. No time limit." />
            </p>
            <p class="text-base font-bold text-green-700 mt-1">
                @bv($cf?->slab1_weaker_bv_paise ?? 0)
                <span class="text-xs text-gray-400 font-normal">/ 15,000 target</span>
            </p>
            @php $pctSlab1 = $cf ? min(100, round($cf->slab1_weaker_bv_paise / 1_500_000 * 100, 1)) : 0; @endphp
            <div class="w-full bg-green-100 rounded-full h-1.5 mt-1.5">
                <div class="bg-green-500 h-1.5 rounded-full" style="width:{{ $pctSlab1 }}%"></div>
            </div>
        </div>
    </div>
</div>

{{-- Failed cut-off alert --}}
@if($failedToday)
<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 flex items-center gap-3">
    <span>⚠ <strong>Today's cut-off failed</strong> — {{ $failedToday->failure_reason ?? 'unknown error' }}</span>
    <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $distributor->adn, 'action' => 'retry', 'date' => today()->toDateString()]) }}"
       class="ml-auto px-3 py-1 rounded bg-amber-200 text-amber-900 text-xs font-medium hover:bg-amber-300">Retry this cut-off</a>
</div>
@endif

{{-- Tabs --}}
<div class="flex border-b border-gray-200 mb-5">
    @foreach(['gsb' => 'GSB History', 'mb' => 'Mentorship Bonus', 'bv-log' => 'Daily BV Log', 'wallet' => 'Wallet Ledger', 'payouts' => 'Payout History', 'audit' => 'Audit Log'] as $key => $label)
    <a href="{{ route('admin.compensation.distributors.show', [$distributor, 'tab' => $key]) }}"
       class="px-4 py-2 text-sm font-medium border-b-2 -mb-px
              {{ $tab === $key ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
        {{ $label }}
    </a>
    @endforeach
</div>

@include('admin.compensation.distributors._tab-'.(in_array($tab, ['gsb','mb','bv-log','wallet','payouts','audit'], true) ? $tab : 'gsb'))

@endsection
