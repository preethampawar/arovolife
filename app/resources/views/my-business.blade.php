@extends('layouts.app')
@section('title', 'My Business')

@section('content')
@php
    use App\Modules\Shared\Support\IndianNumber as Number;

    // Every Genos figure is gated by eligibility: below the personal-BV minimum
    // the cut-off discards group BV, so the page shows 0 rather than a number
    // the distributor will never be credited for.
    $gsbMinBv = $gsbMinBvPaise !== null ? Number::format($gsbMinBvPaise / 100, 0) : '600';

    $leftTodayBv = (int) round(($dailyBv->left_bv_paise ?? 0) / 100);
    $rightTodayBv = (int) round(($dailyBv->right_bv_paise ?? 0) / 100);

    // Carry forward in the plan's strict sense: what remained after the last
    // slab match (weaker side resets to 0, power side keeps the remainder).
    // Zero until the first match — until then everything is carry over.
    $leftCarryForwardBv = $lastMatch?->power_side_after === 'L' ? (int) round($lastMatch->power_cf_after_paise / 100) : 0;
    $rightCarryForwardBv = $lastMatch?->power_side_after === 'R' ? (int) round($lastMatch->power_cf_after_paise / 100) : 0;

    // Personal purchase BV is not part of either side until the 23:59 cut-off
    // credits it to the weaker group — shown as a separate pending line, never
    // added into the carried-over totals.
    $pendingPersonalBv = (int) round(($slabProgress?->pendingPersonalBvTopupPaise ?? 0) / 100);

    // Pending-until-cut-off rule (client, 2026-08-29): nothing that tonight's
    // 23:59 cut-off decides is shown on the tiles beforehand. The Carried-over
    // tiles show the carry over as it stood after the LAST cut-off — today's
    // Genos BV is not added to it, the personal purchase BV is not attached to
    // a side, and the Power/Weaker badges are the last cut-off's decision
    // (hidden until one exists). Everything pending is on the info icon.
    $leftCarriedBv = (int) round(($slabProgress?->carriedLeftPaise() ?? 0) / 100);
    $rightCarriedBv = (int) round(($slabProgress?->carriedRightPaise() ?? 0) / 100);
    $settledPowerSide = $slabProgress?->settledPowerSide();
    $settledWeakerSide = $slabProgress?->settledWeakerSide() ?? 'R';
    $pendingTip = static function (string $side, int $todayBv) use ($pendingPersonalBv): string {
        $tail = ' Carry over, the power/weaker side and any slab match are updated only at the cut-off.';
        if ($todayBv <= 0 && $pendingPersonalBv <= 0) {
            return 'Nothing is pending for your '.$side.' side — no '.$side.' Genos BV has arrived since the last 23:59 cut-off.'.$tail;
        }
        $parts = [];
        if ($todayBv > 0) {
            $parts[] = Number::format($todayBv, 0).' BV of '.$side.' Genos business today';
        }
        if ($pendingPersonalBv > 0) {
            $parts[] = Number::format($pendingPersonalBv, 0).' BV of your own purchase, which goes to whichever side is weaker at that moment (only if a side has reached the first slab)';
        }

        return 'Pending tonight\'s 23:59 cut-off: '.implode(' and ', $parts).'.'.$tail;
    };
    $sideBadgeClasses = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium';
    $powerBadgeClasses = $sideBadgeClasses.' bg-indigo-100 text-indigo-700';
    $weakerBadgeClasses = $sideBadgeClasses.' bg-amber-100 text-amber-700';

    // With the GSB flag off there is no cut-off, no eligibility minimum and no
    // matching — every GSB-mechanics phrase disappears from the page.
    $eligibilityNote = (! $gsbOn || $genosBvEligible)
        ? 'as of last page load'
        : 'requires '.$gsbMinBv.' BV of personal purchases';
    $eligibilityTipSuffix = $gsbOn
        ? ' Genos BV is counted only after your lifetime personal BV reaches '.$gsbMinBv.' BV; until then it shows as 0.'
        : '';
    $todayBvTipSuffix = $gsbOn ? ' — that is, since the last 23:59 cut-off. It does not include BV carried over from earlier days' : '';

    $badgeTipSuffix = ' The badge shows whether this was your power side (the higher of the two) or your weaker side at the last 23:59 cut-off — only the weaker side is matched against the slab table. It is hidden until a cut-off has decided the sides.';

    $cardClasses = 'bg-white rounded-2xl border border-gray-200 p-5';
    $statLabelClasses = 'text-xs text-gray-600 font-medium';
    $statValueClasses = 'text-2xl font-bold text-gray-900';
