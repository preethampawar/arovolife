@extends('layouts.app')
@section('title', 'My Income — Rank Bonus')

@section('content')
<div>
    <h1 class="text-2xl font-bold text-gray-900 mb-2">Rank Bonus</h1>

    @include('income._tabs')

    {{-- Page note --}}
    @developer
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        The Rank Bonus is paid monthly from each rank's pool (a share of the company's business volume for that month).
        Rank 1 (Silver) is points-based: each achiever earned 10 RAP and each AO-GO grantee 5 points that month; the pool was divided by the month's total points and your income is your points × the point value.
        Ranks 2–9 pools are split equally among that rank's achievers.
        Re-qualifying a rank you already achieved requires that month's repurchase BV and a cleared repurchase wallet.
        Admin charge (min of 3%, max ₹25,000 per monthly batch) and 5% TDS are deducted. Credited on the 8th of the following month.
    </div>
    @enddeveloper

    {{-- Rank status — the distributor's own standing, and the published
         conditions of the next rank measured against their own figures. --}}
    @if($rankStatus)
    <section class="bg-white rounded-2xl border border-gray-200 p-5 mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">My rank status</h2>
                <p class="text-xs text-gray-600 mt-0.5">Ranks are checked once a month. Everything below is your own recorded result.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <div class="rounded-xl border border-gray-200 px-4 py-2.5 text-center min-w-[140px]">
                    <p class="text-[11px] uppercase tracking-wider text-gray-600 flex items-center justify-center gap-1">
                        Current rank
                        <x-help-tip text="The rank you achieved this month, or last month while this month is still being counted." />
                    </p>
                    <p class="text-base font-bold text-indigo-700 mt-1">{{ $rankStatus->currentRankName() ?? 'No rank yet' }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-2.5 text-center min-w-[140px]">
                    <p class="text-[11px] uppercase tracking-wider text-gray-600 flex items-center justify-center gap-1">
                        Highest rank
                        <x-help-tip text="The highest rank you have ever achieved." />
                    </p>
                    <p class="text-base font-bold text-purple-700 mt-1">{{ $rankStatus->highestRankName() ?? '—' }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-2.5 text-center min-w-[140px]">
                    <p class="text-[11px] uppercase tracking-wider text-gray-600">This month</p>
                    <p class="text-base font-bold mt-1 {{ $rankStatus->qualifiedThisMonth ? 'text-green-700' : 'text-gray-600' }}">
                        {{ $rankStatus->qualifiedThisMonth ? ($rankStatus->rankNames[$rankStatus->thisMonthRank] ?? 'Achieved') : 'Not yet achieved' }}
                    </p>
                </div>
            </div>
        </div>

        @if($rankStatus->requalificationConditionsMet === false)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 mb-4">
            <strong>Requalification conditions not met this month.</strong>
            Achieving a rank you already hold pays only when the month's repurchase BV for that rank is completed and your repurchase wallet is cleared.
            <a href="{{ route('income.wallet') }}" class="underline">Check my wallet →</a>
        </div>
        @endif

        {{-- Achievement history: how many times each rank has been achieved. --}}
        @if($rankStatus->totalAchievements() > 0)
        <div class="mb-5">
            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2">Ranks achieved</p>
            <div class="flex flex-wrap gap-2">
                @foreach($rankStatus->achievementCounts as $rankNumber => $times)
                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-indigo-50 border border-indigo-100 text-sm text-indigo-800">
                    {{ $rankStatus->rankNames[$rankNumber] ?? 'Rank '.$rankNumber }}
                    <span class="text-xs text-indigo-600">×{{ $times }}</span>
                </span>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Next rank conditions — plan facts vs the distributor's own figures.
             No timeline, no earnings estimate (DSR 2021 r.5(1)(d)). --}}
        @if($rankStatus->nextRank !== null)
        <div>
            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3">
                Conditions for {{ $rankStatus->nextRankName() }}
            </p>
            <div class="space-y-3">
                @foreach($rankStatus->nextRequirements as $requirement)
                @php
                    $isBv = $requirement->unit === 'bv';
                    $currentLabel = $isBv
                        ? \App\Modules\Shared\Support\IndianNumber::format($requirement->current / 100, 0).' BV'
                        : \App\Modules\Shared\Support\IndianNumber::format($requirement->current);
                    $requiredLabel = $isBv
                        ? \App\Modules\Shared\Support\IndianNumber::format($requirement->required / 100, 0).' BV'
                        : \App\Modules\Shared\Support\IndianNumber::format($requirement->required);
                @endphp
                <div>
                    <div class="flex items-center justify-between text-sm mb-1">
                        <span class="text-gray-700 flex items-center gap-1">
                            {{ $requirement->label }}
                            @if($requirement->note)
                                <x-help-tip :text="$requirement->note" />
                            @endif
                        </span>
                        <span class="font-mono {{ $requirement->met() ? 'text-green-700 font-semibold' : 'text-gray-600' }}">
                            {{ $currentLabel }} <span class="text-gray-600">of</span> {{ $requiredLabel }}
                            @if($requirement->met()) <span class="ml-1">✓</span> @endif
                        </span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="{{ $requirement->met() ? 'bg-green-500' : 'bg-brand-700' }} h-1.5 rounded-full" style="width:{{ $requirement->percent() }}%"></div>
                    </div>
                </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-600 mt-3">
                These are the plan's published conditions for {{ $rankStatus->nextRankName() }} shown next to your own current figures. Meeting them is not a guarantee of any income.
            </p>
        </div>
        @endif
    </section>
    @endif

    {{-- AO-GO offer — shown to anyone the offer can apply to (a rank achieved
         at least once). Conditions only, in points; no rupee figure and no
         suggestion the offer will be granted (DSR 2021 r.5(1)(d)). --}}
    @if($aogoStatus && ($aogoStatus->everAchievedRank || $aogoStatus->usesUsed > 0))
    <section class="bg-white rounded-2xl border border-gray-200 p-5 mb-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">AO-GO offer</h2>
                <p class="text-xs text-gray-600 mt-0.5">Achieve Once – Get Once</p>
            </div>
            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-teal-50 border border-teal-100 text-sm text-teal-800">
                Used {{ $aogoStatus->usesUsed }} of {{ $aogoStatus->usesMax }}
                <span class="text-xs text-teal-600">{{ $aogoStatus->usesLeft() }} left</span>
            </span>
        </div>

        <p class="text-sm text-gray-600 mb-4">
            If you have achieved a rank in an earlier month and hold no rank in a later month, that month earns you
            <strong>{{ $aogoStatus->pointsPerGrant }} points</strong> in the Rank-1 pool — up to {{ $aogoStatus->usesMax }} times in your lifetime,
            never in two months running, and only after achieving a rank again between uses.
        </p>

        @if($aogoStatus->granted())
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            <strong>Applied this month.</strong>
            {{ $aogoStatus->grantedPoints }} points recorded in the Rank-1 pool for this month
            ({{ str_replace('_', ' ', $aogoStatus->grantedStatus ?? '') }}).
        </div>
        @else
        <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3">This month's conditions</p>
        <ul class="space-y-2">
            @foreach($aogoStatus->conditions as $condition)
            <li class="flex items-start gap-2 text-sm">
                <span class="mt-0.5 {{ $condition->met ? 'text-green-600' : 'text-gray-300' }}">{{ $condition->met ? '✓' : '○' }}</span>
                <span class="{{ $condition->met ? 'text-gray-700' : 'text-gray-600' }} flex items-center gap-1">
                    {{ $condition->label }}
                    @if($condition->note)
                        <x-help-tip :text="$condition->note" />
                    @endif
                </span>
            </li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-600 mt-3">
            These conditions are checked once a month, when the rank calculation runs. A month that misses them uses nothing —
            the offer stays available for a later month. Meeting them is not a guarantee of any income.
        </p>
        @endif
    </section>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 {{ ($aogoUsed ?? 0) > 0 ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }} gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Net Rank Bonus earned (page)</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows->isEmpty() ? '—' : '₹'.\App\Modules\Shared\Support\IndianNumber::format($totalNet / 100, 0) }}
            </p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Months credited</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows instanceof \Illuminate\Pagination\LengthAwarePaginator ? \App\Modules\Shared\Support\IndianNumber::format($rows->total()) : count($rows) }}
            </p>
        </div>
        @if(($aogoUsed ?? 0) > 0)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1 flex items-center justify-center gap-1">
                AO-GO offer used
                <x-help-tip text="Achieve Once – Get Once: if you lose your rank, you can earn 5 points in the Rank-1 pool up to {{ $aogoMax }} times in your lifetime — never in consecutive months, and only after re-achieving a rank between uses." />
            </p>
            <p class="text-2xl font-bold text-gray-900">{{ $aogoUsed }} of {{ $aogoMax }}</p>
        </div>
        @endif
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-600 mb-1">From (YYYY-MM)</label>
            <input type="month" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-600 mb-1">To (YYYY-MM)</label>
            <input type="month" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-700 text-white text-sm rounded-lg hover:bg-brand-800 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.rank-bonus') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-600 font-medium">No Rank Bonus yet.</p>
            <p class="text-sm text-gray-600 mt-1">Your Rank Bonus will appear here once you qualify for a rank in a calendar month.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[600px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Month</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Rank</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                Points × Value
                                <x-help-tip text="Rank 1 only: RAP (Rank Achievement Points, 10 per achiever) or AO-GO offer points (5), multiplied by the month's point value (Rank-1 pool ÷ total points). Ranks 2–9 are an equal split, shown as —." />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Gross</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                Admin <x-help-tip text="min(3% of gross, ₹25,000 per monthly batch)" />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">TDS (5%)</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Net</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                    $rankNames = app(\App\Modules\Compensation\Services\CompensationPlanSettingsService::class)->rankNames();
                    $sc = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'pending' => 'bg-gray-100 text-gray-600'];
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800">
                            {{ \Illuminate\Support\Carbon::parse($row->month_start)->format('F Y') }}
                        </td>
                        <td class="px-4 py-3">
                            @if($row->aogo_points !== null)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-teal-700">
                                AO-GO Offer
                            </span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                                {{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}
                            </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">
                            @php $points = $row->rap_points ?? $row->aogo_points; @endphp
                            @if($points !== null && $row->point_value_paise !== null)
                                {{ $points }} × ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->point_value_paise / 100, 0) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->admin_charge_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $sc[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ ucfirst($row->status) }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
