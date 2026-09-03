@extends('admin.layouts.admin')
@section('title', 'Fortune Bonus — '.$date->format('F Y'))
@section('heading', 'Fortune Bonus — '.$date->format('F Y'))

@section('content')

{{-- Frozen month economics (fortune_monthly_pools) --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
        <span class="font-semibold text-gray-800">
            Frozen month economics
            <x-help-tip text="Written once, before any credit, and never recomputed — a re-run prices against this snapshot so the month's economics never move under a distributor who was already paid." />
        </span>
        <span class="text-gray-600">Company BV
            <strong class="text-gray-700">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->company_bv_paise / 100, 2) : '—' }}</strong></span>
        <span class="text-gray-600">Pool rate
            <strong class="text-gray-700">{{ $pool ? \App\Modules\Shared\Support\IndianNumber::percentFromBp($pool->pool_rate_bp) : '—' }}</strong></span>
        <span class="text-gray-600">Pool
            <strong class="text-indigo-700">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 2) : '—' }}</strong></span>
        <span class="text-gray-600">Total FB points
            <x-help-tip text="The sum of every enrolled participant's FB points for the month — the denominator of the point value." />
            <strong class="text-gray-700">{{ $pool ? \App\Modules\Shared\Support\IndianNumber::format($pool->total_points) : '—' }}</strong></span>
        <span class="text-gray-600">Point value
            <x-help-tip text="Cascade months price every level against the pool and points carried forward from the level above, each with its own per-member cap — see the per-level table below. Months run before the cascade carry one month-wide value." />
            <strong class="text-gray-700">{{ $pool ? ($pool->point_value_paise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->point_value_paise / 100, 2) : 'Per level') : '—' }}</strong></span>
        <span class="text-gray-600">Minimum guarantee
            <x-help-tip text="Every qualifier's guaranteed minimum × the number of qualifiers, reserved off the pool before the cascade distributes." />
            <strong class="text-gray-700">{{ $pool && $pool->guaranteed_total_paise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->guaranteed_total_paise / 100, 2) : '—' }}</strong></span>
        <span class="text-gray-600">Payout
            <strong class="text-green-700">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->payout_paise / 100, 2) : '—' }}</strong></span>
        <span class="text-gray-600">Leftover
            <x-help-tip text="The part of the pool the cascade could not distribute — whole-rupee flooring remainders and the headroom left by per-level caps." />
            <strong class="{{ $pool && $pool->leftover_paise < 0 ? 'text-red-600' : 'text-gray-700' }}">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->leftover_paise / 100, 2) : '—' }}</strong></span>
    </div>
    @if($pool && $pool->is_shortfall)
    <p class="px-4 py-3 text-xs text-amber-700 bg-amber-50 border-t border-amber-200">
        Shortfall month — the pool could not cover the minimum guarantee for every qualifier, so each received the same pro-rated share of
        ₹{{ \App\Modules\Shared\Support\IndianNumber::format(($pool->shortfall_per_head_paise ?? 0) / 100, 2) }} and no point earnings were distributed.
    </p>
    @endif
    @unless($pool)
    <p class="px-4 py-3 text-xs text-gray-600">
        No frozen pool row for this month — the engine has not run for it yet.
    </p>
    @endunless
</div>

