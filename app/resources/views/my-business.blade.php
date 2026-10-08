@extends('layouts.app')
@section('title', 'My Business World')

@section('content')
@php
    use App\Modules\Shared\Support\IndianNumber as Number;

    // R-75 / QA F53: the Wednesday-to-Tuesday payout week and the 8th-of-month
    // monthly run are plan disclosures. While the `compensation` policy page is
    // held back for the DSA §6.2 30-day notice, no distributor surface may state
    // them — a tooltip that says what the unpublished page has not yet said is
    // the same disclosure by another route. Falls back to the cadence that is
    // already published.
    $payoutCadencePublished = \App\Modules\Content\Models\ContentPage::isSlugPublished('compensation');

    // Every Genos figure is gated by eligibility: below the personal-BV minimum
    // the cut-off discards group BV, so the page shows 0 rather than a number
    // the distributor will never be credited for.
    $gsbMinBv = $gsbMinBvPaise !== null ? \App\Modules\Shared\Support\IndianNumber::format($gsbMinBvPaise / 100, 0) : '600';

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
    $leftSinceMatchBv = \App\Modules\Compensation\Support\CarryOverDisplay::sinceLastMatch($leftCarriedBv, $leftCarryForwardBv);
    $rightSinceMatchBv = \App\Modules\Compensation\Support\CarryOverDisplay::sinceLastMatch($rightCarriedBv, $rightCarryForwardBv);
    $gl = \App\Modules\Genealogy\Support\GenosSideColors::for('L');
    $gr = \App\Modules\Genealogy\Support\GenosSideColors::for('R');
    $settledPowerSide = $slabProgress?->settledPowerSide();
    $settledWeakerSide = $slabProgress?->settledWeakerSide() ?? 'R';
    $pendingTip = static function (string $side, int $todayBv) use ($pendingPersonalBv): string {
        $tail = ' Carry over, the power/weaker side and any slab match are updated only at the cut-off.';
        if ($todayBv <= 0 && $pendingPersonalBv <= 0) {
            return 'Nothing is pending for your '.$side.' side — no '.$side.' Genos BV has arrived since the last 23:59 cut-off.'.$tail;
        }
        $parts = [];
        if ($todayBv > 0) {
            $parts[] = \App\Modules\Shared\Support\IndianNumber::format($todayBv, 0).' BV of '.$side.' Genos business today';
        }
        if ($pendingPersonalBv > 0) {
            $parts[] = \App\Modules\Shared\Support\IndianNumber::format($pendingPersonalBv, 0).' BV of your own purchase, which goes to whichever side is weaker at that moment (only if a side has reached the first slab)';
        }

        return 'Pending tonight\'s 23:59 cut-off: '.implode(' and ', $parts).'.'.$tail;
    };

    // With the GSB flag off there is no cut-off, no eligibility minimum and no
    // matching — every GSB-mechanics phrase disappears from the page.
    $eligibilityNote = (! $gsbOn || $genosBvEligible)
        ? 'as of last page load'
        : 'requires '.$gsbMinBv.' BV of personal purchases';
    $eligibilityTipSuffix = $gsbOn
        ? ' Genos BV is counted only after your lifetime personal BV reaches '.$gsbMinBv.' BV; until then it shows as 0.'
        : '';
    $todayBvTipSuffix = $gsbOn ? ' — that is, since the last 23:59 cut-off. It does not include BV carried over from earlier days' : '';


    $cardClasses = 'bg-white rounded-2xl border border-gray-200 p-5';
    $statLabelClasses = 'text-xs text-gray-600 font-medium';
    $statValueClasses = 'text-2xl font-bold text-gray-900';
