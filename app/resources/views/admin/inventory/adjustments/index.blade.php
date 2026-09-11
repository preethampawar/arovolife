@extends('admin.layouts.admin')
@section('title', 'Stock Adjustments')
@section('heading', 'Stock Adjustments')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">Physical counts, damage, expiry, theft — every change here is audited with a reason.</p>
    <a href="{{ route('admin.inventory.adjustments.create') }}"
        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
        + New adjustment
    </a>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Adjustment No.</th>
                <th class="px-4 py-3 font-semibold">Warehouse</th>
                <th class="px-4 py-3 font-semibold">Product</th>
                <th class="px-4 py-3 font-semibold">Qty</th>
                <th class="px-4 py-3 font-semibold">Reason</th>
                <th class="px-4 py-3 font-semibold">By</th>
                <th class="px-4 py-3 font-semibold">When</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($adjustments as $adjustment)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ $adjustment->adjustment_no }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $adjustment->warehouse_code }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $adjustment->variant?->variant_sku }} — {{ $adjustment->variant?->product?->name }}</td>
                    <td class="px-4 py-3 font-mono {{ $adjustment->qty_delta > 0 ? 'text-green-700' : 'text-red-700' }}">{{ $adjustment->qty_delta > 0 ? '+' : '' }}{{ $adjustment->qty_delta }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ str_replace('_', ' ', ucfirst($adjustment->reason)) }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $adjustment->actor?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-600 text-xs">{{ $adjustment->occurred_at->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-gray-600">No adjustments yet. <a href="{{ route('admin.inventory.adjustments.create') }}" class="text-brand-700 underline">Create one</a>.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $adjustments->links() }}</div>
@endsection
