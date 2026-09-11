@extends('admin.layouts.admin')
@section('title', 'Goods Receipts (GRN)')
@section('heading', 'Goods Receipts (GRN)')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">The supplier invoice and the goods receipt are the same document. Posting one is what brings stock on hand.</p>
    <a href="{{ route('admin.inventory.grns.create') }}"
        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
        + New GRN
    </a>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">GRN No.</th>
                <th class="px-4 py-3 font-semibold">Supplier</th>
                <th class="px-4 py-3 font-semibold">Supplier invoice</th>
                <th class="px-4 py-3 font-semibold text-right">Total</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($invoices as $invoice)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ $invoice->grn_no }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $invoice->supplier?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-700 font-mono text-xs">{{ $invoice->supplier_invoice_no }}</td>
                    <td class="px-4 py-3 text-right font-mono">₹{{ number_format($invoice->total_paise / 100, 2) }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $invoice->status === 'posted' ? 'bg-green-50 text-green-700 border-green-200' : ($invoice->status === 'cancelled' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-gray-100 text-gray-600 border-gray-200') }}">{{ ucfirst($invoice->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.inventory.grns.show', $invoice) }}" class="text-brand-700 hover:text-brand-800 font-medium">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-gray-600">No goods receipts yet. <a href="{{ route('admin.inventory.grns.create') }}" class="text-brand-700 underline">Create one</a>.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $invoices->links() }}</div>
@endsection
