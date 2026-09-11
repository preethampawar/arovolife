@extends('admin.layouts.admin')
@section('title', 'Stock')
@section('heading', 'Stock on hand')

@section('content')
<form method="GET" class="flex items-center gap-3 mb-6 flex-wrap">
    <input type="text" name="q" value="{{ $search }}" placeholder="Search SKU or product name…"
        class="w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
    <select name="warehouse_code" class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
        <option value="">All warehouses</option>
        @foreach($warehouses as $wh)
            <option value="{{ $wh->code }}" @selected($warehouseCode === $wh->code)>{{ $wh->name }} ({{ $wh->code }})</option>
        @endforeach
    </select>
    <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Filter</button>
    @if($search !== '' || $warehouseCode !== '')
        <a href="{{ route('admin.inventory.stock.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Clear</a>
    @endif
</form>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Product</th>
                <th class="px-4 py-3 font-semibold">Warehouse</th>
                <th class="px-4 py-3 font-semibold text-right">On hand</th>
                <th class="px-4 py-3 font-semibold text-right">Reserved</th>
                <th class="px-4 py-3 font-semibold text-right">Available</th>
                <th class="px-4 py-3 font-semibold text-right">Reorder</th>
                <th class="px-4 py-3 font-semibold text-right">Value</th>
                <th class="px-4 py-3 font-semibold">Flag</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($levels as $level)
                @php
                    $available = max(0, $level->on_hand - $level->reserved);
                    $reorderLevel = (int) ($level->reorder_level ?? 0);
                    $status = $available <= 0 ? 'OUT' : ($reorderLevel > 0 && $available <= $reorderLevel ? 'LOW' : 'OK');
                    $key = $level->product_variant_id.'|'.$level->warehouse_code;
                    $batches = $batchesByKey->get($key, collect());
                    $value = $batches->sum(fn ($b) => $b->qty_on_hand * $b->unit_cost_paise);
                    $statusClasses = match ($status) {
                        'OUT' => 'bg-red-50 text-red-700 border-red-200',
                        'LOW' => 'bg-amber-50 text-amber-700 border-amber-200',
                        default => 'bg-green-50 text-green-700 border-green-200',
                    };
                @endphp
                <tr class="hover:bg-gray-50 align-top">
                    <td class="px-4 py-3 text-gray-700">{{ $level->variant_sku }} — {{ $level->product_name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $level->warehouse_code }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ $level->on_hand }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ $level->reserved }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ $available }}</td>
                    <td class="px-4 py-3 text-right font-mono text-gray-500">{{ $reorderLevel }}</td>
                    <td class="px-4 py-3 text-right font-mono">₹{{ number_format($value / 100, 2) }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $statusClasses }}">{{ $status }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        @can('inventory.manage')
                        <a href="{{ route('admin.inventory.adjustments.create', ['warehouse_code' => $level->warehouse_code, 'product_variant_id' => $level->product_variant_id]) }}"
                            class="text-brand-700 hover:text-brand-800 font-medium">Adjust</a>
                        @endcan
                    </td>
                </tr>
                @if($batches->isNotEmpty())
                <tr>
                    <td colspan="9" class="px-4 pb-3 pt-0">
                        <details class="text-xs text-gray-600">
                            <summary class="cursor-pointer text-gray-500">{{ $batches->count() }} batch(es)</summary>
                            <table class="w-full mt-2 text-xs">
                                <thead class="text-gray-500 text-left">
                                    <tr><th class="pr-3 py-1">Batch</th><th class="pr-3 py-1">Expiry</th><th class="pr-3 py-1 text-right">Qty on hand</th><th class="pr-3 py-1 text-right">Unit cost</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($batches as $batch)
                                    <tr>
                                        <td class="pr-3 py-1 font-mono">{{ $batch->batch_no }}</td>
                                        <td class="pr-3 py-1">{{ $batch->expiry_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="pr-3 py-1 text-right font-mono">{{ $batch->qty_on_hand }}</td>
                                        <td class="pr-3 py-1 text-right font-mono">₹{{ number_format($batch->unit_cost_paise / 100, 2) }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    </td>
                </tr>
                @endif
            @empty
                <tr><td colspan="9" class="px-4 py-10 text-center text-gray-600">No stock levels match this filter.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $levels->links() }}</div>
@endsection
