@extends('admin.layouts.admin')
@section('title', 'GSB Personal BV Topups')
@section('heading', 'GSB Personal BV Topup Ledger')

@section('content')

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    Before each nightly GSB cut-off, a distributor's own day's personal order BV is injected into their weaker Genos leg to help them reach a slab. This ledger records every such injection and any subsequent reversal (triggered when the originating order is cancelled within the cooling-off period).
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-5">
    <input type="date" name="date" value="{{ $date->toDateString() }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <select name="type" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All entries</option>
        <option value="active"   {{ $type === 'active'   ? 'selected' : '' }}>Active topups</option>
        <option value="reversed" {{ $type === 'reversed' ? 'selected' : '' }}>Reversed only</option>
    </select>
    <input type="text" name="q" value="{{ $q }}" placeholder="Search ADN…"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-40">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium">Apply</button>
    <a href="{{ route('admin.compensation.personal-bv-topups.export', array_filter(['date' => $date->toDateString(), 'type' => $type, 'q' => $q])) }}"
       class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">⬇ CSV</a>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-600 text-center">
        No personal BV topups recorded for {{ $date->format('d M Y') }}.
    </p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600">Name</th>
                    <th class="px-3 py-2 text-right text-gray-600">Order</th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        BV Injected <x-help-tip text="Personal order BV added to the weaker Genos leg before the nightly cut-off." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600">
                        Side <x-help-tip text="The Genos leg (Left / Right) that received the injection — whichever had lower BV at the time." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600">Type</th>
                    <th class="px-3 py-2 text-left text-gray-600">Reversed At</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                <tr class="{{ $row->reversed_at ? 'bg-red-50/40' : '' }}">
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-700 hover:underline">
                            {{ $row->distributor->adn ?? '—' }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700 truncate max-w-[120px]">
                        {{ $row->distributor->user?->full_name ?? '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono text-gray-600">#{{ $row->order_id }}</td>
                    <td class="px-3 py-2 text-right font-medium">@bv($row->bv_paise)</td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium
                            {{ $row->side === 'L' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700' }}">
                            {{ $row->side === 'L' ? 'Left' : 'Right' }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        @if($row->reversed_at)
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-red-100 text-red-700">Reversed</span>
                        @else
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700">Topup</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 text-[11px]">
                        {{ $row->reversed_at?->format('d M Y, H:i') ?? '—' }}
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