@endphp
<div>
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Business</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        @if($gsbOn)
            A snapshot of your own arovolife business: your personal purchase BV and title, your current wallet balance, and your Left and Right Genos carry over as it stood after the last 23:59 cut-off. Every number here is a record of what has already happened on your account. Use the tabs above to open the detailed pages.
        @else
            A snapshot of your own arovolife business: your personal purchase BV and title, your current wallet balance, and your Left and Right Genos team figures. Every number here is a record of what has already happened on your account. Use the tabs above to open the detailed pages.
        @endif
    </div>

    {{-- Group 2 — headline stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-5 gap-4 mb-4">
        <div class="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-2xl p-5 text-white sm:col-span-2">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-indigo-200 font-medium">Personal BV (lifetime)</p>
                <x-help-tip :light="true" text="The total Business Volume from your own personal purchases since joining. It is a lifetime running total and never resets, and it is what decides your purchase title." />
            </div>
            <p class="text-2xl font-bold">{{ $personalBvPaise !== null ? Number::format($personalBvPaise / 100, 0) : '—' }}</p>
            <p class="text-xs text-indigo-200 mt-1 flex items-center gap-1">
                Title: {{ $title?->title ?? 'No title yet' }}
                <x-help-tip :light="true" text="Your title comes from the personal purchase ladder — it moves up as your lifetime personal BV grows. Below 3,000 BV of personal purchases no title is held yet, which is shown as 'No title yet'." />
            </p>
        </div>
        <div class="bg-gradient-to-r from-indigo-600 to-purple-600 rounded-2xl p-5 text-white sm:col-span-3">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-indigo-200 font-medium">Next payout — Tuesday, {{ $nextPayout->format('d M Y') }}</p>
                <x-help-tip :light="true" text="Weekly payouts run every Tuesday (IST); monthly bonus income transfers in the monthly payout on the 9th. This is the balance sitting in your wallet right now, not a forecast. At payout it is transferred after the 3% admin charge, 5% TDS and any repurchase deduction; a balance below the minimum payout amount is not transferred and simply stays in your wallet for the following payout." />
            </div>
            <p class="text-2xl font-bold">₹{{ $walletBalancePaise !== null ? Number::format($walletBalancePaise / 100, 2) : '—' }}</p>
            <p class="text-xs text-indigo-200 mt-1">Transferred after 3% admin charge + 5% TDS + repurchase deduction.</p>
            <p class="text-xs text-indigo-300 mt-1">Current wallet balance</p>
        </div>
    </div>

    {{-- Group 3 — carry forward / carry over row (Left before Right; GSB matching mechanics) --}}
    @if($gsbOn)
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <div class="{{ $cardClasses }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Left carry forward</p>
                <x-help-tip text="The BV remaining on your Left side after your last slab match — when a slab pays, the weaker side resets to 0 and the power side's remaining BV is carried forward. Until your first slab matches this is 0: BV building up before a match is carry over, shown in the Carried-over cards.{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ Number::format($leftCarryForwardBv, 0) }}</p>
            @if($lastMatch !== null)
                <p class="text-xs text-gray-600 mt-1">{{ $leftCarryForwardBv > 0 ? 'Remaining after your last slab match' : 'Reset at your last slab match' }} ({{ $lastMatch->cutoff_date->format('d M Y') }})</p>
            @else
                <p class="text-xs text-gray-600 mt-1">No slab matched yet</p>
            @endif
        </div>
        <div class="{{ $cardClasses }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Carried-over Left Genos BV</p>
                <x-help-tip text="The BV carried over on your Left side, as it stood after the last 23:59 cut-off — business that occurs before matching is carry over, and it is never lost; it keeps accumulating until a slab is matched. Today's Left Genos BV and your own purchase BV are not added until tonight's cut-off — the info icon below shows what is pending.{{ $badgeTipSuffix }}{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ Number::format($leftCarriedBv, 0) }}</p>
            @if($settledPowerSide !== null)
                <p class="mt-1">
                    <span class="{{ $settledPowerSide === 'L' ? $powerBadgeClasses : $weakerBadgeClasses }}">{{ $settledPowerSide === 'L' ? 'Power side' : 'Weaker side' }}</span>
                </p>
            @endif
            <p class="text-xs text-gray-600 mt-1 flex items-center gap-1">As of the last 23:59 cut-off <x-help-tip :text="$pendingTip('Left', $leftTodayBv)" /></p>
            @if($slabProgress !== null && $settledWeakerSide === 'L' && $slabProgress->slab1WeakerCfPaise > 0)
                <p class="text-xs text-gray-600 mt-1">+ {{ Number::format($slabProgress->slab1WeakerCfPaise / 100, 0) }} BV slab-1 weaker carry over</p>
            @endif
        </div>
        <div class="{{ $cardClasses }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Carried-over Right Genos BV</p>
                <x-help-tip text="The BV carried over on your Right side, as it stood after the last 23:59 cut-off — business that occurs before matching is carry over, and it is never lost; it keeps accumulating until a slab is matched. Today's Right Genos BV and your own purchase BV are not added until tonight's cut-off — the info icon below shows what is pending.{{ $badgeTipSuffix }}{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ Number::format($rightCarriedBv, 0) }}</p>
            @if($settledPowerSide !== null)
                <p class="mt-1">
                    <span class="{{ $settledPowerSide === 'R' ? $powerBadgeClasses : $weakerBadgeClasses }}">{{ $settledPowerSide === 'R' ? 'Power side' : 'Weaker side' }}</span>
                </p>
            @endif
            <p class="text-xs text-gray-600 mt-1 flex items-center gap-1">As of the last 23:59 cut-off <x-help-tip :text="$pendingTip('Right', $rightTodayBv)" /></p>
            @if($slabProgress !== null && $settledWeakerSide === 'R' && $slabProgress->slab1WeakerCfPaise > 0)
                <p class="text-xs text-gray-600 mt-1">+ {{ Number::format($slabProgress->slab1WeakerCfPaise / 100, 0) }} BV slab-1 weaker carry over</p>
            @endif
        </div>
        <div class="{{ $cardClasses }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Right carry forward</p>
                <x-help-tip text="The BV remaining on your Right side after your last slab match — when a slab pays, the weaker side resets to 0 and the power side's remaining BV is carried forward. Until your first slab matches this is 0: BV building up before a match is carry over, shown in the Carried-over cards.{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ Number::format($rightCarryForwardBv, 0) }}</p>
            @if($lastMatch !== null)
                <p class="text-xs text-gray-600 mt-1">{{ $rightCarryForwardBv > 0 ? 'Remaining after your last slab match' : 'Reset at your last slab match' }} ({{ $lastMatch->cutoff_date->format('d M Y') }})</p>
            @else
                <p class="text-xs text-gray-600 mt-1">No slab matched yet</p>
            @endif
        </div>
    </div>
    @endif

    {{-- Group 4 — team size and today's Genos BV (Left before Right) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="{{ $cardClasses }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Left Genos total team</p>
                <x-help-tip text="Everyone placed anywhere in the Left side of your Genos, at any depth below you. It counts placements, not purchases." />
            </div>
            <p class="{{ $statValueClasses }}">{{ Number::format($teamCounts['left_team'] ?? 0, 0) }}</p>
            <p class="text-xs text-gray-600 mt-1">Left side, all depths</p>
        </div>
        <div class="{{ $cardClasses }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Today Left Genos BV</p>
                <x-help-tip text="Business Volume generated by your Left Genos side today{{ $todayBvTipSuffix }}.{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ Number::format($leftTodayBv, 0) }}</p>
            <p class="text-xs text-gray-600 mt-1">{{ $eligibilityNote }}</p>
        </div>
        <div class="{{ $cardClasses }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Today Right Genos BV</p>
                <x-help-tip text="Business Volume generated by your Right Genos side today{{ $todayBvTipSuffix }}.{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ Number::format($rightTodayBv, 0) }}</p>
            <p class="text-xs text-gray-600 mt-1">{{ $eligibilityNote }}</p>
        </div>
        <div class="{{ $cardClasses }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Right Genos total team</p>
                <x-help-tip text="Everyone placed anywhere in the Right side of your Genos, at any depth below you. It counts placements, not purchases." />
            </div>
            <p class="{{ $statValueClasses }}">{{ Number::format($teamCounts['right_team'] ?? 0, 0) }}</p>
            <p class="text-xs text-gray-600 mt-1">Right side, all depths</p>
        </div>
    </div>

    {{-- Note — carry over vs carry forward, the partner's canonical definitions --}}
    @if($gsbOn)
    <div class="bg-white rounded-2xl border border-gray-200 p-5 mt-8">
        <p class="text-sm font-semibold text-gray-700 mb-3">Note</p>
        <p class="text-sm text-gray-600 mb-2"><span class="font-semibold text-gray-800">Carry over:</span> Business that occurs before matching is called carry over.</p>
        <p class="text-sm text-gray-600"><span class="font-semibold text-gray-800">Carry forward:</span> The remaining BVs after matching are called carry forward.</p>
        <p class="text-xs text-gray-600 mt-2">Carry over is never lost — it keeps accumulating as that side's opening balance until a slab is matched. After a match, the weaker side resets to 0 and the power side's carry forward is capped at 4,50,000 BV.</p>
        <p class="text-xs text-gray-600 mt-2">Your own purchase BV never joins the carry over at the time of purchase. It is added at the 23:59 cut-off, to whichever Genos side is weaker at that moment, and only if a side has reached the first slab that day.</p>
    </div>
    @endif
</div>
@endsection