{{-- Frozen per-level cascade economics (fortune_monthly_pool_levels) --}}
@if($pool && $pool->point_value_paise === null && $pool->levels->isNotEmpty())
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 text-xs font-semibold text-gray-800">
        Frozen per-level economics
        <x-help-tip text="Levels settle top-down, each at the whole-rupee floor of the remaining pool over all remaining points, with a per-member ceiling that includes the guaranteed minimum (₹30 at level 9 = the minimum only). Legacy months may show Residual (one shared value, uncapped) or Flat-minimum levels from the rule in force before 03 Sep 2026." />
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">Level</th>
                    <th class="px-4 py-2 text-left font-medium">Mode</th>
                    <th class="px-4 py-2 text-right font-medium">Participants</th>
                    <th class="px-4 py-2 text-right font-medium">FB points</th>
                    <th class="px-4 py-2 text-right font-medium">Point value</th>
                    <th class="px-4 py-2 text-right font-medium">Cap / member</th>
                    <th class="px-4 py-2 text-right font-medium">Paid</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($pool->levels as $level)
                <tr>
                    <td class="px-4 py-2 font-medium text-gray-700">L{{ $level->matrix_level }}</td>
                    <td class="px-4 py-2 text-gray-600">{{ ['capped' => 'Capped', 'residual' => 'Residual', 'flat_min' => 'Flat minimum'][$level->payout_mode] ?? $level->payout_mode }}</td>
                    <td class="px-4 py-2 text-right text-gray-600">{{ \App\Modules\Shared\Support\IndianNumber::format($level->participants) }}</td>
                    <td class="px-4 py-2 text-right text-indigo-700">{{ \App\Modules\Shared\Support\IndianNumber::format($level->points) }}</td>
                    <td class="px-4 py-2 text-right text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($level->point_value_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-600">{{ $level->cap_paise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($level->cap_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-right text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($level->paid_paise / 100, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Per-depth points ladder (admin-editable under Plan settings → Fortune Bonus) --}}
<div class="mb-6 flex flex-wrap items-center gap-2 text-xs text-gray-600">
    <span class="font-medium text-gray-700">FB points per downline member
        <x-help-tip text="Points a participant earns for each enrolled distributor sitting this many levels below them in the month's matrix. Nothing is earned deeper than level 9." />
    </span>
    @foreach($levelPoints as $depth => $points)
        @if($depth > 0)
        <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700">L{{ $depth }} · {{ \App\Modules\Shared\Support\IndianNumber::format($points) }} pts</span>
        @endif
    @endforeach
</div>

{{-- Level summary cards --}}
@if($levelSummaries->isNotEmpty())
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
    @foreach($levelSummaries as $level => $summary)
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm text-center">
        <p class="text-xs font-medium text-gray-600 mb-1">Level {{ $level }}</p>
        <p class="text-sm font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($summary->participant_count) }} participants</p>
        <p class="text-xs text-indigo-700 font-medium mt-0.5">{{ \App\Modules\Shared\Support\IndianNumber::format((int) $summary->total_points) }} FB points</p>
        <p class="text-xs text-gray-600 mt-0.5">Net total: ₹{{ \App\Modules\Shared\Support\IndianNumber::format($summary->total_net_paise / 100, 2) }}</p>
    </div>
    @endforeach
</div>
@endif

{{-- Participants table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Matrix participants</span>
    </div>
    @if($rows->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-600 text-center">No participants enrolled for this month.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-right text-gray-600">#</th>
                    <th class="px-4 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-4 py-2 text-center text-gray-600">Level</th>
                    <th class="px-4 py-2 text-center text-gray-600">Tier</th>
                    <th class="px-4 py-2 text-left text-gray-600">First GSB date</th>
                    <th class="px-4 py-2 text-right text-gray-600">FB points</th>
                    <th class="px-4 py-2 text-right text-gray-600">Value</th>
                    <th class="px-4 py-2 text-right text-gray-600">Gross</th>
                    <th class="px-4 py-2 text-right text-gray-600">TDS</th>
                    <th class="px-4 py-2 text-right text-gray-600">Net</th>
                    <th class="px-4 py-2 text-center text-gray-600">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $participant)
                @php
                    $result = $resultsByDistributor[$participant->distributor_id] ?? null;
                    $sc = ['credited' => 'bg-green-100 text-green-700', 'skipped' => 'bg-gray-100 text-gray-600', 'pending' => 'bg-amber-100 text-amber-700'];
                @endphp
                <tr>
                    <td class="px-4 py-2 text-right font-mono text-gray-600">{{ \App\Modules\Shared\Support\IndianNumber::format($participant->position) }}</td>
                    <td class="px-4 py-2 font-mono">{{ $participant->distributor->adn ?? '—' }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 text-[10px] font-medium">L{{ $participant->matrix_level }}</span>
                    </td>
                    <td class="px-4 py-2 text-center text-gray-600">{{ str_replace('_', ' ', $participant->eligibility_tier) }}</td>
                    <td class="px-4 py-2 text-gray-600">{{ $participant->first_gsb_date ?? '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono text-indigo-700">{{ $result?->points !== null ? \App\Modules\Shared\Support\IndianNumber::format($result->points) : '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono text-gray-600">{{ $result?->point_value_paise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($result->point_value_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ $result ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($result->gross_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono text-gray-600">{{ $result ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($result->tds_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold {{ ($result?->net_paise ?? 0) > 0 ? 'text-green-700' : 'text-gray-600' }}">
                        {{ $result ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($result->net_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-4 py-2 text-center">
                        @if($result)
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$result->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($result->status) }}
                        </span>
                        @else
                        <span class="text-gray-600">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
