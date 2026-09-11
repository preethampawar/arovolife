@extends('admin.layouts.admin')
@section('title', $invoice->exists ? 'Edit GRN' : 'New GRN')
@section('heading', $invoice->exists ? 'Edit GRN: '.$invoice->grn_no : 'New goods receipt (GRN)')

@section('content')
@php
    $isEdit = $invoice->exists;
    $action = $isEdit ? route('admin.inventory.grns.update', $invoice) : route('admin.inventory.grns.store');
    $existingLinesForJs = $items->isNotEmpty() ? $items->values() : (old('lines') ?? []);
@endphp

<form method="POST" action="{{ $action }}" class="max-w-6xl space-y-6">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <p class="text-sm text-gray-600">The supplier invoice and the goods receipt are the same document here — posting it is what brings the stock on hand. Purchase lines are entered ex-GST.</p>

    @if($errors->has('grn'))
        <div class="rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-3">{{ $errors->first('grn') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Supplier <x-help-tip text="The supplier who raised the invoice." /></span>
                <select name="supplier_id" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Choose a supplier…</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $invoice->supplier_id) === $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Against purchase order <x-help-tip text="Optional. If this receipt fulfils a purchase order, choosing it here rolls the order's received quantities forward when posted." /></span>
                <select name="purchase_order_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">No purchase order</option>
                    @foreach($purchaseOrders as $po)
                        <option value="{{ $po->id }}" @selected((int) old('purchase_order_id', $invoice->purchase_order_id) === $po->id)>{{ $po->po_no }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Receiving warehouse <x-help-tip text="Where this stock physically arrives." /></span>
                <select name="warehouse_code" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->code }}" @selected(old('warehouse_code', $invoice->warehouse_code) === $wh->code)>{{ $wh->name }} ({{ $wh->code }})</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Supplier invoice no. <x-help-tip text="The invoice number printed on the supplier's own document. Cannot be entered twice for the same supplier." /></span>
                <input type="text" name="supplier_invoice_no" value="{{ old('supplier_invoice_no', $invoice->supplier_invoice_no) }}" maxlength="64" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Supplier invoice date</span>
                <input type="date" name="supplier_invoice_date" value="{{ old('supplier_invoice_date', $invoice->supplier_invoice_date?->toDateString()) }}" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
        </div>
        <label class="block">
            <span class="block text-xs text-gray-700 mb-1 font-medium">Notes</span>
            <textarea name="notes" rows="2" maxlength="1000"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">{{ old('notes', $invoice->notes) }}</textarea>
        </label>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4 overflow-x-auto">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Line items</h2>
        <table class="w-full text-sm min-w-[900px]" id="grnLinesTable">
            <thead class="text-gray-600 text-left">
                <tr>
                    <th class="py-2 pr-2 font-semibold">Product</th>
                    <th class="py-2 pr-2 font-semibold w-32">Batch no.</th>
                    <th class="py-2 pr-2 font-semibold w-36">Mfg date</th>
                    <th class="py-2 pr-2 font-semibold w-36">Expiry date</th>
                    <th class="py-2 pr-2 font-semibold w-24">Qty</th>
                    <th class="py-2 pr-2 font-semibold w-32">Unit cost (₹)</th>
                    <th class="py-2 pr-2 font-semibold w-24">GST %</th>
                    <th class="py-2 pr-2 font-semibold w-32 text-right">Line total</th>
                    <th class="py-2 w-10"></th>
                </tr>
            </thead>
            <tbody id="grnLinesBody" class="divide-y divide-gray-100"></tbody>
            <tfoot>
                <tr>
                    <td colspan="4"></td>
                    <td colspan="2" class="pt-3 text-right text-gray-600">Taxable</td>
                    <td class="pt-3 text-right font-mono" id="grnTaxable">₹0.00</td>
                    <td colspan="2"></td>
                </tr>
                <tr>
                    <td colspan="4"></td>
                    <td colspan="2" class="text-right text-gray-600">GST</td>
                    <td class="text-right font-mono" id="grnGst">₹0.00</td>
                    <td colspan="2"></td>
                </tr>
                <tr>
                    <td colspan="4"></td>
                    <td colspan="2" class="text-right font-semibold text-gray-900">Total</td>
                    <td class="text-right font-semibold font-mono text-gray-900" id="grnGrandTotal">₹0.00</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        <button type="button" id="grnAddLine" class="text-sm text-brand-700 hover:text-brand-800 font-medium">+ Add line</button>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
            {{ $isEdit ? 'Save changes' : 'Save as draft' }}
        </button>
        <a href="{{ $isEdit ? route('admin.inventory.grns.show', $invoice) : route('admin.inventory.grns.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</form>

