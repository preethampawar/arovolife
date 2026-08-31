@developer
<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Monthly Rank Bonus for this distributor. Rank 1 is points-based (RAP for achievers, AO-GO offer points for grantees; income = points × the month's point value); Ranks 2–9 split their pool equally among that rank's achievers.
    "requalification_held" = re-qualified but missed the month's requalification conditions (the rank's repurchase BV + a cleared repurchase wallet) — recorded, not paid.
</div>
@enddeveloper

{{-- Rank Bonus results --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No Rank Bonus history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600">Month</th>
                <th class="px-3 py-2 text-left text-gray-600">Rank</th>
                <th class="px-3 py-2 text-right text-gray-600">Points <x-help-tip text="RAP (Rank Achievement Points) for an achiever, or AO-GO offer points for a grantee. Ranks 2–9 are an equal split and show —." /></th>
                <th class="px-3 py-2 text-right text-gray-600">Point value</th>
                <th class="px-3 py-2 text-right text-gray-600">Pool</th>
                <th class="px-3 py-2 text-right text-gray-600">Gross</th>
                <th class="px-3 py-2 text-right text-gray-600">Admin</th>
                <th class="px-3 py-2 text-right text-gray-600">TDS</th>
                <th class="px-3 py-2 text-right text-gray-600">Net</th>
                <th class="px-3 py-2 text-center text-gray-600">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            @php
                $statusClass = match ($row->status) {
                    'credited' => 'bg-green-100 text-green-700',
                    'reversed' => 'bg-red-100 text-red-700',
                    'requalification_held' => 'bg-amber-100 text-amber-800',
                    default => 'bg-gray-100 text-gray-600',
                };
                $points = $row->rap_points ?? $row->aogo_points;
            @endphp
            <tr>
                <td class="px-3 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($row->month_start)->format('M Y') }}</td>
                <td class="px-3 py-2">
                    @if($row->aogo_points !== null)
                    <span class="inline-flex px-2 py-0.5 rounded-full bg-teal-100 text-teal-700 text-[10px] font-medium">AO-GO Offer</span>
                    @else
                    <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">{{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-right font-semibold">{{ $points ?? '—' }}</td>
                <td class="px-3 py-2 text-right text-gray-600">
                    @if($row->point_value_paise !== null)₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->point_value_paise / 100, 2) }}@else<span class="text-gray-600">—</span>@endif
                </td>
                <td class="px-3 py-2 text-right text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->pool_paise / 100, 0) }}</td>
                <td class="px-3 py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->admin_charge_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusClass }}">{{ str_replace('_', ' ', $row->status) }}</span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

