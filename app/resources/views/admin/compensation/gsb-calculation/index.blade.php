@extends('admin.layouts.admin')
@section('title', 'GSB Calculation Report')
@section('heading', 'GSB Calculation Report')

@section('content')

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global daily GSB slab calculation table — shows every distributor who matched a slab on each cutoff date.
    Only rows where a slab was awarded are included. Search by ADN or name, filter by date range and status.
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
        <option value="calculated" {{ $status === 'calculated' ? 'selected' : '' }}>Calculated</option>
        <option value="repurchase_held" {{ $status === 'repurchase_held' ? 'selected' : '' }}>Repurchase held</option>
        <option value="repurchase_suspended" {{ $status === 'repurchase_suspended' ? 'selected' : '' }}>Repurchase suspended</option>
        <option value="reversed" {{ $status === 'reversed' ? 'selected' : '' }}>Reversed</option>
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium">Apply</button>
    @if($q || $from || $to || $status || $slab)
    <a href="{{ route('admin.compensation.gsb-calculation.index') }}"
       class="text-sm text-gray-600 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.gsb-calculation.export', array_filter(['q' => $q, 'from' => $from, 'to' => $to, 'status' => $status, 'slab' => $slab])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No slab-earning records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Title</th>
                    <th class="px-3 py-2 text-left text-gray-600 font-medium">Date</th>
                    <th class="px-3 py-2 text-center text-gray-600 font-medium">Slab</th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">
                        Score
                        <x-help-tip text="The matched slab's score (snapshotted at cut-off). Income = score × the score value." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600 font-medium">
                        Score value
                        <x-help-tip text="Rupees per score point used for this row (snapshotted at cut-off). Slabs 1–2 are fixed; slabs 3–7 use the day's pro-rated pool value." />
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
                    $statusBadges = [
                        'credited'             => 'bg-green-100 text-green-700',
                        'reversed'             => 'bg-red-100 text-red-700',
                        'failed'               => 'bg-red-100 text-red-700',
                        'calculated'           => 'bg-blue-100 text-blue-700',
                        'repurchase_held'      => 'bg-amber-100 text-amber-700',
                        'repurchase_suspended' => 'bg-orange-100 text-orange-700',
                    ];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-600">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', [$row->distributor_id, 'tab' => 'genos-ledger']) }}"
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
                        {{ \Illuminate\Support\Carbon::parse($row->cutoff_date)->format('d/m/y') }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 font-bold text-[11px]">
                            {{ $row->slab }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right font-semibold text-gray-800">
                        {{ (int) $row->score }}
                        <span class="block text-[10px] text-gray-600 font-normal">
                            weaker @bv($row->weaker_bv_paise)
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right text-gray-700">
                        {{ $row->score_value_paise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($row->score_value_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="font-semibold {{ $row->status === 'reversed' ? 'text-red-600' : 'text-green-700' }}">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_gsb_paise / 100, 2) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusBadges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ str_replace('_', ' ', $row->status) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                <tr class="font-semibold text-gray-800">
                    <td class="px-3 py-2 text-right" colspan="6">Grand total (all filtered rows)</td>
                    <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($totalScore) }}</td>
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2 text-right text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($totalIncomePaise / 100, 2) }}</td>
                    <td class="px-3 py-2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
