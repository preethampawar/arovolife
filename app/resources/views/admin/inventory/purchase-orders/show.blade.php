@extends('admin.layouts.admin')
@section('title', 'Purchase order '.$purchaseOrder->po_no)
@section('heading', 'Purchase order: '.$purchaseOrder->po_no)

@section('content')
@if(session('status'))
    <div class="rounded-lg border border-green-200 bg-green-50 text-green-700 text-sm px-4 py-3 mb-4">{{ session('status') }}</div>
@endif
@if($errors->has('po'))
    <div class="rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-3 mb-4">{{ $errors->first('po') }}</div>
@endif

<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-gray-100 text-gray-700 border-gray-200">{{ str_replace('_', ' ', ucfirst($purchaseOrder->status)) }}</span>
    <div class="flex items-center gap-3">
        @if($purchaseOrder->isEditable())
            <a href="{{ route('admin.inventory.purchase-orders.edit', $purchaseOrder) }}" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Edit</a>
            <form method="POST" action="{{ route('admin.inventory.purchase-orders.send', $purchaseOrder) }}" data-confirm="Send this purchase order?" data-confirm-title="Confirm send" data-confirm-impact="Send this purchase order to the supplier?">
                @csrf
                <x-ui.button >Send to supplier</x-ui.button>
            </form>
        @endif
        @if(in_array($purchaseOrder->status, ['sent', 'partially_received']))
            <x-ui.button :href="route('admin.inventory.grns.create', ['purchase_order_id' => $purchaseOrder->id])" icon="package-plus">Create GRN from this PO</x-ui.button>
        @endif
        @if(! in_array($purchaseOrder->status, ['received', 'cancelled']))
            <form method="POST" action="{{ route('admin.inventory.purchase-orders.cancel', $purchaseOrder) }}" data-confirm="Cancel this purchase order?" data-confirm-title="Confirm cancellation" data-confirm-impact="Cancel this purchase order?">
                @csrf
                <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium">Cancel</button>
            </form>
        @endif
    </div>
</div>

<x-ui.card padding="p-6 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm" class="mb-6">
    <div><span class="block text-xs text-gray-500">Supplier</span>{{ $purchaseOrder->supplier?->name ?? '—' }}</div>
    <div><span class="block text-xs text-gray-500">Warehouse</span>{{ $purchaseOrder->warehouse_code }}</div>
    <div><span class="block text-xs text-gray-500">Expected</span>{{ $purchaseOrder->expected_at?->format('d M Y') ?? '—' }}</div>
    <div><span class="block text-xs text-gray-500">Created by</span>{{ $purchaseOrder->createdBy?->full_name ?? '—' }}</div>
    @if($purchaseOrder->notes)
        <div class="sm:col-span-4"><span class="block text-xs text-gray-500">Notes</span><span class="whitespace-pre-line">{{ $purchaseOrder->notes }}</span></div>
    @endif
</x-ui.card>

<x-ui.card flush class="mb-6">
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Product</th>
                <th class="px-4 py-3 font-semibold text-right">Ordered</th>
                <th class="px-4 py-3 font-semibold text-right">Received</th>
                <th class="px-4 py-3 font-semibold text-right">Unit cost</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($purchaseOrder->items as $item)
                <tr>
                    <td class="px-4 py-3 text-gray-900">{{ $item->variant->variant_sku }} — {{ $item->variant->product->name }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ $item->qty_ordered }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ $item->qty_received }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ \App\Modules\Shared\Support\IndianNumber::rupees((int) $item->unit_cost_paise) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</x-ui.card>

@if($invoices->isNotEmpty())
<x-ui.card flush>
    <div class="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900">Goods receipts against this PO</div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">GRN No.</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3 font-semibold text-right">Total</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($invoices as $invoice)
                <tr>
                    <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ $invoice->grn_no }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ ucfirst($invoice->status) }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ \App\Modules\Shared\Support\IndianNumber::rupees((int) $invoice->total_paise) }}</td>
                    <td class="px-4 py-3 text-right"><a href="{{ route('admin.inventory.grns.show', $invoice) }}" class="text-brand-700 hover:text-brand-800 font-medium">View</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</x-ui.card>
@endif
@endsection
