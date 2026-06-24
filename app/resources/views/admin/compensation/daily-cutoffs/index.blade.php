@extends('admin.layouts.admin')
@section('title', 'Daily Cut-offs')
@section('heading', 'Daily GSB Cut-offs')

@section('content')

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Each row is one 23:59 cut-off for one distributor. The slab is determined by the lower of the distributor's personal purchase title and the matched left/right group BV. After each cut-off: weaker leg resets to zero, power leg carries forward (capped 4,50,000 BV). Slab 1 (15,000 BV) is lifetime — the weaker leg accumulates until matched. Use <a href="{{ route('admin.compensation.manual-controls.index') }}" class="underline">Manual Controls</a> to retry failed rows or reverse incorrect credits.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-5">
    <input type="date" name="date" value="{{ $date->toDateString() }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        @foreach(['credited', 'failed', 'no_match', 'frozen', 'below_600bv', 'calculated'] as $s)
        <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>
            {{ str_replace('_', ' ', ucfirst($s)) }}
        </option>
        @endforeach
    </select>
    <input type="text" name="q" value="{{ $q }}" placeholder="Search ADN…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-40">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    <a href="{{ route('admin.compensation.daily-cutoffs.export', array_filter(['date' => $date->toDateString(), 'status' => $status, 'q' => $q])) }}"
       class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">⬇ CSV</a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-400 text-center">
        No cut-off data for {{ $date->format('d M Y') }}. GSB engine not yet active.
    </p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500">Name</th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Left BV <x-help-tip text="Left Genos group BV accumulated today (fresh, excluding carry-forward)." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Right BV <x-help-tip text="Right Genos group BV accumulated today (fresh, excluding carry-forward)." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-500">
                        Slab <x-help-tip text="Slab 1=15K, 2=30K, 3=90K, 4=2.7L, 5=8L, 6=24L, 7=72L BV matched on the weaker side." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Gross GSB <x-help-tip text="Before admin charge and TDS." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Admin 3% <x-help-tip text="3% of gross or ₹30,000 max." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        TDS 5% <x-help-tip text="5% of (gross − admin charge)." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Net GSB <x-help-tip text="Amount credited to wallet." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-500">Status</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                    $badges = [
                        'credited'    => 'bg-green-100 text-green-700',
                        'failed'      => 'bg-red-100 text-red-700',
                        'no_match'    => 'bg-gray-100 text-gray-500',
                        'frozen'      => 'bg-blue-100 text-blue-700',
                        'below_600bv' => 'bg-amber-100 text-amber-700',
                        'calculated'  => 'bg-purple-100 text-purple-700',
                    ];
                @endphp
                <tr class="{{ $row->status === 'failed' ? 'bg-red-50' : '' }}">
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-600 hover:underline">
                            {{ $row->distributor->adn ?? '—' }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700 truncate max-w-[120px]">
                        {{ $row->distributor->user?->full_name ?? '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">@bv($row->left_bv_paise)</td>
                    <td class="px-3 py-2 text-right">@bv($row->right_bv_paise)</td>
                    <td class="px-3 py-2 text-center">{{ $row->slab ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">
                        {{ $row->gross_gsb_paise ? '₹'.number_format($row->gross_gsb_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-500">
                        {{ $row->admin_charge_paise ? '₹'.number_format($row->admin_charge_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-500">
                        {{ $row->tds_paise ? '₹'.number_format($row->tds_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold {{ $row->net_gsb_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        {{ $row->net_gsb_paise > 0 ? '₹'.number_format($row->net_gsb_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badges[$row->status] ?? 'bg-gray-100 text-gray-500' }}">
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
