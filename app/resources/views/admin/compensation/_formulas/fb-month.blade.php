{{-- Fortune Bonus month header + cascade formula (frozen fortune_monthly_pools row + its level rows).
     Expects: \App\Modules\Compensation\Models\FortuneMonthlyPool $pool (levels loaded) --}}
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    $num = \App\Modules\Shared\Support\IndianNumber::format(...);
    $pct = \App\Modules\Shared\Support\IndianNumber::percentFromBp($pool->pool_rate_bp);
    $levels = $pool->levels;
    $minCommission = (int) ($pool->min_commission_paise ?? 0);
    $qualifiers = $levels->isNotEmpty()
        ? (int) $levels->sum('participants')
        : ($minCommission > 0 ? intdiv((int) ($pool->guaranteed_total_paise ?? 0), $minCommission) : 0);
    $guaranteed = (int) ($pool->guaranteed_total_paise ?? 0);
    $remaining = max(0, $pool->pool_paise - $guaranteed);
    $modeLabel = ['capped' => 'Capped', 'residual' => 'Residual', 'flat_min' => 'Minimum only'];
@endphp
{{-- Collapsed by default; a page showing a single period opens it. The header row is the summary. --}}
<details class="group bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6"{{ ($open ?? false) ? ' open' : '' }}>
    <summary class="px-4 py-3 bg-gray-50 group-open:border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs list-none [&::-webkit-details-marker]:hidden cursor-pointer select-none" title="Show or hide the calculation">
        <x-lucide-chevron-right class="h-3.5 w-3.5 text-gray-400 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
        <span class="font-semibold text-gray-800">{{ \Illuminate\Support\Carbon::parse($pool->month_start)->format('F Y') }}</span>
        <span class="text-gray-500">Month total BV <strong class="text-gray-700">@bv($pool->company_bv_paise)</strong>
            <x-help-tip text="This month's accumulated company BV as the engine froze it on the run — the base of the Fortune pool." /></span>
        <span class="text-gray-500">Fortune pool ({{ $pct }}) <strong class="text-gray-700">{{ $inr($pool->pool_paise) }}</strong>
            <x-help-tip text="The pool rate, minimum commission and every per-level value are frozen on the month's pool rows." /></span>
        <span class="text-gray-500">Qualifiers <strong class="text-gray-700">{{ $num($qualifiers) }}</strong></span>
        <span class="text-gray-500">Minimum guarantee ({{ $inr($minCommission, 0) }} each) <strong class="text-gray-700">{{ $inr($guaranteed) }}</strong></span>
        <span class="text-gray-500">Total points <strong class="text-gray-700">{{ $num((int) $pool->total_points) }}</strong></span>
        @if($pool->is_shortfall)
        <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-medium">Shortfall month</span>
        @endif
        <span class="text-gray-500 ml-auto">Computed <strong class="text-gray-700">{{ $pool->created_at?->format('d M Y H:i') ?? '—' }}</strong>
            <x-help-tip text="When this month's pool was frozen — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the month's end." /></span>
    </summary>

    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How the Fortune Bonus is calculated</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> Fortune pool = Month total BV × Fortune pool %</li>
                <li><span class="text-gray-500">2.</span> Minimum guarantee = Minimum commission × Qualifiers <span class="font-sans text-gray-500">(reserved off the pool first)</span></li>
                <li><span class="text-gray-500">3.</span> Remaining pool = Fortune pool − Minimum guarantee</li>
                <li><span class="text-gray-500">4.</span> Capped levels (ascending): Point value = ⌊ Remaining pool ÷ Points not yet paid ⌋; Income = Minimum + min( Points × Point value, Cap − Minimum )</li>
                <li><span class="text-gray-500">5.</span> Residual levels: one shared Point value = ⌊ Remaining pool ÷ Residual points ⌋; Income = Minimum + Points × Point value</li>
                <li><span class="text-gray-500">6.</span> Minimum-only levels: Income = Minimum</li>
                <li class="font-sans text-gray-500">If the pool cannot cover the minimum guarantee, every qualifier gets ⌊ Fortune pool ÷ Qualifiers ⌋ and nothing else.</li>
            </ol>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this month's values</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> @bv($pool->company_bv_paise) × {{ $pct }} = <strong>{{ $inr($pool->pool_paise) }}</strong></li>
                <li><span class="text-gray-500">2.</span> {{ $inr($minCommission, 0) }} × {{ $num($qualifiers) }} = <strong>{{ $inr($guaranteed) }}</strong></li>
                @if($pool->is_shortfall)
                <li><span class="text-gray-500">3.</span> {{ $inr($pool->pool_paise) }} &lt; {{ $inr($guaranteed) }} → shortfall: ⌊ {{ $inr($pool->pool_paise) }} ÷ {{ $num($qualifiers) }} ⌋ = <strong>{{ $inr((int) ($pool->shortfall_per_head_paise ?? 0), 0) }}</strong> per qualifier</li>
                @else
                <li><span class="text-gray-500">3.</span> {{ $inr($pool->pool_paise) }} − {{ $inr($guaranteed) }} = <strong>{{ $inr($remaining) }}</strong></li>
                @if($levels->isNotEmpty())
                @foreach($levels as $level)
                @php $earn = $level->payout_mode === 'flat_min' ? 0 : ($level->points > 0 ? $level->point_value_paise : 0); @endphp
                <li><span class="text-gray-500">{{ $loop->index + 4 }}.</span> Level {{ $level->matrix_level }} ({{ $modeLabel[$level->payout_mode] ?? $level->payout_mode }}, {{ $num((int) $level->participants) }} × {{ $level->participants > 0 ? $num(intdiv((int) $level->points, max(1, (int) $level->participants))) : 0 }} pts):
                    @if($level->payout_mode === 'flat_min')
                    {{ $inr($minCommission, 0) }} each = <strong>{{ $inr((int) $level->paid_paise) }}</strong>
                    @else
                    point value <strong>{{ $inr((int) $level->point_value_paise, 0) }}</strong>{{ $level->cap_paise !== null ? ', cap '.$inr((int) $level->cap_paise, 0) : '' }} → paid <strong>{{ $inr((int) $level->paid_paise) }}</strong>
                    @endif
                </li>
                @endforeach
                @elseif((int) $pool->total_points > 0 && $pool->point_value_paise !== null)
                <li><span class="text-gray-500">4.</span> ⌊ {{ $inr($remaining) }} ÷ {{ $num((int) $pool->total_points) }} ⌋ = <strong>{{ $inr((int) $pool->point_value_paise, 0) }}</strong> per point (single-value month, before the cascade)</li>
                @else
                <li class="font-sans text-gray-500">4–6. No points this month — the remaining pool went unspent.</li>
                @endif
                @endif
                <li class="font-sans text-gray-500">Paid {{ $inr((int) $pool->payout_paise) }} · leftover {{ $inr((int) $pool->leftover_paise) }}</li>
            </ol>
        </div>
    </div>
</details>