@endphp
<div>
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Business World</h1>

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
        <div class="relative overflow-hidden rounded-2xl border border-leaf-200 bg-gradient-to-br from-leaf-50 via-white to-white p-5 shadow-sm sm:col-span-2">
            <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-leaf-400 to-leaf-600" aria-hidden="true"></span>
            <div class="flex items-start justify-between gap-2 mb-3">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl shadow-md bg-leaf-500 text-white shadow-leaf-500/30">
                    <x-lucide-shopping-bag class="w-5 h-5" />
                </span>
                <x-help-tip text="The total Business Volume from your own personal purchases since joining. It is a lifetime running total and never resets, and it is what decides your purchase title." />
            </div>
            <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">Personal BV (lifetime)</p>
            <p class="mt-1 text-xl sm:text-2xl font-bold text-leaf-800 leading-tight">{{ $personalBvPaise !== null ? Number::format($personalBvPaise / 100, 0) : '—' }}</p>
            <p class="mt-1 text-[11px] text-gray-600 leading-snug flex items-center gap-1">
                Title: {{ $title?->title ?? 'No title yet' }}
                <x-help-tip text="Your title comes from the personal purchase ladder — it moves up as your lifetime personal BV grows. Below 3,000 BV of personal purchases no title is held yet, which is shown as 'No title yet'." />
            </p>
        </div>
        <div class="relative overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 via-white to-white p-5 shadow-sm sm:col-span-3">
            <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-400 to-brand-600" aria-hidden="true"></span>
            <div class="flex items-start justify-between gap-2 mb-3">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl shadow-md bg-brand-500 text-white shadow-brand-500/30">
                    <x-lucide-wallet class="w-5 h-5" />
                </span>
                <x-help-tip :text="($payoutCadencePublished
                    ? 'Weekly income for each Wednesday-to-Tuesday earning week is paid on the following Tuesday (03:00 IST), so the '.$nextPayout->format('d M Y').' transfer covers earnings through '.\App\Modules\Compensation\Models\PayoutBatch::weeklyEarningWindow($nextPayout)['end']->format('d M Y').'; monthly bonus income transfers in the monthly payout on the 8th.'
                    : 'Weekly income is transferred in the Tuesday payout run (03:00 IST); monthly bonus income transfers in the monthly payout run.')
                    .' This is the balance sitting in your wallet right now, not a forecast. The repurchase deduction was already taken when each bonus was credited; at payout the balance is transferred after the 3% admin charge and 5% TDS; a balance below the minimum payout amount is not transferred and simply stays in your wallet for the following payout.'" />
            </div>
            <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-600">Next payout — Tuesday, {{ $nextPayout->format('d M Y') }}</p>
            <p class="mt-1 text-xl sm:text-2xl font-bold text-brand-800 leading-tight">₹{{ $walletBalancePaise !== null ? Number::format($walletBalancePaise / 100, 2) : '—' }}</p>
            <p class="mt-1 text-[11px] text-gray-600 leading-snug">Already net of the repurchase deduction. Transferred after 3% admin charge + 5% TDS.</p>
            <p class="mt-0.5 text-[11px] text-gray-500 leading-snug">Current wallet balance</p>
        </div>
    </div>

    {{-- Today's business was forfeited by an unmet repurchase period (client, 2026-09-07). --}}
    @if($gsbOn && ($slabProgress?->forfeitedToday ?? false))
    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-800 mb-4 flex items-start gap-2">
        <x-lucide-circle-alert class="w-4 h-4 mt-0.5 shrink-0" />
        <span>
            Repurchase not met — today's Genos BV was not counted.
            Your repurchase period closed without being met, so today's Left and Right Genos business
            was not added to either side and no slab was matched for today. Your carried-over BV on
            both sides is untouched and counting resumes on the day you meet the condition.
            <x-help-tip text="The figures below show your carried-over BV as it stood before today, with none of today's business in them — today's business is not part of any slab progress." />
        </span>
    </div>
    @endif

    {{-- Group 3 — carry forward / carry over row (Left before Right; GSB matching mechanics) --}}
    @if($gsbOn)
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <div class="rounded-2xl border p-5 shadow-sm {{ $gl['card'] }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Left Genos carry forward</p>
                <x-help-tip text="The BV remaining on your Left side after your last slab match — when a slab pays, the weaker side resets to 0 and the power side's remaining BV is carried forward. Until your first slab matches this is 0: BV building up before a match is carry over, shown in the Carried-over cards.{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ \App\Modules\Shared\Support\IndianNumber::format($leftCarryForwardBv, 0) }}</p>
        </div>
        <div class="rounded-2xl border p-5 shadow-sm {{ $gl['card'] }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Carried-over Left Genos BV</p>
                <x-help-tip text="Business added on your Left side since your last slab match, as it stood after the last 23:59 cut-off. Together with your carry forward, it counts toward your next slab match.{{ ($slabProgress !== null && $settledWeakerSide === 'L' && $slabProgress->slab1WeakerCfPaise > 0) ? ' It includes '.\App\Modules\Shared\Support\IndianNumber::format($slabProgress->slab1WeakerCfPaise / 100, 0).' BV of slab-1 weaker carry over.' : '' }}{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ \App\Modules\Shared\Support\IndianNumber::format($leftSinceMatchBv, 0) }}</p>
            <p class="text-xs text-gray-600 mt-1 flex items-center gap-1">As of the last 23:59 cut-off <x-help-tip :text="$pendingTip('Left', $leftTodayBv)" /></p>
        </div>
        <div class="rounded-2xl border p-5 shadow-sm {{ $gr['card'] }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Carried-over Right Genos BV</p>
                <x-help-tip text="Business added on your Right side since your last slab match, as it stood after the last 23:59 cut-off. Together with your carry forward, it counts toward your next slab match.{{ ($slabProgress !== null && $settledWeakerSide === 'R' && $slabProgress->slab1WeakerCfPaise > 0) ? ' It includes '.\App\Modules\Shared\Support\IndianNumber::format($slabProgress->slab1WeakerCfPaise / 100, 0).' BV of slab-1 weaker carry over.' : '' }}{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ \App\Modules\Shared\Support\IndianNumber::format($rightSinceMatchBv, 0) }}</p>
            <p class="text-xs text-gray-600 mt-1 flex items-center gap-1">As of the last 23:59 cut-off <x-help-tip :text="$pendingTip('Right', $rightTodayBv)" /></p>
        </div>
        <div class="rounded-2xl border p-5 shadow-sm {{ $gr['card'] }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Right Genos carry forward</p>
                <x-help-tip text="The BV remaining on your Right side after your last slab match — when a slab pays, the weaker side resets to 0 and the power side's remaining BV is carried forward. Until your first slab matches this is 0: BV building up before a match is carry over, shown in the Carried-over cards.{{ $eligibilityTipSuffix }}" />
            </div>
            <p class="{{ $statValueClasses }}">{{ \App\Modules\Shared\Support\IndianNumber::format($rightCarryForwardBv, 0) }}</p>
        </div>
    </div>
    @endif

    {{-- Group 4 — team size + today's paid self orders, and today's Genos BV (Left before Right) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div data-team-card="left" class="rounded-2xl border p-5 shadow-sm {{ $gl['card'] }}">
            <div class="flex items-center justify-between mb-1">
                <p class="{{ $statLabelClasses }}">Left Genos total team</p>
                <x-help-tip text="Everyone placed anywhere in the Left side of your Genos, at any depth below you, and how many paid orders they placed for themselves today." />
            </div>
            <p class="flex items-center gap-2 text-2xl font-bold text-gray-900"
               aria-label="{{ $teamCounts['left_team'] ?? 0 }} members, {{ $ordersToday['left'] }} orders today">
                <span>{{ \App\Modules\Shared\Support\IndianNumber::format($teamCounts['left_team'] ?? 0, 0) }}</span>
                <x-lucide-arrow-right class="w-5 h-5 {{ $gl['text'] }}" aria-hidden="true" />
                <span>{{ \App\Modules\Shared\Support\IndianNumber::format($ordersToday['left'], 0) }}</span>
            </p>
            <p class="text-xs text-gray-600 mt-1">members → orders today</p>
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
        <div data-team-card="right" class="rounded-2xl border p-5 shadow-sm text-right {{ $gr['card'] }}">
            <div class="flex items-center justify-between flex-row-reverse mb-1">
                <p class="{{ $statLabelClasses }}">Right Genos total team</p>
                <x-help-tip text="Everyone placed anywhere in the Right side of your Genos, at any depth below you, and how many paid orders they placed for themselves today." />
            </div>
            <p class="flex items-center justify-end gap-2 text-2xl font-bold text-gray-900"
               aria-label="{{ $teamCounts['right_team'] ?? 0 }} members, {{ $ordersToday['right'] }} orders today">
                <span>{{ \App\Modules\Shared\Support\IndianNumber::format($ordersToday['right'], 0) }}</span>
                <x-lucide-arrow-left class="w-5 h-5 {{ $gr['text'] }}" aria-hidden="true" />
                <span>{{ \App\Modules\Shared\Support\IndianNumber::format($teamCounts['right_team'] ?? 0, 0) }}</span>
            </p>
            <p class="text-xs text-gray-600 mt-1">orders today ← members</p>
        </div>
    </div>
</div>
@endsection
