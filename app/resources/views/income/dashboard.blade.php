@extends('layouts.app')
@section('title', 'My Income')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        This dashboard shows a live snapshot of your Genos Income. Group BV updates as your Genos members make purchases throughout the day. The 23:59 daily cut-off locks the BV for that day and calculates your Genos Sales Bonus. Your wallet is credited after the cut-off and your earnings are transferred to your bank account every Tuesday. Deductions (3% admin charge, 5% TDS, and any repurchase wallet balance) are applied before transfer.
    </div>

    {{-- Payout hero card --}}
    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-2xl p-6 text-white mb-6">
        <p class="text-sm text-indigo-200 font-medium mb-1">Next Payout — Tuesday</p>
        @if ($walletBalancePaise !== null)
            <p class="text-4xl font-bold mb-1">₹{{ \Illuminate\Support\Number::format($walletBalancePaise / 100, 2) }}</p>
            <p class="text-sm text-indigo-200">After 3% admin charge + 5% TDS + repurchase deduction</p>
        @else
            <p class="text-4xl font-bold mb-1">₹—</p>
            <p class="text-sm text-indigo-200">Your wallet balance will appear here once the GSB engine is active.</p>
        @endif
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500 font-medium">Personal BV (Lifetime)</p>
                <x-help-tip text="The total Business Volume you have accumulated from your own personal purchases since joining. This is a lifetime running total and never resets. It determines your personal purchase title (Retailer, Dealer, Wholesaler, etc.)." />
            </div>
            @if ($personalBvPaise !== null)
                <p class="text-2xl font-bold text-gray-900">{{ \Illuminate\Support\Number::format($personalBvPaise / 100, 0) }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ $title?->title ?? 'No title yet' }}</p>
            @else
                <p class="text-2xl font-bold text-gray-900">—</p>
                <p class="text-xs text-gray-400 mt-1">No title yet</p>
            @endif
        </div>
        @php
            $gsbMinBv = $gsbMinBvPaise !== null ? \Illuminate\Support\Number::format($gsbMinBvPaise / 100, 0) : '600';
            $groupBvNote = $genosBvEligible
                ? 'as of last page load'
                : 'requires '.$gsbMinBv.' BV of personal purchases';
            $groupBvTipSuffix = ' Group BV is counted only after your lifetime personal BV reaches '.$gsbMinBv.' BV; until then it shows as 0.';

            // Big number = the carry-forward-inclusive figure tonight's cut-off
            // will use — the same figure the Genos BV page shows. The breakdown
            // splits it into today's fresh BV, the carry-forward that opened the
            // day on that side, and (only when the cut-off is about to credit
            // it) the pending personal-BV top-up on the weaker side.
            $leftTodayBv = (int) round(($dailyBv->left_bv_paise ?? 0) / 100);
            $rightTodayBv = (int) round(($dailyBv->right_bv_paise ?? 0) / 100);
            $leftEffectiveBv = (int) round(($slabProgress?->leftEffectivePaise ?? 0) / 100);
            $rightEffectiveBv = (int) round(($slabProgress?->rightEffectivePaise ?? 0) / 100);
            $leftCarriedBv = (int) round(($slabProgress?->carriedLeftPaise() ?? 0) / 100);
            $rightCarriedBv = (int) round(($slabProgress?->carriedRightPaise() ?? 0) / 100);
            $leftTopupBv = max(0, $leftEffectiveBv - $leftTodayBv - $leftCarriedBv);
            $rightTopupBv = max(0, $rightEffectiveBv - $rightTodayBv - $rightCarriedBv);

            $bvBreakdown = static fn (int $todayBv, int $carriedBv, int $topupBv): string =>
                \Illuminate\Support\Number::format($todayBv, 0).' today + '
                .\Illuminate\Support\Number::format($carriedBv, 0).' carried over'
                .($topupBv > 0 ? ' + '.\Illuminate\Support\Number::format($topupBv, 0).' personal BV' : '');

            $leftBreakdown = $bvBreakdown($leftTodayBv, $leftCarriedBv, $leftTopupBv);
            $rightBreakdown = $bvBreakdown($rightTodayBv, $rightCarriedBv, $rightTopupBv);

            // Power/weaker side and the per-side carry-forward split both live on
            // the GsbSlabProgress DTO so every page reads one implementation.
            $powerSideIsLeft = ($slabProgress?->powerSide() ?? 'L') === 'L';
            $weakerSideIsLeft = ! $powerSideIsLeft;
            $weakerSideLabel = $weakerSideIsLeft ? 'Left' : 'Right';
            $sideBadgeClasses = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium';
            $powerBadgeClasses = $sideBadgeClasses.' bg-indigo-100 text-indigo-700';
            $weakerBadgeClasses = $sideBadgeClasses.' bg-amber-100 text-amber-700';

            // The slab-1 weaker carry-forward has no L/R column — it applies to
            // whichever side is weaker at the next cut-off — so it is surfaced as
            // a hint under the currently weaker card, never added into a side's
            // total.
            $slab1WeakerCfBv = (int) round(($slabProgress?->slab1WeakerCfPaise ?? 0) / 100);
            $slab1WeakerCfHint = $slab1WeakerCfBv > 0
                ? '+ '.\Illuminate\Support\Number::format($slab1WeakerCfBv, 0).' BV in slab-1 weaker carry over (see card below)'
                : null;
        @endphp
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500 font-medium">Left Group BV (Today)</p>
                <x-help-tip text="Business Volume generated by your Left Genos group today, plus any BV carried over on your Left side from earlier days. This is the figure tonight's 23:59 cut-off will use.{{ $groupBvTipSuffix }}" />
            </div>
            <p class="text-2xl font-bold text-gray-900">{{ $genosBvEligible ? ($slabProgress ? \Illuminate\Support\Number::format($leftEffectiveBv, 0) : '—') : '0' }}</p>
            @if ($genosBvEligible && $slabProgress)
                <p class="mt-1">
                    <span class="{{ $powerSideIsLeft ? $powerBadgeClasses : $weakerBadgeClasses }}">{{ $powerSideIsLeft ? 'Power side' : 'Weaker side' }}</span>
                </p>
                <p class="text-xs text-gray-500 mt-1">{{ $leftBreakdown }}</p>
                @if ($slab1WeakerCfHint !== null && $weakerSideIsLeft)
                    <p class="text-xs text-gray-400 mt-1">{{ $slab1WeakerCfHint }}</p>
                @endif
            @endif
            <p class="text-xs text-gray-400 mt-1">{{ $groupBvNote }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500 font-medium">Right Group BV (Today)</p>
                <x-help-tip text="Business Volume generated by your Right Genos group today, plus any BV carried over on your Right side from earlier days. This is the figure tonight's 23:59 cut-off will use.{{ $groupBvTipSuffix }}" />
            </div>
            <p class="text-2xl font-bold text-gray-900">{{ $genosBvEligible ? ($slabProgress ? \Illuminate\Support\Number::format($rightEffectiveBv, 0) : '—') : '0' }}</p>
            @if ($genosBvEligible && $slabProgress)
                <p class="mt-1">
                    <span class="{{ $powerSideIsLeft ? $weakerBadgeClasses : $powerBadgeClasses }}">{{ $powerSideIsLeft ? 'Weaker side' : 'Power side' }}</span>
                </p>
                <p class="text-xs text-gray-500 mt-1">{{ $rightBreakdown }}</p>
                @if ($slab1WeakerCfHint !== null && ! $weakerSideIsLeft)
                    <p class="text-xs text-gray-400 mt-1">{{ $slab1WeakerCfHint }}</p>
                @endif
            @endif
            <p class="text-xs text-gray-400 mt-1">{{ $groupBvNote }}</p>
        </div>
    </div>

    {{-- Carry-forward cards --}}
    @php
        $powerCfBv = $cf ? (int) round($cf->power_side_bv_paise / 100) : 0;
        $powerCapBv = 450_000;
        $powerPct = $powerCapBv > 0 ? min(100, round($powerCfBv / $powerCapBv * 100)) : 0;

        $slab1CfBv = $cf ? (int) round($cf->slab1_weaker_bv_paise / 100) : 0;
        $slab1TargetBv = 15_000;
        $slab1Pct = $slab1TargetBv > 0 ? min(100, round($slab1CfBv / $slab1TargetBv * 100)) : 0;
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-gray-700">Power-side carry over (opening balance)</p>
                <x-help-tip text="BV on your stronger (higher) Genos side is never lost. At every cut-off it carries over and is added straight back to the same side the next day — it is that side's opening balance, not a deduction. Business that occurs before matching is carry over; before your first slab matches it simply keeps accumulating. Only after a slab pays is the matched amount deducted — the weaker side resets to 0 and the power side's remaining BV (the carry forward) continues to the next day. Capped at 4,50,000 BV — any BV above this cap is flushed at each cut-off." />
            </div>
            <p class="text-xl font-bold text-gray-900 mb-2">{{ \Illuminate\Support\Number::format($powerCfBv, 0) }} BV <span class="text-sm font-normal text-gray-400">/ 4,50,000 BV cap</span></p>
            <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="bg-indigo-500 h-2 rounded-full" style="width: {{ $powerPct }}%"></div>
            </div>
            @if ($cf && $cf->power_side)
                <p class="text-xs text-gray-400 mt-2">On {{ $cf->power_side === 'L' ? 'Left' : 'Right' }} side</p>
            @endif
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-gray-700">Slab-1 weaker carry over</p>
                <x-help-tip text="For the first slab only (15,000 BV match), your weaker side BV carries over indefinitely — there is no time limit. It accumulates day by day until 15,000 BV is matched on both sides, which is when Slab 1 (₹2,000) pays. This bucket is not pinned to Left or Right: it counts toward whichever side is the weaker one at each cut-off, which is why it is held here instead of inside a side's Group BV total." />
            </div>
            <p class="text-xl font-bold text-gray-900 mb-2">{{ \Illuminate\Support\Number::format($slab1CfBv, 0) }} BV <span class="text-sm font-normal text-gray-400">/ 15,000 BV target</span></p>
            <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="bg-green-500 h-2 rounded-full" style="width: {{ $slab1Pct }}%"></div>
            </div>
            @if ($genosBvEligible && $slabProgress)
                <p class="text-xs text-gray-400 mt-2">Currently accumulating from your {{ $weakerSideLabel }} (weaker) side</p>
            @endif
            <p class="text-xs text-gray-400 mt-2">No time limit</p>
        </div>
    </div>
</div>
@endsection
