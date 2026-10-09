{{-- Rank Bonus month header + point-value formula (frozen snapshot from the run).
     A two-pass month (client 2026-10-05: one pool, priced in two passes) shows the
     eight-step two-pass formula with the frozen pass rows; a month priced before
     that rule shows the old Rank 1 explanation under a legacy label.
     Expects: ?array $rank1 (BonusCalculationSnapshots::rankBonusMonth), Carbon $date, array $rankNames --}}
@if($rank1 !== null)
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    $fmt = \App\Modules\Shared\Support\IndianNumber::format(...);
    // Whole rupees print without paise; anything else keeps them.
    $whole = fn (int $paise): string => $inr($paise, $paise % 100 === 0 ? 0 : 2);
    $envelopePct = \App\Modules\Shared\Support\IndianNumber::percentFromBp($rank1['envelope_bp']);
    $twoPass = $rank1['passes'] !== [];
    $rank1Name = $rankNames[1] ?? 'Rank 1';

    if ($twoPass) {
        // The engine writes both pass rows in one transaction; a missing one is
        // an incomplete freeze and is said so, never zero-filled (principle 5).
        $p1 = $rank1['passes'][1] ?? null;
        $p2 = $rank1['passes'][2] ?? null;
        $passesIncomplete = $p1 === null || $p2 === null;
        // A pass with nobody priced in it has no value — "—", not ₹0.
        $passValue = fn (?array $pass): string => $pass !== null && $pass['total_points'] > 0 ? $whole($pass['point_value_paise']) : '—';
        // Frozen on the pass row — never recomputed.
        $envelopePaise = $rank1['envelope_paise'] ?? 0;
    } else {
        // Legacy month: turnover × the CURRENT envelope setting, integer only.
        $envelopePaise = max(0, intdiv($rank1['turnover_paise'] * $rank1['envelope_bp'], 10_000));
        $rawPointValue = ($rank1['total_points'] ?? 0) > 0 ? intdiv($rank1['pool_paise'], $rank1['total_points']) : null;
    }
@endphp
{{-- Collapsed by default; a page showing a single period opens it. The header row is the summary.
     With embedded => true it renders as a strip inside a report card that already shows the header. --}}
