@extends('layouts.app')
@section('title', 'Income')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        @if ($gsbOn)
            This dashboard shows a live snapshot of your Genos Income. Genos BV updates as your Genos members make purchases throughout the day. The 23:59 daily cut-off locks the BV for that day and calculates your Genos Sales Bonus. Your wallet is credited after the cut-off; weekly bonuses transfer to your bank account every Tuesday{{ ($keyDates['hasMonthlyBonuses'] ?? false) ? ', and monthly bonuses in the monthly payout on the 9th' : '' }}. Deductions (3% admin charge, 5% TDS, and any repurchase wallet balance) are applied before transfer.
        @else
            This dashboard shows a live snapshot of your income. Weekly bonuses transfer to your bank account every Tuesday{{ ($keyDates['hasMonthlyBonuses'] ?? false) ? ', and monthly bonuses in the monthly payout on the 9th' : '' }}. Deductions (3% admin charge, 5% TDS, and any repurchase wallet balance) are applied before transfer.
        @endif
    </div>

    {{-- Wallet hero card --}}
    <div class="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-2xl p-6 text-white mb-6">
        <p class="text-sm text-indigo-200 font-medium mb-1">Wallet Balance</p>
        @if ($walletBalancePaise !== null)
            <p class="text-4xl font-bold mb-1">₹{{ \App\Modules\Shared\Support\IndianNumber::format($walletBalancePaise / 100, 2) }}</p>
            <p class="text-sm text-indigo-200">Transferred to your bank on payout days, after 3% admin charge + 5% TDS + repurchase deduction</p>
        @else
            <p class="text-4xl font-bold mb-1">₹—</p>
            <p class="text-sm text-indigo-200">Your wallet balance will appear here once the GSB engine is active.</p>
        @endif
    </div>

    {{-- Key dates strip: schedule facts only, no amounts --}}
    @php
        $matchedSoFarBv = ($genosBvEligible && $slabProgress)
            ? (int) round(min($slabProgress->leftEffectivePaise, $slabProgress->rightEffectivePaise) / 100)
            : null;
    @endphp
    @php
        $keyDateCardCount = 1 + ($gsbOn ? 1 : 0) + (($keyDates['hasMonthlyBonuses'] ?? false) ? 1 : 0);
        $keyDateGridClass = [1 => 'sm:grid-cols-1', 2 => 'sm:grid-cols-2'][$keyDateCardCount] ?? 'sm:grid-cols-3';
    @endphp
    <div class="grid grid-cols-1 {{ $keyDateGridClass }} gap-4 mb-6">
        @if ($gsbOn)
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-600 font-medium">Tonight's cut-off</p>
                <x-help-tip text="Every day at 23:59 IST your Left and Right Genos BV is locked and the lower (weaker) side is matched against the Genos Sales Bonus slabs. See the slab ladder on the Genos BV page for exactly where you stand." />
            </div>
            <p class="text-xl font-bold text-gray-900">{{ $keyDates['nextCutoff']->format('d M') }} · 23:59</p>
            @if ($matchedSoFarBv !== null)
                <p class="text-xs text-gray-600 mt-1">Matched BV so far today: {{ \App\Modules\Shared\Support\IndianNumber::format($matchedSoFarBv, 0) }}</p>
            @else
                <p class="text-xs text-gray-600 mt-1">Locks today's Genos BV</p>
            @endif
        </div>
        @endif
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-600 font-medium">Next weekly payout</p>
                <x-help-tip text="Your weekly bonus income{{ $gsbOn ? ' — Genos Sales Bonus and other weekly credits —' : '' }} transfers to your bank every Tuesday, after deductions and provided your balance meets the minimum. See Wallet &amp; Payouts for the exact rules." />
            </div>
            <p class="text-xl font-bold text-gray-900">{{ $keyDates['nextWeeklyPayout']->format('D, d M') }}</p>
            <p class="text-xs text-gray-600 mt-1">Weekly bonus income</p>
        </div>
        @if ($keyDates['hasMonthlyBonuses'] ?? false)
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-600 font-medium">Next monthly payout</p>
                <x-help-tip text="Monthly bonus income for the previous month is calculated at the start of each month and transfers to your bank in the monthly payout on the 9th." />
            </div>
            <p class="text-xl font-bold text-gray-900">{{ $keyDates['nextMonthlyPayout']->format('D, d M') }}</p>
            <p class="text-xs text-gray-600 mt-1">Monthly bonuses for the previous month</p>
        </div>
        @endif
    </div>

    {{-- Per-bonus summary: wallet-ledger figures, strictly historical --}}
    @if (! empty($bonusSummary))
    <div class="bg-white rounded-2xl border border-gray-200 p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-semibold text-gray-700">My bonuses — credited to wallet</p>
            <x-help-tip text="What each bonus has actually credited to your wallet — this calendar month and since you joined. These are historical figures from your wallet ledger; deductions are applied at payout." />
        </div>
        @php
            $bonusGridClass = [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3'][count($bonusSummary)] ?? 'lg:grid-cols-4';
        @endphp
        <div class="grid grid-cols-1 sm:grid-cols-2 {{ $bonusGridClass }} gap-3">
            @foreach ($bonusSummary as $bonus)
            <a href="{{ route($bonus['route']) }}" class="block bg-gray-50 rounded-xl px-4 py-3 hover:bg-gray-100 transition-colors">
                <p class="text-xs text-gray-600 flex items-center gap-1 mb-1">{{ $bonus['label'] }} <x-help-tip :text="$bonus['tip']" /></p>
                <p class="text-lg font-bold text-gray-900">₹{{ \App\Modules\Shared\Support\IndianNumber::format($bonus['monthPaise'] / 100, 0) }} <span class="text-xs font-normal text-gray-600">this month</span></p>
                <p class="text-xs text-gray-600 mt-0.5">₹{{ \App\Modules\Shared\Support\IndianNumber::format($bonus['lifetimePaise'] / 100, 0) }} lifetime</p>
                <p class="text-xs text-brand-700 mt-1 font-medium">View details →</p>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 {{ $gsbOn ? 'sm:grid-cols-3' : 'sm:grid-cols-1' }} gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-600 font-medium">Personal BV (Lifetime)</p>
                <x-help-tip text="The total Business Volume you have accumulated from your own personal purchases since joining. This is a lifetime running total and never resets. It determines your personal purchase title (Retailer, Dealer, Wholesaler, etc.)." />
            </div>
            @if ($personalBvPaise !== null)
                <p class="text-2xl font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($personalBvPaise / 100, 0) }}</p>
                <p class="text-xs text-gray-600 mt-1">{{ $title?->title ?? 'No title yet' }}</p>
            @else
                <p class="text-2xl font-bold text-gray-900">—</p>
                <p class="text-xs text-gray-600 mt-1">No title yet</p>
            @endif
        </div>
        @php
            $gsbMinBv = $gsbMinBvPaise !== null ? \App\Modules\Shared\Support\IndianNumber::format($gsbMinBvPaise / 100, 0) : '600';
            $groupBvNote = $genosBvEligible
                ? 'as of last page load'
                : 'requires '.$gsbMinBv.' BV of personal purchases';
            $groupBvTipSuffix = ' Genos BV is counted only after your lifetime personal BV reaches '.$gsbMinBv.' BV; until then it shows as 0.';

            // Big number = the carry-forward-inclusive figure tonight's cut-off
            // will use — the same figure the Genos BV page shows. The breakdown
            // splits it into today's fresh BV and the carry-forward that opened
            // the day on that side. Personal purchase BV is NOT in either: the
            // 23:59 cut-off adds it to the weaker side, so it is shown as a
            // separate pending line under that side's card.
            $leftTodayBv = (int) round(($dailyBv->left_bv_paise ?? 0) / 100);
            $rightTodayBv = (int) round(($dailyBv->right_bv_paise ?? 0) / 100);
            $leftEffectiveBv = (int) round(($slabProgress?->leftEffectivePaise ?? 0) / 100);
            $rightEffectiveBv = (int) round(($slabProgress?->rightEffectivePaise ?? 0) / 100);
            $leftCarriedBv = (int) round(($slabProgress?->carriedLeftPaise() ?? 0) / 100);
            $rightCarriedBv = (int) round(($slabProgress?->carriedRightPaise() ?? 0) / 100);

            $bvBreakdown = static fn (int $todayBv, int $carriedBv): string =>
                \App\Modules\Shared\Support\IndianNumber::format($todayBv, 0).' today + '
                .\App\Modules\Shared\Support\IndianNumber::format($carriedBv, 0).' carried over';

            $leftBreakdown = $bvBreakdown($leftTodayBv, $leftCarriedBv);
            $rightBreakdown = $bvBreakdown($rightTodayBv, $rightCarriedBv);

            $pendingPersonalBv = (int) round(($slabProgress?->pendingPersonalBvTopupPaise ?? 0) / 100);
            $pendingPersonalBvHint = $pendingPersonalBv > 0
                ? '+ '.\App\Modules\Shared\Support\IndianNumber::format($pendingPersonalBv, 0).' BV personal purchase — pending tonight\'s cut-off'
                : null;

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
                ? '+ '.\App\Modules\Shared\Support\IndianNumber::format($slab1WeakerCfBv, 0).' BV in slab-1 weaker carry over (see card below)'
                : null;
        @endphp
        @if ($gsbOn)
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-600 font-medium">Left Genos BV (Today)</p>
                <x-help-tip text="Business Volume generated by your Left Genos group today, plus any BV carried over on your Left side from earlier days. This is the figure tonight's 23:59 cut-off will use. Your own purchase BV is not included — the cut-off adds it to whichever side is weaker, and only if a side has reached the first slab.{{ $groupBvTipSuffix }}" />
            </div>
            <p class="text-2xl font-bold text-gray-900">{{ $genosBvEligible ? ($slabProgress ? \App\Modules\Shared\Support\IndianNumber::format($leftEffectiveBv, 0) : '—') : '0' }}</p>
            @if ($genosBvEligible && $slabProgress)
                <p class="mt-1">
                    <span class="{{ $powerSideIsLeft ? $powerBadgeClasses : $weakerBadgeClasses }}">{{ $powerSideIsLeft ? 'Power side' : 'Weaker side' }}</span>
                </p>
                <p class="text-xs text-gray-600 mt-1">{{ $leftBreakdown }}</p>
                @if ($pendingPersonalBvHint !== null && $slabProgress->pendingTopupSide === 'L')
                    <p class="text-xs text-gray-600 mt-1">{{ $pendingPersonalBvHint }}</p>
                @endif
                @if ($slab1WeakerCfHint !== null && $weakerSideIsLeft)
                    <p class="text-xs text-gray-600 mt-1">{{ $slab1WeakerCfHint }}</p>
                @endif
            @endif
            <p class="text-xs text-gray-600 mt-1">{{ $groupBvNote }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-600 font-medium">Right Genos BV (Today)</p>
                <x-help-tip text="Business Volume generated by your Right Genos group today, plus any BV carried over on your Right side from earlier days. This is the figure tonight's 23:59 cut-off will use. Your own purchase BV is not included — the cut-off adds it to whichever side is weaker, and only if a side has reached the first slab.{{ $groupBvTipSuffix }}" />
            </div>
            <p class="text-2xl font-bold text-gray-900">{{ $genosBvEligible ? ($slabProgress ? \App\Modules\Shared\Support\IndianNumber::format($rightEffectiveBv, 0) : '—') : '0' }}</p>
            @if ($genosBvEligible && $slabProgress)
                <p class="mt-1">
                    <span class="{{ $powerSideIsLeft ? $weakerBadgeClasses : $powerBadgeClasses }}">{{ $powerSideIsLeft ? 'Weaker side' : 'Power side' }}</span>
                </p>
                <p class="text-xs text-gray-600 mt-1">{{ $rightBreakdown }}</p>
                @if ($pendingPersonalBvHint !== null && $slabProgress->pendingTopupSide === 'R')
                    <p class="text-xs text-gray-600 mt-1">{{ $pendingPersonalBvHint }}</p>
                @endif
                @if ($slab1WeakerCfHint !== null && ! $weakerSideIsLeft)
                    <p class="text-xs text-gray-600 mt-1">{{ $slab1WeakerCfHint }}</p>
                @endif
            @endif
            <p class="text-xs text-gray-600 mt-1">{{ $groupBvNote }}</p>
        </div>
        @endif
    </div>

    @if ($gsbOn)
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
            <p class="text-xl font-bold text-gray-900 mb-2">{{ \App\Modules\Shared\Support\IndianNumber::format($powerCfBv, 0) }} BV <span class="text-sm font-normal text-gray-600">/ 4,50,000 BV cap</span></p>
            <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="bg-indigo-500 h-2 rounded-full" style="width: {{ $powerPct }}%"></div>
            </div>
            @if ($cf && $cf->power_side)
                <p class="text-xs text-gray-600 mt-2">On {{ $cf->power_side === 'L' ? 'Left' : 'Right' }} side</p>
            @endif
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-gray-700">Slab-1 weaker carry over</p>
                <x-help-tip text="For the first slab only (15,000 BV match), your weaker side BV carries over indefinitely — there is no time limit. It accumulates day by day until 15,000 BV is matched on both sides, which is when Slab 1 (₹2,000) pays. This bucket is not pinned to Left or Right: it counts toward whichever side is the weaker one at each cut-off, which is why it is held here instead of inside a side's Genos BV total." />
            </div>
            <p class="text-xl font-bold text-gray-900 mb-2">{{ \App\Modules\Shared\Support\IndianNumber::format($slab1CfBv, 0) }} BV <span class="text-sm font-normal text-gray-600">/ 15,000 BV target</span></p>
            <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="bg-green-500 h-2 rounded-full" style="width: {{ $slab1Pct }}%"></div>
            </div>
            @if ($genosBvEligible && $slabProgress)
                <p class="text-xs text-gray-600 mt-2">Currently accumulating from your {{ $weakerSideLabel }} (weaker) side</p>
            @endif
            <p class="text-xs text-gray-600 mt-2">No time limit</p>
        </div>
    </div>
    @endif
</div>
@endsection
