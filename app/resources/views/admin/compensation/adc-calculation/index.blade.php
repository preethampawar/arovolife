@extends('admin.layouts.admin')
@section('title', 'ADC Calculation Report')
@section('heading', 'Arete Development Center (ADC) Monthly Calculation Report')

@section('content')

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global monthly ADC Bonus calculation table — one row per Arete centre per month.
    <strong>Monthly Turnover BV</strong> = total BV of all members assigned to the centre.
    <strong>Rate %</strong> = gross ADC ÷ turnover BV × 100.
    Search by pincode, district, or state to filter by centre location.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="text" name="q" value="{{ $q ?? '' }}"
           placeholder="Search ADN or name…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-44">
    <input type="text" name="location" value="{{ $location ?? '' }}"
           placeholder="Pincode / district / state…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-52">
    <input type="month" name="month" value="{{ $month ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="credited" {{ $status === 'credited' ? 'selected' : '' }}>Credited</option>
        <option value="reversed" {{ $status === 'reversed' ? 'selected' : '' }}>Reversed</option>
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($q || $location || $month || $status)
    <a href="{{ route('admin.compensation.adc-calculation.index') }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.adc-calculation.export', array_filter(['q' => $q, 'location' => $location, 'month' => $month, 'status' => $status])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No ADC Bonus records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Title</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Rank</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Month</th>
                    <th class="px-3 py-2 text-right text-gray-500 font-medium">
                        Monthly Turnover BV
                        <x-help-tip text="Total BV contributed by all members of this Arete centre in the month." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-500 font-medium">
                        Rate %
                        <x-help-tip text="Gross ADC ÷ Turnover BV × 100. Reflects the effective payout rate after admin charge." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500 font-medium">Income (Net ADC)</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">
                        Pincode / District / State
                        <x-help-tip text="Centre location as registered. Use the location filter to narrow down by area." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-500 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0);
                    $rowNumber = ($rows->currentPage() - 1) * $rows->perPage() + $i + 1;
                    $ratePct = $row->total_member_bv_paise > 0
                        ? round($row->gross_paise / $row->total_member_bv_paise * 100, 2)
                        : 0;
                    $statusBadges = [
                        'credited' => 'bg-green-100 text-green-700',
                        'pending'  => 'bg-amber-100 text-amber-700',
                        'reversed' => 'bg-red-100 text-red-700',
                    ];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-400">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', [$row->distributor_id, 'tab' => 'adc-bonus']) }}"
                           class="text-brand-600 hover:underline">
                            {{ $row->adn }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700">{{ $row->full_name ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if($titleObj->title !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700">
                            {{ $titleObj->title }}
                        </span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        @if($row->rank_name)
                        <span class="font-medium text-purple-700">{{ $row->rank_name }}</span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($row->month_start)->format('M Y') }}
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="font-medium text-gray-800">@bv($row->total_member_bv_paise)</span>
                        @if($row->member_count > 0)
                        <span class="block text-[10px] text-gray-400">{{ $row->member_count }} members</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-semibold bg-purple-50 text-purple-700">
                            {{ $ratePct }}%
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="font-semibold text-green-700">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) }}
                        </span>
                        @if($row->tds_paise > 0 || $row->admin_charge_paise > 0)
                        <span class="block text-[10px] text-gray-400 font-normal">
                            gross ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }}
                            @if($row->admin_charge_paise > 0)
                            · adm ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->admin_charge_paise / 100, 2) }}
                            @endif
                            @if($row->tds_paise > 0)
                            · TDS ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}
                            @endif
                        </span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600">
                        @if($row->center_location)
                        <span class="text-gray-700">{{ $row->center_location }}</span>
                        <span class="block text-[10px] text-gray-400">{{ $row->center_name }}</span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusBadges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ $row->status }}
                        </span>
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