<details class="group {{ ($embedded ?? false) ? 'border-b border-gray-200' : 'bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6' }}"{{ ($open ?? false) ? ' open' : '' }}>
    <summary class="{{ ($embedded ?? false) ? 'px-4 py-2 hover:bg-gray-50' : 'px-4 py-3 bg-gray-50 group-open:border-b border-gray-200' }} flex flex-wrap items-center gap-x-6 gap-y-1 text-xs list-none [&::-webkit-details-marker]:hidden cursor-pointer select-none" title="Show or hide the calculation">
        <x-lucide-chevron-right class="h-3.5 w-3.5 text-gray-400 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
        @if($embedded ?? false)
        @if($twoPass)
        <span class="text-gray-600">How this month's two-pass point values were calculated</span>
        @else
        <span class="text-gray-600">How this month's {{ $rank1Name }} point value was calculated</span>
        @endif
        @else
        <span class="font-semibold text-gray-800">{{ $date->format('F Y') }}</span>
        <span class="text-gray-500">Month turnover
            <strong class="text-gray-700">@bv($rank1['turnover_paise'])</strong>
            <x-help-tip text="This month's accumulated company BV as the engine froze it on the run — the base of the rank envelope." /></span>
        @if($twoPass)
        <span class="text-gray-500">Rank envelope ({{ $envelopePct }})
            <x-help-tip text="The share of month turnover that funds the Rank Bonus — the one pool both passes divide. The envelope % and ₹ amount are frozen on the month's pass rows." />
            <strong class="text-gray-700">{{ $inr($envelopePaise) }}</strong></span>
        <span class="text-gray-500">Pass 1 value
            <strong class="text-gray-700">{{ $passValue($p1) }}</strong></span>
        <span class="text-gray-500">Pass 2 value
            <strong class="text-gray-700">{{ $passValue($p2) }}</strong></span>
        <span class="text-gray-500">Leftover
            <strong class="text-gray-700">{{ $p2 !== null ? $inr($p2['leftover_paise']) : '—' }}</strong>
            <x-help-tip text="What pass 2 left unpaid — it stays with the company." /></span>
        @if($passesIncomplete)
        <span class="text-amber-800 font-medium">Pass row missing — this month's freeze is incomplete.</span>
        @endif
        @else
        <span class="text-gray-500">Rank envelope ({{ $envelopePct }})
            <x-help-tip text="The share of month turnover that funds the Rank Bonus. The envelope % is a current plan setting, not a frozen snapshot — the ₹ pool, points and point value below ARE frozen on the run's result rows." />
            <strong class="text-gray-700">{{ $inr($envelopePaise) }}</strong></span>
        <span class="text-gray-500">{{ $rank1Name }} pool
            <strong class="text-gray-700">{{ $inr($rank1['pool_paise']) }}</strong></span>
        <span class="text-gray-500">Qualifiers <strong class="text-gray-700">{{ $fmt($rank1['qualifiers']) }}</strong></span>
        <span class="text-gray-500">Points <strong class="text-gray-700">{{ $rank1['total_points'] !== null ? $fmt($rank1['total_points']) : '—' }}</strong></span>
        <span class="text-gray-500">Point value
            <strong class="text-gray-700">{{ $rank1['point_value_paise'] !== null ? $inr($rank1['point_value_paise']) : '—' }}</strong></span>
        @endif
        <span class="text-gray-500 ml-auto">Computed
            <strong class="text-gray-700">{{ $rank1['computed_at']?->format('d M Y H:i') ?? '—' }}</strong>
            <x-help-tip text="When this month's Rank Bonus rows were written — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the month's end." /></span>
        @endif
    </summary>

    @if($twoPass)
    @if($passesIncomplete)
    <div class="px-4 pt-3 text-[11px] text-amber-800">
        Pass {{ $p1 === null ? 1 : 2 }} row missing — this month's freeze is incomplete, so the steps below cannot be shown. Rebuild the month before trusting any figure on it.
    </div>
    @endif
    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How the two-pass point values are calculated</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> Envelope = Month turnover × Rank envelope %</li>
                <li><span class="text-gray-500">2.</span> Pass 1 points = Σ (payable × RAP) of the pass-1 ranks + AO-GO points</li>
                <li><span class="text-gray-500">3.</span> Pass 1 value = min(cap, ⌊ Envelope ÷ Pass 1 points ⌋) <span class="font-sans text-gray-500">(floored to the whole rupee)</span></li>
                <li><span class="text-gray-500">4.</span> Pass 1 payout = Pass 1 points × Pass 1 value</li>
                <li><span class="text-gray-500">5.</span> Remainder = Envelope − Pass 1 payout</li>
                <li><span class="text-gray-500">6.</span> Pass 2 points = Σ (payable × RAP) of the pass-2 ranks</li>
                <li><span class="text-gray-500">7.</span> Pass 2 value = min(cap, ⌊ Remainder ÷ Pass 2 points ⌋)</li>
                <li><span class="text-gray-500">8.</span> Leftover = Remainder − Pass 2 points × Pass 2 value <span class="font-sans text-gray-500">(stays with the company)</span></li>
            </ol>
            <p class="mt-2 text-gray-500">Each achiever is paid own points × the value of the pass that priced their rank.</p>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this month's values</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> @bv($rank1['turnover_paise']) × {{ $envelopePct }} = <strong>{{ $inr($envelopePaise) }}</strong></li>
                @if($passesIncomplete)
                <li class="font-sans text-amber-800">2–8. Not shown — a pass row is missing.</li>
                @else
                <li><span class="text-gray-500">2.</span> Pass 1 points = <strong>{{ $fmt($p1['total_points']) }}</strong>@if($rank1['aogo_points'] > 0) <span class="font-sans text-gray-500">(of which AO-GO {{ $fmt($rank1['aogo_points']) }})</span>@endif</li>
                @if($p1['total_points'] > 0)
                <li><span class="text-gray-500">3.</span> min({{ $whole($p1['point_value_cap_paise']) }}, ⌊ {{ $inr($p1['pool_paise']) }} ÷ {{ $fmt($p1['total_points']) }} ⌋) = min({{ $whole($p1['point_value_cap_paise']) }}, {{ $whole($p1['raw_point_value_paise']) }}) = <strong>{{ $whole($p1['point_value_paise']) }}</strong></li>
                @else
                <li class="font-sans text-gray-500">3. No pass-1 points this month — pass 1 pays nothing and pass 2 divides the whole envelope.</li>
                @endif
                <li><span class="text-gray-500">4.</span> {{ $fmt($p1['total_points']) }} × {{ $whole($p1['point_value_paise']) }} = <strong>{{ $inr($p1['payout_paise']) }}</strong></li>
                <li><span class="text-gray-500">5.</span> {{ $inr($p1['pool_paise']) }} − {{ $inr($p1['payout_paise']) }} = <strong>{{ $inr($p2['pool_paise']) }}</strong></li>
                <li><span class="text-gray-500">6.</span> Pass 2 points = <strong>{{ $fmt($p2['total_points']) }}</strong></li>
                @if($p2['total_points'] > 0)
                <li><span class="text-gray-500">7.</span> min({{ $whole($p2['point_value_cap_paise']) }}, ⌊ {{ $inr($p2['pool_paise']) }} ÷ {{ $fmt($p2['total_points']) }} ⌋) = min({{ $whole($p2['point_value_cap_paise']) }}, {{ $whole($p2['raw_point_value_paise']) }}) = <strong>{{ $whole($p2['point_value_paise']) }}</strong></li>
                @else
                <li class="font-sans text-gray-500">7. No pass-2 points this month — the remainder goes unspent.</li>
                @endif
                <li><span class="text-gray-500">8.</span> {{ $inr($p2['pool_paise']) }} − {{ $inr($p2['payout_paise']) }} = <strong>{{ $inr($p2['leftover_paise']) }}</strong> <span class="font-sans text-gray-500">left with the company</span></li>
                @endif
            </ol>
        </div>
    </div>
    @else
    <div class="px-4 pt-3 text-[11px] text-amber-800">
        This month was priced under the per-rank pool rule in force before the two-pass rule (client 2026-10-05); it has no pass summary.
    </div>
    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How the {{ $rank1Name }} point value is calculated</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> Rank envelope = Month turnover × Rank envelope %</li>
                <li><span class="text-gray-500">2.</span> Total points = (Qualifiers × RAP points) + AO-GO points</li>
                <li><span class="text-gray-500">3.</span> Point value = ⌊ {{ $rank1Name }} pool ÷ Total points ⌋ <span class="font-sans text-gray-500">(floored to the whole rupee; remainder stays unspent)</span></li>
                <li><span class="text-gray-500">4.</span> Gross per qualifier = RAP points × Point value</li>
            </ol>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this month's values</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> @bv($rank1['turnover_paise']) × {{ $envelopePct }} = <strong>{{ $inr($envelopePaise) }}</strong></li>
                @if($rank1['total_points'] !== null && $rank1['rap_points'] !== null)
                <li><span class="text-gray-500">2.</span> ({{ $fmt($rank1['qualifiers']) }} × {{ $fmt($rank1['rap_points']) }}) + {{ $fmt($rank1['aogo_points']) }} = <strong>{{ $fmt($rank1['total_points']) }}</strong></li>
                @if($rawPointValue !== null && $rank1['point_value_paise'] !== null)
                <li><span class="text-gray-500">3.</span> ⌊ {{ $inr($rank1['pool_paise']) }} ÷ {{ $fmt($rank1['total_points']) }} ⌋ = ⌊ {{ $inr($rawPointValue) }} ⌋ = <strong>{{ $inr($rank1['point_value_paise'], 0) }}</strong></li>
                <li><span class="text-gray-500">4.</span> {{ $fmt($rank1['rap_points']) }} × {{ $inr($rank1['point_value_paise'], 0) }} = <strong>{{ $inr($rank1['rap_points'] * $rank1['point_value_paise'], 0) }}</strong> per qualifier</li>
                @else
                <li class="font-sans text-gray-500">3–4. No points this month — the pool went unspent.</li>
                @endif
                @else
                <li class="font-sans text-gray-500">2–4. No point snapshot on this month's rows.</li>
                @endif
            </ol>
        </div>
    </div>
    @endif
</details>
@endif