{{-- Rank qualifications — the achievement history the bonus is paid from. --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
    <p class="px-4 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b border-gray-100">
        Rank qualifications (latest 24)
        <x-help-tip text="Every month this distributor met a rank's conditions. Ranks 1–2 record the month's Left/Right Genos BV; Ranks 3–9 qualify structurally (two prior-rank qualifiers per side) and record no BV." />
    </p>
    @if(empty($quals) || $quals->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No rank qualification yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600">Month</th>
                <th class="px-3 py-2 text-left text-gray-600">Rank</th>
                <th class="px-3 py-2 text-center text-gray-600">Occurrence</th>
                <th class="px-3 py-2 text-right text-gray-600">Left Genos BV</th>
                <th class="px-3 py-2 text-right text-gray-600">Right Genos BV</th>
                <th class="px-3 py-2 text-center text-gray-600">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($quals as $qual)
            <tr>
                <td class="px-3 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($qual->month_start)->format('M Y') }}</td>
                <td class="px-3 py-2">
                    <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">{{ $rankNames[$qual->rank_number] ?? 'Rank '.$qual->rank_number }}</span>
                    @if($qual->is_carry_forward)
                    <span class="inline-flex px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-[10px]">carry-forward</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-center">#{{ $qual->occurrence_in_month }}</td>
                <td class="px-3 py-2 text-right">@if($qual->left_genos_bv_paise !== null)@bv($qual->left_genos_bv_paise)@else<span class="text-gray-600">—</span>@endif</td>
                <td class="px-3 py-2 text-right">@if($qual->right_genos_bv_paise !== null)@bv($qual->right_genos_bv_paise)@else<span class="text-gray-600">—</span>@endif</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $qual->status === 'qualified' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ ucfirst($qual->status) }}</span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

{{-- AO-GO eligibility for the current month — the same four rules the monthly
     run applies, so support can answer "why no AO-GO this month?". --}}
@if(! empty($aogoStatus))
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
    <p class="px-4 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
        <span>
            AO-GO offer — this month
            <x-help-tip text="Evaluated live from what is recorded today. The grant itself is created only when the month's Rank Bonus run executes." />
        </span>
        <span class="font-normal text-gray-600">
            Used {{ $aogoStatus->usesUsed }} of {{ $aogoStatus->usesMax }} · {{ $aogoStatus->pointsPerGrant }} points per grant
        </span>
    </p>
    <div class="px-4 py-3">
        @if($aogoStatus->granted())
        <p class="text-xs text-green-700 font-medium mb-2">
            Granted this month — {{ $aogoStatus->grantedPoints }} points ({{ str_replace('_', ' ', $aogoStatus->grantedStatus ?? '') }}).
        </p>
        @else
        <p class="text-xs {{ $aogoStatus->conditionsMet ? 'text-green-700' : 'text-gray-600' }} font-medium mb-2">
            {{ $aogoStatus->conditionsMet ? 'All conditions currently met — the monthly run will grant.' : 'Not eligible right now.' }}
        </p>
        @endif
        <ul class="space-y-1">
            @foreach($aogoStatus->conditions as $condition)
            <li class="flex items-start gap-2 text-xs">
                <span class="{{ $condition->met ? 'text-green-600' : 'text-gray-300' }}">{{ $condition->met ? '✓' : '○' }}</span>
                <span class="{{ $condition->met ? 'text-gray-700' : 'text-gray-600' }} flex items-center gap-1">
                    {{ $condition->label }}
                    @if($condition->note)
                        <x-help-tip :text="$condition->note" />
                    @endif
                </span>
            </li>
            @endforeach
        </ul>
    </div>
</div>
@endif

{{-- AO-GO grants --}}
@if(! empty($aogoGrants) && $aogoGrants->isNotEmpty())
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <p class="px-4 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b border-gray-100">
        AO-GO grants
        <x-help-tip text="Achieve Once – Get Once: a degraded ex-rank-holder earns offer points in the Rank-1 pool, never in consecutive months, up to the lifetime cap." />
    </p>
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600">Month</th>
                <th class="px-3 py-2 text-center text-gray-600">Grant #</th>
                <th class="px-3 py-2 text-center text-gray-600">Previous rank</th>
                <th class="px-3 py-2 text-right text-gray-600">Points</th>
                <th class="px-3 py-2 text-right text-gray-600">Point value</th>
                <th class="px-3 py-2 text-right text-gray-600">Income</th>
                <th class="px-3 py-2 text-center text-gray-600">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($aogoGrants as $grant)
            <tr>
                <td class="px-3 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($grant->month_start)->format('M Y') }}</td>
                <td class="px-3 py-2 text-center">{{ $grant->grant_number }}</td>
                <td class="px-3 py-2 text-center">{{ $rankNames[$grant->previous_rank_number] ?? 'Rank '.$grant->previous_rank_number }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ $grant->points }}</td>
                <td class="px-3 py-2 text-right text-gray-600">
                    @if($grant->point_value_paise !== null)₹{{ \App\Modules\Shared\Support\IndianNumber::format($grant->point_value_paise / 100, 2) }}@else<span class="text-gray-600">—</span>@endif
                </td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">
                    @if($grant->income_paise !== null)₹{{ \App\Modules\Shared\Support\IndianNumber::format($grant->income_paise / 100, 2) }}@else<span class="text-gray-600">—</span>@endif
                </td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $grant->status === 'credited' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ ucfirst($grant->status) }}</span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
