{{-- ADC month header + per-centre bonus formula (aggregated adc_bonus_results + CURRENT rate/cap).
     Expects: array $adc (BonusCalculationSnapshots::adcMonths item), string $monthStart --}}
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    $num = \App\Modules\Shared\Support\IndianNumber::format(...);
    $pct = \App\Modules\Shared\Support\IndianNumber::percentFromBp($adc['rate_bp']);
@endphp
{{-- Collapsed by default; a page showing a single period opens it. The header row is the summary. --}}
<details class="group bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6"{{ ($open ?? false) ? ' open' : '' }}>
    <summary class="px-4 py-3 bg-gray-50 group-open:border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs list-none [&::-webkit-details-marker]:hidden cursor-pointer select-none" title="Show or hide the calculation">
        <x-lucide-chevron-right class="h-3.5 w-3.5 text-gray-400 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
        <span class="font-semibold text-gray-800">{{ \Illuminate\Support\Carbon::parse($monthStart)->format('F Y') }}</span>
        <span class="text-gray-500">Centres paid <strong class="text-gray-700">{{ $num($adc['centers']) }}</strong></span>
        <span class="text-gray-500">Member BV (all centres) <strong class="text-gray-700">@bv($adc['member_bv_paise'])</strong>
            <x-help-tip text="Net BV (refunds deducted) of every active member of each paid centre this month, as frozen on the rows below, summed across centres." /></span>
        <span class="text-gray-500">ADC rate ({{ $pct }})
            <x-help-tip text="The ADC rate and the monthly cap are CURRENT plan settings; the per-centre bonus on each row is what the engine froze on the run." />
            <strong class="text-gray-700">{{ $inr($adc['uncapped_paise']) }}</strong> <span class="text-gray-400">before caps</span></span>
        <span class="text-gray-500">Monthly cap <strong class="text-gray-700">{{ $inr($adc['cap_paise'], 0) }}</strong> <span class="text-gray-400">per centre</span></span>
        <span class="text-gray-500">ADC bonus paid <strong class="text-gray-700">{{ $inr($adc['gross_paise']) }}</strong></span>
        <span class="text-gray-500 ml-auto">Computed <strong class="text-gray-700">{{ $adc['computed_at']?->format('d M Y H:i') ?? '—' }}</strong>
            <x-help-tip text="When this month's ADC rows were written — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the month's end." /></span>
    </summary>

    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How each centre's ADC bonus is calculated</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> Member BV = Σ net BV of the centre's active members this month <span class="font-sans text-gray-500">(refunds deducted)</span></li>
                <li><span class="text-gray-500">2.</span> Flat bonus = ⌊ Member BV × ADC rate % ⌋</li>
                <li><span class="text-gray-500">3.</span> ADC bonus = min( Flat bonus, Monthly cap ) <span class="font-sans text-gray-500">(a centre's own lower cap override, if set, replaces the monthly cap)</span></li>
                <li><span class="text-gray-500">4.</span> Paid to the centre's assigned distributor; admin charge and TDS apply at payout</li>
            </ol>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this month's values (all centres together)</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> Member BV = <strong>@bv($adc['member_bv_paise'])</strong> across {{ $num($adc['centers']) }} centre{{ $adc['centers'] === 1 ? '' : 's' }}</li>
                <li><span class="text-gray-500">2.</span> @bv($adc['member_bv_paise']) × {{ $pct }} = <strong>{{ $inr($adc['uncapped_paise']) }}</strong></li>
                <li><span class="text-gray-500">3.</span> after the {{ $inr($adc['cap_paise'], 0) }} cap on {{ $num($adc['capped_centers']) }} centre{{ $adc['capped_centers'] === 1 ? '' : 's' }} = <strong>{{ $inr($adc['gross_paise']) }}</strong></li>
                <li class="font-sans text-gray-500">Each row below shows the same formula for one centre: its Member BV × {{ $pct }}, capped.</li>
            </ol>
        </div>
    </div>
</details>
