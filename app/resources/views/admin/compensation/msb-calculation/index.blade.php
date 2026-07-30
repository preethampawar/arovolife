@extends('admin.layouts.admin')
@section('title', 'MSB Calculation Report')
@section('heading', 'MSB Calculation Report')

@section('content')

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global daily Mentorship Bonus (MSB) calculation table — one row per sponsor–sponsee pair per cutoff date.
    When a sponsee's cut-off matches a GSB slab, the direct sponsor earns that slab's MSB points; income = points × the day's point value (the 3% MSB pool ÷ the day's total points — see <a href="{{ route('admin.compensation.msb-input-output.index') }}" class="underline">MSB Input &amp; Output / Day</a>).
    Search by sponsor or sponsee ADN/name, filter by date range, slab and status.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="text" name="q" value="{{ $q ?? '' }}"
           placeholder="Search ADN or name…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-52">
    <input type="date" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <input type="date" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <select name="slab" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All slabs</option>
        @foreach(range(1, 7) as $s)
        <option value="{{ $s }}" {{ (string) $slab === (string) $s ? 'selected' : '' }}>Slab {{ $s }}</option>
        @endforeach
    </select>
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        <option value="credited" {{ $status === 'credited' ? 'selected' : '' }}>Credited</option>
        <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Failed</option>
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($q || $from || $to || $status || $slab)
    <a href="{{ route('admin.compensation.msb-calculation.index') }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.msb-calculation.export', array_filter(['q' => $q, 'from' => $from, 'to' => $to, 'status' => $status, 'slab' => $slab])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No MSB records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Sponsor ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Sponsor Name</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Title</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Date</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Sponsee ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Sponsee Name</th>
                    <th class="px-3 py-2 text-right text-gray-500 font-medium">
                        MSB Points
                        <x-help-tip text="Points credited to the sponsor for the sponsee's matched slab (snapshotted at credit time). Income = points × value. Legacy rate-ladder rows show —." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500 font-medium">
                        Value
                        <x-help-tip text="Rupee value of one MSB point on the day it was credited: the day's 3% MSB pool ÷ the day's total MSB points, floored to whole rupees. Snapshotted per credit, so it never changes afterwards." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500 font-medium">Received Income</th>
                    <th class="px-3 py-2 text-center text-gray-500 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->sponsor_id] ?? 0);
                    $rowNumber = ($rows->currentPage() - 1) * $rows->perPage() + $i + 1;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-400">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', [$row->sponsor_id, 'tab' => 'mb']) }}"
                           class="text-brand-600 hover:underline">
                            {{ $row->sponsor_adn }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700">{{ $row->sponsor_name ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if($titleObj->title !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700">
                            {{ $titleObj->title }}
                        </span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($row->cutoff_date)->format('d/m/y') }}
                    </td>
                    <td class="px-3 py-2 font-mono text-gray-700">{{ $row->sponsee_adn }}</td>
                    <td class="px-3 py-2 text-gray-600">{{ $row->sponsee_name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right font-semibold text-gray-800">
                        @if($row->msb_points !== null)
                        {{ (int) $row->msb_points }}
                        @if($row->slab !== null)
                        <span class="block text-[10px] text-gray-400 font-normal">slab {{ $row->slab }}</span>
                        @endif
                        @else
                        <span class="text-gray-400 font-normal">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right text-gray-800">
                        @if($row->msb_point_value_paise !== null)
                        ₹{{ \Illuminate\Support\Number::format($row->msb_point_value_paise / 100, 2) }}
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="font-semibold {{ $row->status === 'failed' ? 'text-red-600' : 'text-green-700' }}">
                            ₹{{ \Illuminate\Support\Number::format($row->mb_gross_paise / 100, 2) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $row->status === 'credited' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $row->status }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                <tr class="font-semibold text-gray-800">
                    <td class="px-3 py-2 text-right" colspan="7">Grand total (all filtered rows)</td>
                    <td class="px-3 py-2 text-right">{{ \Illuminate\Support\Number::format($totalPoints) }}</td>
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2 text-right text-green-700">₹{{ \Illuminate\Support\Number::format($totalIncomePaise / 100, 2) }}</td>
                    <td class="px-3 py-2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
