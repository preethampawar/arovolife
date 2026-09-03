@extends('admin.layouts.admin')
@section('title', 'Fortune Bonus Calculation Report')
@section('heading', 'Fortune Bonus Monthly Calculation Report')

@section('content')

@php
    $statusBadges = [
        'credited' => 'bg-green-100 text-green-700',
        'pending'  => 'bg-amber-100 text-amber-700',
        'skipped'  => 'bg-gray-100 text-gray-600',
    ];
@endphp

@developer
<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global monthly Fortune Bonus (FB) calculation table — one row per distributor per month.
    Value = the whole-rupee point value applied at the distributor's matrix level in the month's
    cascade (the pool is 5% of company BV). Income includes the ₹30 minimum every qualifier
    receives and is capped per level (₹30,000 at levels 0–3, ₹20,000 / ₹10,000 / ₹5,000 at
    levels 4–6, ₹2,500 / ₹1,500 at levels 7–8, ₹30 at level 9). Level = matrix
    depth (0 = top); Date = the first GSB credit that enrolled them. Rows written before the
    cascade rework show "—" for points and value. Search by ADN or name.
</div>
@enddeveloper

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="text" name="q" value="{{ $q ?? '' }}"
           placeholder="Search ADN or name…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-52">
    <input type="month" name="month" value="{{ $month ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="credited" {{ $status === 'credited' ? 'selected' : '' }}>Credited</option>
        <option value="skipped" {{ $status === 'skipped' ? 'selected' : '' }}>Skipped</option>
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium">Apply</button>
    @if($q || $month || $status)
    <a href="{{ route('admin.compensation.fb-calculation.index') }}"
       class="text-sm text-gray-600 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.fb-calculation.export', array_filter(['q' => $q, 'month' => $month, 'status' => $status])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

{{-- Per month: frozen pool header + the cascade formula, symbolic then with the month's values --}}
@foreach($monthPools as $pool)
    @include('admin.compensation._formulas.fb-month', ['pool' => $pool, 'open' => count($monthPools) === 1])
@endforeach

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No Fortune Bonus records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium w-10">S.No</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Arete Center</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Title</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">Rank</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">
                        Date
                        <x-help-tip text="The first GSB credit of the month that enrolled this distributor in the matrix — their place in the first-come, first-served order." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">
                        Level
                        <x-help-tip text="Matrix depth — 0 is the top position." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">
                        FB Points
                        <x-help-tip text="Points earned from the enrolled distributors below them in the month's matrix." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">
                        Value
                        <x-help-tip text="The whole-rupee point value applied at this distributor's matrix level in the month's cascade. Income = the guaranteed minimum + points × this value, capped per level." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">Income</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0);
                    $rowNumber = ($rows->currentPage() - 1) * $rows->perPage() + $i + 1;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-600">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', [$row->distributor_id, 'tab' => 'fortune-bonus']) }}"
                           class="text-brand-700 hover:underline">
                            {{ $row->adn }}
                        </a>
                    </td>
                    {{-- ADC center name — Phase 7. Distributors are not assigned
                         to an Arete Center yet, so this reads "—" until then. --}}
                    <td class="px-3 py-2 text-gray-600">{{ $areteCenterMap[$row->distributor_id] ?? '—' }}</td>
                    <td class="px-3 py-2 text-gray-700">{{ $row->full_name ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if($titleObj->title !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700">
                            {{ $titleObj->title }}
                        </span>
                        @else
                        <span class="text-gray-600">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        @if($row->rank_name !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded bg-purple-100 text-purple-700 font-bold text-[10px] whitespace-nowrap">
                            {{ $row->rank_name }}
                        </span>
                        @else
                        <span class="text-gray-600">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                        {{ $row->first_gsb_date ? \Illuminate\Support\Carbon::parse($row->first_gsb_date)->format('d/m/y') : '—' }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 font-bold text-[11px]">
                            {{ $row->matrix_level }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right font-semibold text-indigo-700">
                        {{ $row->points !== null ? \App\Modules\Shared\Support\IndianNumber::format($row->points) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-600">
                        {{ $row->point_value_paise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($row->point_value_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="font-semibold {{ $row->status === 'skipped' ? 'text-gray-600' : 'text-green-700' }}">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }}
                        </span>
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
    <p class="px-4 py-2 text-xs text-gray-500 border-t border-gray-100">Income = ₹30 minimum (funded from the month's pool, pro-rated on a shortfall month) + FB Points × Value, limited to the level's maximum. The ₹30 is included in the single wallet credit and in the Income column; it is not a separate line.</p>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
