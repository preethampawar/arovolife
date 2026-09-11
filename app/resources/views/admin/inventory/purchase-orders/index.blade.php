@extends('admin.layouts.admin')
@section('title', 'Purchase Orders')
@section('heading', 'Purchase Orders')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">The intent to buy. A purchase order moves no stock on its own — a posted GRN does.</p>
    <a href="{{ route('admin.inventory.purchase-orders.create') }}"
        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
        + New purchase order
    </a>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">PO No.</th>
                <th class="px-4 py-3 font-semibold">Supplier</th>
                <th class="px-4 py-3 font-semibold">Warehouse</th>
                <th class="px-4 py-3 font-semibold">Expected</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($orders as $po)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ $po->po_no }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $po->supplier?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $po->warehouse_code }}</td>
                    <td class="px-4 py-3 text-gray-600 text-xs">{{ $po->expected_at?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-gray-100 text-gray-700 border-gray-200">{{ str_replace('_', ' ', ucfirst($po->status)) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.inventory.purchase-orders.show', $po) }}" class="text-brand-700 hover:text-brand-800 font-medium">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-gray-600">No purchase orders yet. <a href="{{ route('admin.inventory.purchase-orders.create') }}" class="text-brand-700 underline">Create one</a>.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $orders->links() }}</div>
@endsection
