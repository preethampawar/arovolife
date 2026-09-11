@extends('admin.layouts.admin')
@section('title', 'Transfer '.$transfer->transfer_no)
@section('heading', 'Transfer '.$transfer->transfer_no)

@section('content')
@if($errors->has('transfer'))
    <div class="rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-3 mb-4">{{ $errors->first('transfer') }}</div>
@endif

<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
        <div><span class="block text-xs text-gray-500">From</span>{{ $transfer->fromWarehouse?->name }} ({{ $transfer->from_warehouse_code }})</div>
        <div><span class="block text-xs text-gray-500">To</span>{{ $transfer->toWarehouse?->name }} ({{ $transfer->to_warehouse_code }})</div>
        <div><span class="block text-xs text-gray-500">Status</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-gray-100 text-gray-700 border-gray-200">{{ ucfirst($transfer->status) }}</span>
        </div>
        <div><span class="block text-xs text-gray-500">Created</span>{{ $transfer->created_at->format('d M Y H:i') }}</div>
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Product</th>
                <th class="px-4 py-3 font-semibold">Batch</th>
                <th class="px-4 py-3 font-semibold w-32">Dispatched qty</th>
                @if($transfer->status === 'dispatched')
                    <th class="px-4 py-3 font-semibold w-40">Received qty</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($transfer->items as $item)
                <tr>
                    <td class="px-4 py-3 text-gray-700">{{ $item->variant?->variant_sku }} — {{ $item->variant?->product?->name }}</td>
                    <td class="px-4 py-3 font-mono text-gray-600">{{ $item->batch?->batch_no }}</td>
                    <td class="px-4 py-3 font-mono">{{ $item->qty }}</td>
                    @if($transfer->status === 'dispatched')
                        <td class="px-4 py-3">
                            <input type="number" form="receiveForm" name="received_qty[{{ $item->id }}]" min="0" max="{{ $item->qty }}" value="{{ $item->qty }}"
                                class="w-24 rounded-lg border border-gray-300 px-2 py-1 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="flex items-center gap-3">
    @if($transfer->status === 'draft')
        <form method="POST" action="{{ route('admin.inventory.transfers.dispatch', $transfer) }}" data-confirm-impact="Dispatch this transfer? Stock will leave {{ $transfer->from_warehouse_code }} immediately.">
            @csrf
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Dispatch</button>
        </form>
        <form method="POST" action="{{ route('admin.inventory.transfers.cancel', $transfer) }}">
            @csrf
            <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium">Cancel</button>
        </form>
    @elseif($transfer->status === 'dispatched')
        <form method="POST" action="{{ route('admin.inventory.transfers.receive', $transfer) }}" id="receiveForm" data-confirm-impact="Receive this transfer at {{ $transfer->to_warehouse_code }}? A shortfall below the dispatched quantity is written off.">
            @csrf
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Receive</button>
        </form>
    @endif
    <a href="{{ route('admin.inventory.transfers.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Back to transfers</a>
</div>
@endsection
