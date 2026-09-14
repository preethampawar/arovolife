@extends('admin.layouts.admin')
@section('title', 'Stock Adjustments')
@section('heading', 'Stock Adjustments')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">Physical counts, damage, expiry, theft — every change here is audited with a reason.</p>
    <x-ui.button href="{{ route('admin.inventory.adjustments.create') }}">
        {{ svg('lucide-plus', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} New adjustment
    </x-ui.button>
</div>

<x-filter-bar :filters="$filters" />

<x-ui.card flush>
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
                <x-ui.empty-state colspan="7" title="No adjustments yet.">
                    <x-ui.button href="{{ route('admin.inventory.adjustments.create') }}" variant="secondary" size="sm" icon="plus">Create one</x-ui.button>
                </x-ui.empty-state>
            @endforelse
        </tbody>
    </table>
</x-ui.card>

<div class="mt-4">{{ $adjustments->links() }}</div>
@endsection
