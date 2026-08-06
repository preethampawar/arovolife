@extends('admin.layouts.admin')
@section('title', 'Genos BV Transactions')
@section('heading', 'Genos BV Transactions')

@section('content')

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Global view of every group BV credit and cancelled-order reversal across all distributors.
    Use the search box to narrow by ADN or name. Credits show the BV that flowed into an upline's
    Left or Right group; Reversals show the BV deducted when the triggering order was cancelled.
</div>

{{-- Tabs --}}
<div class="flex gap-1 mb-4 border-b border-gray-200">
    <a href="{{ route('admin.compensation.genos-transactions.index', array_merge(request()->except('tab', 'page'), ['tab' => 'credits'])) }}"
       class="px-4 py-2 text-sm font-medium rounded-t-lg border-b-2 {{ $tab === 'credits' ? 'border-brand-500 text-brand-700 bg-white' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
        BV Credits
    </a>
    <a href="{{ route('admin.compensation.genos-transactions.index', array_merge(request()->except('tab', 'page'), ['tab' => 'reversals'])) }}"
       class="px-4 py-2 text-sm font-medium rounded-t-lg border-b-2 {{ $tab === 'reversals' ? 'border-red-500 text-red-700 bg-white' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
        BV Reversals
    </a>
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <input type="text" name="q" value="{{ $q ?? '' }}"
           placeholder="Search ADN or name…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-52">
    <input type="date" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" placeholder="From">
    <input type="date" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" placeholder="To">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($q || $from || $to)
    <a href="{{ route('admin.compensation.genos-transactions.index', ['tab' => $tab]) }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.genos-transactions.export', array_filter(['tab' => $tab, 'q' => $q, 'from' => $from, 'to' => $to])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No {{ $tab === 'reversals' ? 'reversal' : 'credit' }} records found.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium w-10">#</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Name</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Title</th>
                    <th class="px-3 py-2 text-center text-gray-500 font-medium">L/R</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Order</th>
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Order Date</th>
                    <th class="px-3 py-2 text-right text-gray-500 font-medium">BV</th>
                    @if($tab === 'reversals')
                    <th class="px-3 py-2 text-left text-gray-500 font-medium">Reversal Date</th>
                    <th class="px-3 py-2 text-right text-gray-500 font-medium">BV Reversed</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rows->items() as $i => $row)
                @php
                    $titleObj = $titleService->forBvPaise($personalBvMap[$row->ancestor_id] ?? 0);
                    $rowNumber = ($rows->currentPage() - 1) * $rows->perPage() + $i + 1;
                @endphp
                <tr class="{{ $tab === 'reversals' ? 'bg-red-50/30 hover:bg-red-50/60' : 'hover:bg-gray-50' }}">
                    <td class="px-3 py-2 text-gray-400">{{ $rowNumber }}</td>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', [$row->ancestor_id, 'tab' => 'genos-ledger']) }}"
                           class="text-brand-600 hover:underline">
                            {{ $row->ancestor_adn }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700">{{ $row->ancestor_name ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if($titleObj->title !== null)
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-700">
                            {{ $titleObj->title }}
                        </span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded font-semibold text-[11px] {{ $row->side === 'L' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' }}">
                            {{ $row->side }}
                        </span>
                    </td>
                    <td class="px-3 py-2">
                        <a href="{{ route('admin.commerce.orders.show', $row->order_id) }}"
                           class="text-brand-600 hover:underline font-mono">#{{ $row->order_id }}</a>
                    </td>
                    <td class="px-3 py-2 text-gray-600">
                        {{ $row->order_date ? \Illuminate\Support\Carbon::parse($row->order_date)->format('d/m/y') : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold {{ $tab === 'reversals' ? 'text-red-700' : 'text-green-700' }}">
                        @if($tab === 'reversals')
                        {{ \App\Modules\Shared\Support\IndianNumber::format($row->bv_paise / 100, 0) }}
                        @else
                        +{{ \App\Modules\Shared\Support\IndianNumber::format($row->bv_paise / 100, 0) }}
                        @if($row->debt_consumed_paise > 0)
                        <span class="block text-[10px] text-gray-400 font-normal">after {{ \App\Modules\Shared\Support\IndianNumber::format($row->debt_consumed_paise / 100, 0) }} adj.</span>
                        @endif
                        @endif
                    </td>
                    @if($tab === 'reversals')
                    <td class="px-3 py-2 text-gray-600">
                        {{ $row->reversed_at ? \Illuminate\Support\Carbon::parse($row->reversed_at)->format('d/m/y') : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold text-red-700">
                        −{{ \App\Modules\Shared\Support\IndianNumber::format($row->absorbed_paise / 100, 0) }}
                        @if($row->debt_paise > 0)
                        <span class="block text-[10px] text-amber-600 font-normal">+{{ \App\Modules\Shared\Support\IndianNumber::format($row->debt_paise / 100, 0) }} fwd debt</span>
                        @endif
                    </td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
