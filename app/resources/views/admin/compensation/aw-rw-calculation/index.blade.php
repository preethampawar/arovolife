@extends('admin.layouts.admin')
@section('title', 'Awards & Rewards Report')
@section('heading', 'Awards & Rewards (AW & RW) Monthly Report')

@section('content')

@developer
<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global Lifetime Awards & Rewards table — one row per award tranche.
    Tranche A is released on a rank's 1st qualification, B on the 2nd, C on the 3rd (the client, 2026-10-09).
    Awards are merchandise only, never cash.
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
        <option value="delivered" {{ $status === 'delivered' ? 'selected' : '' }}>Delivered</option>
        <option value="cancelled" {{ $status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
    </select>
    <x-ui.button >Apply</x-ui.button>
    @if($q || $month || $status)
    <a href="{{ route('admin.compensation.aw-rw-calculation.index') }}"
       class="text-sm text-gray-600 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.aw-rw-calculation.export', array_merge(array_filter(['q' => $q, 'month' => $month, 'status' => $status]), ['format' => 'xlsx'])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download Excel
    </a>
    <a href="{{ route('admin.compensation.aw-rw-calculation.export', array_merge(array_filter(['q' => $q, 'month' => $month, 'status' => $status]), ['format' => 'csv'])) }}"
       class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">
        CSV
    </a>
</form>

{{-- Per month: milestone header + how an award/reward is valued, with the month's per-rank figures --}}
@foreach($monthBlocks as $monthStart => $aw)
    @include('admin.compensation._formulas.awrw-month', ['aw' => $aw, 'monthStart' => $monthStart, 'open' => count($monthBlocks) === 1])
@endforeach

<x-ui.card flush>
    @if($rows->isEmpty())
    <x-ui.empty-state title="No award or reward records found." />
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Title</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Rank</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Month</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">
                        Tranche
                        <x-help-tip text="A is released on the rank's 1st qualification, B on the 2nd, C on the 3rd." />
                    </th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">
                        Award
                        <x-help-tip text="The merchandise awarded for this tranche. Awards are never paid in cash." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">
                        Amount
                        <x-help-tip text="The tranche amount, recorded on the milestone when it was earned." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0);
                    $rowNumber = ($rows->currentPage() - 1) * $rows->perPage() + $i + 1;
                    $statusBadges = [
                        'delivered'  => 'bg-green-100 text-green-700',
                        'pending'    => 'bg-amber-100 text-amber-700',
                        'cancelled'  => 'bg-red-100 text-red-700',
                    ];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-600">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.lifetime-awards.index') }}"
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
                    <td class="px-3 py-2">
                        <span class="font-medium text-purple-700">
                            {{ $row->rank_name ?? 'Rank '.$row->rank_number }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($row->triggered_month)->format('M Y') }}
                    </td>
                    <td class="px-3 py-2 text-center font-medium text-gray-800">{{ chr(64 + (int) $row->tranche) }}</td>
                    <td class="px-3 py-2">
                        @if($row->award_description)
                        <span class="font-medium text-gray-800">{{ $row->award_description }}</span>
                        @else
                        <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right font-semibold text-gray-800">
                        {{ \App\Modules\Shared\Support\IndianNumber::rupees((int) $row->amount_paise, 0) }}
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
</x-ui.card>

@endsection
