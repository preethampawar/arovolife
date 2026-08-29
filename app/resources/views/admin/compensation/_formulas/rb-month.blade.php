{{-- Rank 1 month header + point-value formula (frozen snapshot from the run).
     Expects: ?array $rank1 (BonusCalculationSnapshots::rankBonusMonth), Carbon $date, array $rankNames --}}
@if($rank1 !== null)
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    $envelopePct = \App\Modules\Shared\Support\IndianNumber::percentFromBp($rank1['envelope_bp']);
    $poolPct = \App\Modules\Shared\Support\IndianNumber::percent($rank1['pool_pct']);
    $envelopePaise = (int) round($rank1['turnover_paise'] * $rank1['envelope_bp'] / 10_000);
    $rank1Name = $rankNames[1] ?? 'Rank 1';
    $rawPointValue = ($rank1['total_points'] ?? 0) > 0 ? $rank1['pool_paise'] / $rank1['total_points'] : null;
@endphp
{{-- Collapsed by default; a page showing a single period opens it. The header row is the summary.
     With embedded => true it renders as a strip inside a report card that already shows the header. --}}
<details class="group {{ ($embedded ?? false) ? 'border-b border-gray-200' : 'bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6' }}"{{ ($open ?? false) ? ' open' : '' }}>
    <summary class="{{ ($embedded ?? false) ? 'px-4 py-2 hover:bg-gray-50' : 'px-4 py-3 bg-gray-50 group-open:border-b border-gray-200' }} flex flex-wrap items-center gap-x-6 gap-y-1 text-xs list-none [&::-webkit-details-marker]:hidden cursor-pointer select-none" title="Show or hide the calculation">
        <svg class="h-3.5 w-3.5 text-gray-400 shrink-0 transition-transform group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.17 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
        @if($embedded ?? false)
        <span class="text-gray-600">How this month's {{ $rank1Name }} point value was calculated</span>
        @else
        <span class="font-semibold text-gray-800">{{ $date->format('F Y') }}</span>
        <span class="text-gray-500">Month turnover
            <strong class="text-gray-700">@bv($rank1['turnover_paise'])</strong>
            <x-help-tip text="This month's accumulated company BV as the engine froze it on the run — the base of every rank pool." /></span>
        <span class="text-gray-500">Rank envelope ({{ $envelopePct }})
            <x-help-tip text="The share of month turnover that funds all nine rank pools. The envelope % and Rank 1's pool % are current plan settings, not frozen snapshots — the ₹ pool, points and point value below ARE frozen on the run's result rows." />
            <strong class="text-gray-700">{{ $inr($envelopePaise) }}</strong></span>
        <span class="text-gray-500">{{ $rank1Name }} pool ({{ $poolPct }} of envelope)
            <strong class="text-gray-700">{{ $inr($rank1['pool_paise']) }}</strong></span>
        <span class="text-gray-500">Qualifiers <strong class="text-gray-700">{{ $rank1['qualifiers'] }}</strong></span>
        <span class="text-gray-500">Points <strong class="text-gray-700">{{ $rank1['total_points'] ?? '—' }}</strong></span>
        <span class="text-gray-500">Point value
            <strong class="text-gray-700">{{ $rank1['point_value_paise'] !== null ? $inr($rank1['point_value_paise']) : '—' }}</strong></span>
        <span class="text-gray-500 ml-auto">Computed
            <strong class="text-gray-700">{{ $rank1['computed_at']?->format('d M Y H:i') ?? '—' }}</strong>
            <x-help-tip text="When this month's Rank Bonus rows were written — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the month's end." /></span>
        @endif
    </summary>

    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How the {{ $rank1Name }} point value is calculated</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> Rank envelope = Month turnover × Rank envelope %</li>
                <li><span class="text-gray-500">2.</span> {{ $rank1Name }} pool = Rank envelope × {{ $rank1Name }} pool %</li>
                <li><span class="text-gray-500">3.</span> Total points = (Qualifiers × RAP points) + AO-GO points</li>
                <li><span class="text-gray-500">4.</span> Point value = ⌊ {{ $rank1Name }} pool ÷ Total points ⌋ <span class="font-sans text-gray-500">(floored to the whole rupee; remainder stays unspent)</span></li>
                <li><span class="text-gray-500">5.</span> Gross per qualifier = RAP points × Point value</li>
            </ol>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this month's values</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> @bv($rank1['turnover_paise']) × {{ $envelopePct }} = <strong>{{ $inr($envelopePaise) }}</strong></li>
                <li><span class="text-gray-500">2.</span> {{ $inr($envelopePaise) }} × {{ $poolPct }} = <strong>{{ $inr($rank1['pool_paise']) }}</strong></li>
                @if($rank1['total_points'] !== null && $rank1['rap_points'] !== null)
                <li><span class="text-gray-500">3.</span> ({{ $rank1['qualifiers'] }} × {{ $rank1['rap_points'] }}) + {{ $rank1['aogo_points'] }} = <strong>{{ $rank1['total_points'] }}</strong></li>
                @if($rawPointValue !== null && $rank1['point_value_paise'] !== null)
                <li><span class="text-gray-500">4.</span> ⌊ {{ $inr($rank1['pool_paise']) }} ÷ {{ $rank1['total_points'] }} ⌋ = ⌊ {{ $inr((int) floor($rawPointValue)) }} ⌋ = <strong>{{ $inr($rank1['point_value_paise'], 0) }}</strong></li>
                <li><span class="text-gray-500">5.</span> {{ $rank1['rap_points'] }} × {{ $inr($rank1['point_value_paise'], 0) }} = <strong>{{ $inr($rank1['rap_points'] * $rank1['point_value_paise'], 0) }}</strong> per qualifier</li>
                @else
                <li class="font-sans text-gray-500">4–5. No points this month — the pool went unspent.</li>
                @endif
                @else
                <li class="font-sans text-gray-500">3–5. No point snapshot on this month's rows.</li>
                @endif
            </ol>
        </div>
    </div>
</details>
@endif
