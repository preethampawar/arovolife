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
        <x-ui.button >Filter</x-ui.button>
    </form>
    <div class="flex items-center gap-2">
        {{-- exports.spec.js selects both of these by role and exact name
             ("Export Excel", "CSV"); the icons are aria-hidden so the
             accessible names are unchanged. --}}
        <x-ui.button :href="request()->fullUrlWithQuery(['format' => 'xlsx'])" icon="download">Export Excel</x-ui.button>
        <x-ui.button :href="request()->fullUrlWithQuery(['format' => 'csv'])" variant="secondary">CSV</x-ui.button>
    </div>
</div>

<x-ui.card flush>
    <div class="overflow-x-auto">
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
                @php
                    $value = $row[$column['key']] ?? '';
                    // Grouped here and only here. ReportExport reads the same
                    // rows and must stay ungrouped for spreadsheets, so the
                    // lakh grouping belongs to the screen, not to the row.
                    $display = match (true) {
                        $value instanceof \Illuminate\Support\Carbon => $value->format('d M Y, h:i A'),
                        is_int($value) || is_float($value) => \App\Modules\Shared\Support\IndianNumber::format($value),
                        default => $value,
                    };
                @endphp
                <td class="px-4 py-3 {{ ($column['align'] ?? '') === 'right' ? 'text-right font-mono' : 'text-gray-700' }}">
                    {{ $display }}
                </td>
                @endforeach
            </tr>
            @empty
            <x-ui.empty-state colspan="{{ count($columns) }}" title="No rows match this filter." />
            @endforelse
        </tbody>
    </table>
    </div>
</x-ui.card>

<p class="mt-3 text-xs text-gray-600">
    <a href="{{ route('admin.inventory.reports.index') }}" class="text-brand-700 hover:text-brand-800 font-medium">{{ svg('lucide-arrow-left', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} All reports</a>
</p>
@endsection
