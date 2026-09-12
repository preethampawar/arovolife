@extends('admin.layouts.admin')
@section('title', 'GRN '.$invoice->grn_no)
@section('heading', 'Goods receipt: '.$invoice->grn_no)

@section('content')
@if(session('status'))
    <div class="rounded-lg border border-green-200 bg-green-50 text-green-700 text-sm px-4 py-3 mb-4">{{ session('status') }}</div>
@endif
@if($errors->has('grn'))
    <div class="rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-3 mb-4">{{ $errors->first('grn') }}</div>
@endif

<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border {{ $invoice->status === 'posted' ? 'bg-green-50 text-green-700 border-green-200' : ($invoice->status === 'cancelled' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-gray-100 text-gray-700 border-gray-200') }}">{{ ucfirst($invoice->status) }}</span>
    <div class="flex items-center gap-3">
        @if($invoice->isEditable())
            <a href="{{ route('admin.inventory.grns.edit', $invoice) }}" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Edit</a>
            <form method="POST" action="{{ route('admin.inventory.grns.post', $invoice) }}" data-confirm="Post this goods receipt?" data-confirm-title="Confirm GRN posting" data-confirm-impact="Post this GRN? This brings the stock on hand and cannot be undone by editing — only by cancelling.">
                @csrf
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Post GRN</button>
            </form>
        @endif
        @if($invoice->isPosted())
            <form method="POST" action="{{ route('admin.inventory.grns.cancel', $invoice) }}" class="flex items-center gap-2" data-confirm="Cancel this posted GRN?" data-confirm-title="Confirm GRN cancellation" data-confirm-impact="Cancel this posted GRN? This reverses the stock it brought in — only possible while every unit received is still on hand.">
                @csrf
                <input type="text" name="reason" placeholder="Reason (required)" required class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium">Cancel GRN</button>
            </form>
        @endif
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
    <div><span class="block text-xs text-gray-500">Supplier</span>{{ $invoice->supplier?->name ?? '—' }}</div>
    <div><span class="block text-xs text-gray-500">Purchase order</span>{{ $invoice->purchaseOrder?->po_no ?? '—' }}</div>
    <div><span class="block text-xs text-gray-500">Warehouse</span>{{ $invoice->warehouse_code }}</div>
    <div><span class="block text-xs text-gray-500">Supplier invoice</span>{{ $invoice->supplier_invoice_no }} ({{ $invoice->supplier_invoice_date?->format('d M Y') }})</div>
    @if($invoice->isPosted())
        <div><span class="block text-xs text-gray-500">Posted by</span>{{ $invoice->postedBy?->full_name ?? '—' }} on {{ $invoice->posted_at?->format('d M Y, h:i A') }}</div>
    @endif
    @if($invoice->notes)
        <div class="sm:col-span-4"><span class="block text-xs text-gray-500">Notes</span><span class="whitespace-pre-line">{{ $invoice->notes }}</span></div>
    @endif
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Product</th>
                <th class="px-4 py-3 font-semibold">Batch</th>
                <th class="px-4 py-3 font-semibold">Expiry</th>
                <th class="px-4 py-3 font-semibold text-right">Qty</th>
                <th class="px-4 py-3 font-semibold text-right">Unit cost</th>
                <th class="px-4 py-3 font-semibold text-right">GST %</th>
                <th class="px-4 py-3 font-semibold text-right">Line total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($invoice->items as $item)
                <tr>
                    <td class="px-4 py-3 text-gray-900">{{ $item->variant->variant_sku }} — {{ $item->variant->product->name }}</td>
                    <td class="px-4 py-3 font-mono">{{ $item->batch_no }}</td>
                    <td class="px-4 py-3 text-gray-600 text-xs">{{ $item->expiry_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ $item->qty }}</td>
                    <td class="px-4 py-3 text-right font-mono">₹{{ number_format($item->unit_cost_paise / 100, 2) }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ number_format($item->gst_rate_bp / 100, 2) }}</td>
                    <td class="px-4 py-3 text-right font-mono">₹{{ number_format($item->line_total_paise / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="px-4 py-2 text-right text-gray-600">Taxable</td>
                <td class="px-4 py-2 text-right font-mono">₹{{ number_format($invoice->subtotal_paise / 100, 2) }}</td>
            </tr>
            <tr>
                <td colspan="6" class="px-4 py-2 text-right text-gray-600">GST</td>
                <td class="px-4 py-2 text-right font-mono">₹{{ number_format($invoice->gst_paise / 100, 2) }}</td>
            </tr>
            <tr>
                <td colspan="6" class="px-4 py-3 text-right font-semibold text-gray-900">Total</td>
                <td class="px-4 py-3 text-right font-semibold font-mono text-gray-900">₹{{ number_format($invoice->total_paise / 100, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</div>
@endsection