<template id="grnLineTemplate">
    <tr class="grn-line">
        <td class="py-2 pr-2">
            <select name="lines[__INDEX__][product_variant_id]" class="grn-variant w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500" required>
                <option value="">Choose a product…</option>
                @foreach($variants as $variant)
                    <option value="{{ $variant->id }}" data-gst-rate="{{ number_format($variant->gst_rate_bp / 100, 2, '.', '') }}">{{ $variant->variant_sku }} — {{ $variant->product->name }}</option>
                @endforeach
            </select>
        </td>
        <td class="py-2 pr-2"><input type="text" name="lines[__INDEX__][batch_no]" class="grn-field w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500" required></td>
        <td class="py-2 pr-2"><input type="date" name="lines[__INDEX__][mfg_date]" class="grn-field w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"></td>
        <td class="py-2 pr-2"><input type="date" name="lines[__INDEX__][expiry_date]" class="grn-field w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"></td>
        <td class="py-2 pr-2"><input type="number" min="1" step="1" name="lines[__INDEX__][qty]" class="grn-qty w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500" required></td>
        <td class="py-2 pr-2"><input type="number" min="0" step="0.01" name="lines[__INDEX__][unit_cost]" class="grn-cost w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500" required></td>
        <td class="py-2 pr-2"><input type="number" min="0" max="100" step="0.01" name="lines[__INDEX__][gst_rate]" class="grn-gst w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500" required></td>
        <td class="py-2 pr-2 text-right font-mono grn-line-total">₹0.00</td>
        <td class="py-2 text-right"><button type="button" class="grn-remove-line text-red-600 hover:text-red-700 text-xs font-medium">Remove</button></td>
    </tr>
</template>

@push('scripts')
<script>
(function () {
    const body = document.getElementById('grnLinesBody');
    const template = document.getElementById('grnLineTemplate');
    let index = 0;

    const existingLines = @json($existingLinesForJs);

    function addLine(prefill) {
        const html = template.innerHTML.replaceAll('__INDEX__', index++);
        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = html.trim();
        const row = wrapper.firstElementChild;
        body.appendChild(row);

        const variantSelect = row.querySelector('.grn-variant');
        if (prefill) {
            if (prefill.product_variant_id) variantSelect.value = prefill.product_variant_id;
            if (prefill.batch_no) row.querySelector('[name$="[batch_no]"]').value = prefill.batch_no;
            if (prefill.mfg_date) row.querySelector('[name$="[mfg_date]"]').value = prefill.mfg_date;
            if (prefill.expiry_date) row.querySelector('[name$="[expiry_date]"]').value = prefill.expiry_date;
            if (prefill.qty) row.querySelector('.grn-qty').value = prefill.qty;
            if (prefill.unit_cost) row.querySelector('.grn-cost').value = prefill.unit_cost;
            if (prefill.gst_rate) row.querySelector('.grn-gst').value = prefill.gst_rate;
        }

        variantSelect.addEventListener('change', function () {
            const opt = variantSelect.selectedOptions[0];
            const gstField = row.querySelector('.grn-gst');
            if (opt && opt.dataset.gstRate && !gstField.value) {
                gstField.value = opt.dataset.gstRate;
                recalc();
            }
        });
        row.querySelectorAll('.grn-qty, .grn-cost, .grn-gst').forEach(function (el) {
            el.addEventListener('input', recalc);
        });
        row.querySelector('.grn-remove-line').addEventListener('click', function () {
            row.remove();
            recalc();
        });
        recalc();
    }

    function recalc() {
        let taxable = 0;
        let gstTotal = 0;
        body.querySelectorAll('.grn-line').forEach(function (row) {
            const qty = parseFloat(row.querySelector('.grn-qty').value) || 0;
            const cost = parseFloat(row.querySelector('.grn-cost').value) || 0;
            const gstRate = parseFloat(row.querySelector('.grn-gst').value) || 0;
            const lineTaxable = qty * cost;
            const lineGst = lineTaxable * gstRate / 100;
            row.querySelector('.grn-line-total').textContent = '₹' + (lineTaxable + lineGst).toFixed(2);
            taxable += lineTaxable;
            gstTotal += lineGst;
        });
        document.getElementById('grnTaxable').textContent = '₹' + taxable.toFixed(2);
        document.getElementById('grnGst').textContent = '₹' + gstTotal.toFixed(2);
        document.getElementById('grnGrandTotal').textContent = '₹' + (taxable + gstTotal).toFixed(2);
    }

    document.getElementById('grnAddLine').addEventListener('click', function () { addLine(null); });

    if (existingLines.length > 0) {
        existingLines.forEach(addLine);
    } else {
        addLine(null);
    }
})();
</script>
@endpush
@endsection
