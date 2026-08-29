@extends('admin.layouts.admin')
@section('title', 'GBB Monthly Calculation Report')
@section('heading', 'GBB Monthly Calculation Report')

@section('content')

@php
    $statusBadges = [
        'credited' => 'bg-green-100 text-green-700',
        'pending'  => 'bg-amber-100 text-amber-700',
        'reversed' => 'bg-red-100 text-red-700',
        'repurchase_held' => 'bg-orange-100 text-orange-700',
        'repurchase_suspended' => 'bg-red-100 text-red-700',
    ];
@endphp

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global monthly Growth Booster Bonus (GBB) calculation table — one row per distributor per month.
    AGP Points = slab occurrences earned that month. Point Value = the month's frozen pool ÷ total payable AGP.
    "repurchase_held" = calculated inside the repurchase grace window, released on repurchase completion.
    "repurchase_suspended" = the grace window lapsed, so the month is not payable.
    Search by ADN or name, filter by month and status.
</div>

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
        <option value="reversed" {{ $status === 'reversed' ? 'selected' : '' }}>Reversed</option>
        <option value="repurchase_held" {{ $status === 'repurchase_held' ? 'selected' : '' }}>Repurchase held</option>
        <option value="repurchase_suspended" {{ $status === 'repurchase_suspended' ? 'selected' : '' }}>Repurchase suspended</option>
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium">Apply</button>
    @if($q || $month || $status)
    <a href="{{ route('admin.compensation.gbb-calculation.index') }}"
       class="text-sm text-gray-600 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.gbb-calculation.export', array_filter(['q' => $q, 'month' => $month, 'status' => $status])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

{{-- Per month: frozen pool header + the AGP point-value formula, symbolic then with the month's values --}}
@foreach($monthPools as $pool)
    @include('admin.compensation._formulas.gbb-month', ['pool' => $pool])
@endforeach

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No GBB records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Title</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Month</th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">
                        AGP Points
                        <x-help-tip text="Total slab occurrences (AGP) earned by this distributor in the month." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">
                        Point Value
                        <x-help-tip text="The month's frozen point value: GBB pool ÷ total payable AGP, floored to the rupee. Blank (—) on rows recorded before this snapshot existed." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">
                        AGP Value
                        <x-help-tip text="Per-point pool share actually realised on this row = gross GBB ÷ AGP points earned." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">Income (Net GBB)</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0);
                    $rowNumber = ($rows->currentPage() - 1) * $rows->perPage() + $i + 1;
                    $agpValuePerPoint = $row->agp_earned > 0
                        ? $row->gbb_gross_paise / $row->agp_earned / 100
                        : 0;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-600">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', [$row->distributor_id, 'tab' => 'gbb']) }}"
                           class="text-brand-700 hover:underline">
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
                        <span class="text-gray-600">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($row->year_month)->format('M Y') }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold text-gray-800">
                        {{ \App\Modules\Shared\Support\IndianNumber::format($row->agp_earned) }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-600">
                        {{ $row->point_value_paise !== null
                            ? '₹'.\App\Modules\Shared\Support\IndianNumber::format((int) $row->point_value_paise / 100, 2)
                            : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-700">
                        ₹{{ \App\Modules\Shared\Support\IndianNumber::format($agpValuePerPoint, 2) }}
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="font-semibold {{ in_array($row->status, ['reversed', 'repurchase_suspended'], true) ? 'text-red-600' : ($row->status === 'repurchase_held' ? 'text-orange-700' : 'text-green-700') }}">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gbb_net_paise / 100, 2) }}
                        </span>
                        @if($row->tds_paise > 0)
                        <span class="block text-[10px] text-gray-600 font-normal">
                            gross ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gbb_gross_paise / 100, 2) }} · TDS ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}
                        </span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusBadges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ str_replace('_', ' ', $row->status) }}
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
