@extends('admin.layouts.admin')
@section('title', $purchaseOrder->exists ? 'Edit purchase order' : 'New purchase order')
@section('heading', $purchaseOrder->exists ? 'Edit purchase order: '.$purchaseOrder->po_no : 'New purchase order')

@section('content')
@php
    $isEdit = $purchaseOrder->exists;
    $action = $isEdit ? route('admin.inventory.purchase-orders.update', $purchaseOrder) : route('admin.inventory.purchase-orders.store');
    $existingLinesForJs = $isEdit
        ? $items->map(fn ($item) => [
            'product_variant_id' => $item->product_variant_id,
            'qty_ordered' => $item->qty_ordered,
            'unit_cost' => number_format($item->unit_cost_paise / 100, 2, '.', ''),
        ])
        : (old('lines') ?? []);
@endphp

<form method="POST" action="{{ $action }}" class="max-w-5xl space-y-6">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <p class="text-sm text-gray-600">A purchase order records intent to buy. It moves no stock on its own — posting a goods receipt against it does that.</p>

    @if($errors->has('po'))
        <div class="rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-3">{{ $errors->first('po') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Supplier <x-help-tip text="The supplier this order will be sent to." /></span>
                <select name="supplier_id" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Choose a supplier…</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $purchaseOrder->supplier_id) === $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Receiving warehouse <x-help-tip text="Where the goods will be received when a GRN is posted against this order." /></span>
                <select name="warehouse_code" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->code }}" @selected(old('warehouse_code', $purchaseOrder->warehouse_code) === $wh->code)>{{ $wh->name }} ({{ $wh->code }})</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Expected date <x-help-tip text="When the supplier is expected to deliver. Optional." /></span>
                <input type="date" name="expected_at" value="{{ old('expected_at', $purchaseOrder->expected_at?->toDateString()) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
        </div>
        <label class="block">
            <span class="block text-xs text-gray-700 mb-1 font-medium">Notes</span>
            <textarea name="notes" rows="2" maxlength="1000"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">{{ old('notes', $purchaseOrder->notes) }}</textarea>
        </label>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Line items</h2>
        <table class="w-full text-sm" id="poLinesTable">
            <thead class="text-gray-600 text-left">
                <tr>
                    <th class="py-2 pr-2 font-semibold">Product</th>
                    <th class="py-2 pr-2 font-semibold w-32">Qty ordered</th>
                    <th class="py-2 pr-2 font-semibold w-40">Unit cost (₹)</th>
                    <th class="py-2 pr-2 font-semibold w-40 text-right">Line total</th>
                    <th class="py-2 w-10"></th>
                </tr>
            </thead>
            <tbody id="poLinesBody" class="divide-y divide-gray-100"></tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="pt-3 text-right font-semibold text-gray-700">Total</td>
                    <td class="pt-3 text-right font-semibold text-gray-900" id="poGrandTotal">₹0.00</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <button type="button" id="poAddLine" class="text-sm text-brand-700 hover:text-brand-800 font-medium">+ Add line</button>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
            {{ $isEdit ? 'Save changes' : 'Create purchase order' }}
        </button>
        <a href="{{ $isEdit ? route('admin.inventory.purchase-orders.show', $purchaseOrder) : route('admin.inventory.purchase-orders.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</form>

<template id="poLineTemplate">
    <tr class="po-line">
        <td class="py-2 pr-2">
            <select name="lines[__INDEX__][product_variant_id]" class="po-variant w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500" required>
                <option value="">Choose a product…</option>
                @foreach($variants as $variant)
                    <option value="{{ $variant->id }}">{{ $variant->variant_sku }} — {{ $variant->product->name }}</option>
                @endforeach
            </select>
        </td>
        <td class="py-2 pr-2"><input type="number" min="1" step="1" name="lines[__INDEX__][qty_ordered]" class="po-qty w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500" required></td>
        <td class="py-2 pr-2"><input type="number" min="0" step="0.01" name="lines[__INDEX__][unit_cost]" class="po-cost w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500" required></td>
        <td class="py-2 pr-2 text-right font-mono po-line-total">₹0.00</td>
        <td class="py-2 text-right"><button type="button" class="po-remove-line text-red-600 hover:text-red-700 text-xs font-medium">Remove</button></td>
    </tr>
</template>

@push('scripts')
<script>
(function () {
    const body = document.getElementById('poLinesBody');
    const template = document.getElementById('poLineTemplate');
    const grandTotal = document.getElementById('poGrandTotal');
    let index = 0;

    const existingLines = @json($existingLinesForJs);

    function addLine(prefill) {
        const html = template.innerHTML.replaceAll('__INDEX__', index++);
        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = html.trim();
        const row = wrapper.firstElementChild;
        body.appendChild(row);

        if (prefill) {
            if (prefill.product_variant_id) row.querySelector('.po-variant').value = prefill.product_variant_id;
            if (prefill.qty_ordered) row.querySelector('.po-qty').value = prefill.qty_ordered;
            if (prefill.unit_cost) row.querySelector('.po-cost').value = prefill.unit_cost;
        }

        row.querySelector('.po-qty').addEventListener('input', recalc);
        row.querySelector('.po-cost').addEventListener('input', recalc);
        row.querySelector('.po-remove-line').addEventListener('click', function () {
            row.remove();
            recalc();
        });
        recalc();
    }

    function recalc() {
        let total = 0;
        body.querySelectorAll('.po-line').forEach(function (row) {
            const qty = parseFloat(row.querySelector('.po-qty').value) || 0;
            const cost = parseFloat(row.querySelector('.po-cost').value) || 0;
            const lineTotal = qty * cost;
            row.querySelector('.po-line-total').textContent = '₹' + lineTotal.toFixed(2);
            total += lineTotal;
        });
        grandTotal.textContent = '₹' + total.toFixed(2);
    }

    document.getElementById('poAddLine').addEventListener('click', function () { addLine(null); });

    if (existingLines.length > 0) {
        existingLines.forEach(addLine);
    } else {
        addLine(null);
    }
})();
</script>
@endpush
@endsection
