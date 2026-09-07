@extends('admin.layouts.admin')
@section('title', 'Daily Cut-offs')
@section('heading', 'Daily GSB Cut-offs')

@section('content')

@developer
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Each row is one 23:59 cut-off for one distributor. The slab is determined by the lower of the distributor's personal purchase title and the matched left/right Genos BV. After each cut-off: weaker leg resets to zero, power leg carries forward (capped 4,50,000 BV). Slab 1 (15,000 BV) is lifetime — the weaker leg accumulates until matched. Use <a href="{{ route('admin.compensation.manual-controls.index') }}" class="underline">Manual Controls</a> to retry failed rows or reverse incorrect credits.
</div>
@enddeveloper

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-5">
    <input type="date" name="date" value="{{ $date->toDateString() }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        @foreach(['credited', 'reversed', 'failed', 'repurchase_forfeited', 'no_match', 'frozen', 'below_600bv', 'calculated'] as $s)
        <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>
            {{ str_replace('_', ' ', ucfirst($s)) }}
        </option>
        @endforeach
    </select>
    <input type="text" name="q" value="{{ $q }}" placeholder="Search ADN…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-40">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium">Apply</button>
    <a href="{{ route('admin.compensation.daily-cutoffs.export', array_filter(['date' => $date->toDateString(), 'status' => $status, 'q' => $q])) }}"
       class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">⬇ CSV</a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-600 text-center">
        No cut-off data for {{ $date->format('d M Y') }}. GSB engine not yet active.
    </p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 w-12">S.No.</th>
                    <th class="px-3 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600">Name</th>
                    <th class="px-3 py-2 text-left text-gray-600">
                        Title <x-help-tip text="Personal purchase title based on lifetime BV. The GSB slab is capped at the title's max slab — e.g. a distributor without the Retailer title (3,000 BV) is capped at Slab 1." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        Left BV <x-help-tip text="Left Genos BV accumulated today (fresh, excluding carry-forward)." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        Right BV <x-help-tip text="Right Genos BV accumulated today (fresh, excluding carry-forward)." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600">
                        Slab <x-help-tip :text="$slabThresholdTip" />
                    </th>
                    <x-bonus-credit-head gross-label="Gross GSB" th-class="px-3 py-2 text-right text-gray-600" />
                    <th class="px-3 py-2 text-center text-gray-600">Status</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                    $badges = [
                        'credited'              => 'bg-green-100 text-green-700',
                        'reversed'              => 'bg-red-100 text-red-700',
                        'failed'                => 'bg-red-100 text-red-700',
                        'repurchase_forfeited'  => 'bg-red-100 text-red-700',
                        'no_match'              => 'bg-gray-100 text-gray-600',
                        'frozen'                => 'bg-blue-100 text-blue-700',
                        'below_600bv'           => 'bg-amber-100 text-amber-700',
                        'calculated'            => 'bg-purple-100 text-purple-700',
                    ];
                @endphp
                <tr class="{{ $row->status === 'failed' ? 'bg-red-50' : '' }}">
                    <td class="px-3 py-2 text-gray-500 tabular-nums">{{ $rows->firstItem() + $loop->index }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-700 hover:underline">
                            {{ $row->distributor->adn ?? '—' }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700 truncate max-w-[120px]">
                        {{ $row->distributor->user?->full_name ?? '—' }}
                    </td>
                    <td class="px-3 py-2 text-gray-600 text-[11px]">
                        {{ $titleMap[$row->distributor_id] ?? '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">@bv($row->left_bv_paise)</td>
                    <td class="px-3 py-2 text-right">@bv($row->right_bv_paise)</td>
                    <td class="px-3 py-2 text-center">{{ $row->slab ?? '—' }}</td>
                    <x-bonus-credit-cells td-class="px-3 py-2 text-right" :dash-when-zero="true" :gross="$row->gross_gsb_paise ?? 0" :deduction="$row->repurchase_deduction_paise ?? 0" :credited="$row->net_gsb_paise ?? 0" :is-credited="$row->status === 'credited'" />
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ str_replace('_', ' ', $row->status) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($row->status === 'failed')
                        <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $row->distributor->adn ?? '', 'action' => 'retry', 'date' => $row->cutoff_date->toDateString()]) }}"
                           class="text-[10px] px-2 py-0.5 rounded bg-amber-100 text-amber-800 hover:bg-amber-200 font-medium">Retry</a>
                        @elseif($row->status === 'credited')
                        <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $row->distributor->adn ?? '', 'action' => 'reverse', 'date' => $row->cutoff_date->toDateString()]) }}"
                           class="text-[10px] px-2 py-0.5 rounded bg-red-100 text-red-700 hover:bg-red-200 font-medium">Reverse</a>
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
