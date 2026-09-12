@extends('admin.layouts.admin')
@section('title', $title)
@section('heading', $title)

@section('content')
<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        <select name="warehouse_code" class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
            <option value="">All warehouses</option>
            @foreach($warehouses as $wh)
                <option value="{{ $wh->code }}" @selected($warehouseCode === $wh->code)>{{ $wh->name }} ({{ $wh->code }})</option>
            @endforeach
        </select>
        @if($dated)
        <input type="date" name="date_from" value="{{ $dateFrom }}" placeholder="From"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
        <input type="date" name="date_to" value="{{ $dateTo }}" placeholder="To"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
        @endif
        <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Filter</button>
    </form>
    <div class="flex items-center gap-2">
        <a href="{{ request()->fullUrlWithQuery(['format' => 'xlsx']) }}"
           class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Export Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}"
           class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">CSV</a>
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                @foreach($columns as $column)
                <th class="px-4 py-3 font-semibold {{ ($column['align'] ?? '') === 'right' ? 'text-right' : '' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($rows as $row)
            <tr class="hover:bg-gray-50">
                @foreach($columns as $column)
                @php $value = $row[$column['key']] ?? ''; @endphp
                <td class="px-4 py-3 {{ ($column['align'] ?? '') === 'right' ? 'text-right font-mono' : 'text-gray-700' }}">
                    {{ $value instanceof \Illuminate\Support\Carbon ? $value->format('d M Y, h:i A') : $value }}
                </td>
                @endforeach
            </tr>
            @empty
            <tr><td colspan="{{ count($columns) }}" class="px-4 py-10 text-center text-gray-600">No rows match this filter.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<p class="mt-3 text-xs text-gray-600">
    <a href="{{ route('admin.inventory.reports.index') }}" class="text-brand-700 hover:text-brand-800 font-medium">← All reports</a>
</p>
@endsection
