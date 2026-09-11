@extends('admin.layouts.admin')
@section('title', 'Stock Transfers')
@section('heading', 'Stock Transfers')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">Stock in transit is not counted at either warehouse until it is received.</p>
    <a href="{{ route('admin.inventory.transfers.create') }}"
        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
        + New transfer
    </a>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Transfer No.</th>
                <th class="px-4 py-3 font-semibold">From</th>
                <th class="px-4 py-3 font-semibold">To</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($transfers as $transfer)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ $transfer->transfer_no }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $transfer->from_warehouse_code }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $transfer->to_warehouse_code }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-gray-100 text-gray-700 border-gray-200">{{ ucfirst($transfer->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.inventory.transfers.show', $transfer) }}" class="text-brand-700 hover:text-brand-800 font-medium">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-600">No transfers yet. <a href="{{ route('admin.inventory.transfers.create') }}" class="text-brand-700 underline">Create one</a>.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $transfers->links() }}</div>
@endsection
