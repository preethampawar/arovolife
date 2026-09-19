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

    <x-ui.card padding="p-6 space-y-4">
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
                <span id="grnPoError" hidden class="mt-1 block text-xs text-red-600">
                    Could not load that order's lines. Leave the lines below as they are and try again, or enter them by hand.
                </span>
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
    </x-ui.card>

    <x-ui.card padding="p-6 space-y-4">
        <div>
            <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Landed cost charges</h2>
            <p class="text-xs text-gray-500 mt-1">
                What it cost to get these goods into the warehouse, over and above the supplier's price.
                These are spread across the lines below to give each item its true landed cost —
                which becomes the stock value, the cost of sale, and the product's landing price.
                Leave them at zero if the supplier price is already delivered-to-door.
            </p>
        </div>
        {{-- items-end so the inputs sit on one line whatever the labels do:
             "Loading / handling (₹)" plus its help tip wraps to two lines at
             this column width, which pushed its input a row below its
             neighbours. --}}
        <div class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                'freight' => ['Freight', $invoice->freight_paise, 'Transport from the supplier to your warehouse.'],
                'insurance' => ['Insurance', $invoice->insurance_paise, 'Transit insurance on this consignment.'],
                'handling' => ['Loading / handling', $invoice->handling_paise, 'Loading, unloading and clearing charges.'],
                'other_charges' => ['Other charges', $invoice->other_charges_paise, 'Any other non-recoverable cost of bringing these goods in. Do not put GST here — it is input credit, not a cost.'],
            ] as $field => [$label, $value, $tip])
                <label class="block">
                    <span class="block text-xs text-gray-700 mb-1 font-medium">{{ $label }} (₹) <x-help-tip :text="$tip" /></span>
                    <input type="number" step="0.01" min="0" name="{{ $field }}"
                        value="{{ old($field, number_format(($value ?? 0) / 100, 2, '.', '')) }}"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-brand-500">
                </label>
            @endforeach
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Spread by <x-help-tip text="How the charges are divided across the lines. Value is the usual choice. Use Weight when freight is the dominant charge and the items differ a lot in weight; use Quantity when every unit costs the same to ship." /></span>
                <select name="allocation_basis"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    @foreach (['value' => 'Line value', 'qty' => 'Quantity', 'weight' => 'Weight'] as $basis => $label)
                        <option value="{{ $basis }}" @selected(old('allocation_basis', $invoice->allocation_basis ?? 'value') === $basis)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-ui.card>

    {{-- Not a table any more. As a table every column had to fit one shared row,
         which on a 900px floor left Qty roughly 50px of text once the padding
         and the number spinner were taken out — two digits at a time, and a
         horizontal scroll on anything narrower. A grid lets the same fields lay
         out as a row on a wide screen and stack into labelled fields on a phone,
         which is the only layout that stays usable at 375px.

         Defined out here, not inside the card: a component slot is compiled as
         its own closure, so a variable declared inside it is not in scope for
         the template below, and the row would silently lose its column widths. --}}
    @php
        // Qty and GST % carry a 110px floor, not the 90px the old columns had.
        // At 14px monospace that is about eight digits of room once the 24px of
        // padding is taken off — a goods receipt for 1,00,000 units fits, which
        // the two-digit field it replaces did not.
        $grnGrid = 'lg:grid lg:gap-3 lg:items-center lg:grid-cols-[minmax(190px,2.2fr)_minmax(110px,1fr)_minmax(140px,1fr)_minmax(140px,1fr)_minmax(110px,0.8fr)_minmax(130px,1fr)_minmax(110px,0.8fr)_minmax(110px,1fr)_auto]';
    @endphp

    <x-ui.card padding="p-4 sm:p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Line items</h2>

        <div class="lg:overflow-x-auto">
            <div class="lg:min-w-[1140px] space-y-3 lg:space-y-2">
                <div class="{{ $grnGrid }} hidden text-xs font-semibold text-gray-600 uppercase tracking-wide">
                    <div>Product</div>
                    <div>Batch no.</div>
                    <div>Mfg date</div>
                    <div>Expiry date</div>
                    <div>Qty</div>
                    <div>Unit cost (₹)</div>
                    <div>GST %</div>
                    <div class="text-right">Line total</div>
                    <div class="w-16"></div>
                </div>

                <div id="grnLinesBody" class="space-y-4 lg:space-y-2"></div>
            </div>
        </div>

        <button type="button" id="grnAddLine" class="text-sm text-brand-700 hover:text-brand-800 font-medium">{{ svg('lucide-plus', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} Add line</button>

        <div class="flex justify-end border-t border-gray-200 pt-4">
            <dl class="w-full sm:w-72 text-sm space-y-1">
                <div class="flex justify-between">
                    <dt class="text-gray-600">Taxable</dt>
                    <dd class="font-mono" id="grnTaxable">₹0.00</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">GST</dt>
                    <dd class="font-mono" id="grnGst">₹0.00</dd>
                </div>
                <div class="flex justify-between border-t border-gray-200 pt-1">
                    <dt class="font-semibold text-gray-900">Total</dt>
                    <dd class="font-semibold font-mono text-gray-900" id="grnGrandTotal">₹0.00</dd>
                </div>
            </dl>
        </div>
    </x-ui.card>

    <div class="flex items-center gap-3">
        <x-ui.button type="submit">
            {{ $isEdit ? 'Save changes' : 'Save as draft' }}
        </x-ui.button>
        <a href="{{ $isEdit ? route('admin.inventory.grns.show', $invoice) : route('admin.inventory.grns.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</form>

@php
    $grnBase = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500';
    // Spinners cost about 20px of an already narrow field and are useless for a
    // quantity anyone types. Killing them is what gets Qty from two visible
    // digits to six; type="number" stays, so the numeric keypad and the min/step
    // validation are untouched.
    $grnNum = $grnBase.' font-mono [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none';
    $grnLabel = 'lg:hidden block text-xs text-gray-600 mb-1 font-medium';
@endphp
<template id="grnLineTemplate">
    <div class="grn-line {{ $grnGrid }} rounded-lg border border-gray-200 p-3 lg:border-0 lg:p-0 lg:rounded-none grid grid-cols-2 gap-3">
        <label class="block col-span-2 lg:col-span-1">
            <span class="{{ $grnLabel }}">Product</span>
            <select name="lines[__INDEX__][product_variant_id]" class="grn-variant {{ $grnBase }} bg-white" required>
                <option value="">Choose a product…</option>
                @foreach($variants as $variant)
                    <option value="{{ $variant->id }}" data-gst-rate="{{ number_format($variant->gst_rate_bp / 100, 2, '.', '') }}">{{ $variant->variant_sku }} — {{ $variant->product->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block col-span-2 lg:col-span-1">
            <span class="{{ $grnLabel }}">Batch no.</span>
            <input type="text" name="lines[__INDEX__][batch_no]" class="grn-field {{ $grnBase }} font-mono" required>
        </label>
        <label class="block">
            <span class="{{ $grnLabel }}">Mfg date</span>
            <input type="date" name="lines[__INDEX__][mfg_date]" class="grn-field {{ $grnBase }}">
        </label>
        <label class="block">
            <span class="{{ $grnLabel }}">Expiry date</span>
            <input type="date" name="lines[__INDEX__][expiry_date]" class="grn-field {{ $grnBase }}">
        </label>
        <label class="block">
            <span class="{{ $grnLabel }}">Qty</span>
            <input type="number" min="1" step="1" inputmode="numeric" name="lines[__INDEX__][qty]" class="grn-qty {{ $grnNum }}" required>
        </label>
        <label class="block">
            <span class="{{ $grnLabel }}">Unit cost (₹)</span>
            <input type="number" min="0" step="0.01" inputmode="decimal" name="lines[__INDEX__][unit_cost]" class="grn-cost {{ $grnNum }}" required>
        </label>
        <label class="block">
            <span class="{{ $grnLabel }}">GST %</span>
            <input type="number" min="0" max="100" step="0.01" inputmode="decimal" name="lines[__INDEX__][gst_rate]" class="grn-gst {{ $grnNum }}" required>
        </label>
        <div class="flex flex-col justify-center lg:text-right">
            <span class="{{ $grnLabel }}">Line total</span>
            <span class="font-mono text-sm text-gray-900 grn-line-total">₹0.00</span>
        </div>
        <div class="col-span-2 lg:col-span-1 flex justify-end lg:justify-center">
            <button type="button" class="grn-remove-line text-red-600 hover:text-red-700 text-xs font-medium py-2 px-2 -mr-2 lg:mr-0">Remove</button>
        </div>
    </div>
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
        const wrapper = document.createElement('div');
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
        existingLines.forEach(function (line) { addLine(line); });
    } else {
        addLine(null);
    }

    // Choosing a purchase order fills the table with what that order still has
    // outstanding. This used to happen only when the page was OPENED with a
    // ?purchase_order_id in the query string — reachable from the purchase
    // order screen, but not from the dropdown on this form, so picking the
    // order the obvious way left the receiver retyping every line by hand.
    //
    // The lines come from the server rather than being computed here: what is
    // still owed on an order is ordered-minus-received, and duplicating that
    // arithmetic in the browser is how the two would drift apart.
    const poSelect = document.querySelector('select[name="purchase_order_id"]');
    const supplierSelect = document.querySelector('select[name="supplier_id"]');
    const warehouseSelect = document.querySelector('select[name="warehouse_code"]');
    const poLinesUrl = @json(route('admin.inventory.grns.po-lines', ['purchaseOrder' => '__PO__']));

    if (poSelect) {
        poSelect.addEventListener('change', function () {
            if (!poSelect.value) { return; }

            poSelect.disabled = true;

            fetch(poLinesUrl.replace('__PO__', encodeURIComponent(poSelect.value)), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
                .then(function (r) {
                    if (!r.ok) { throw new Error(String(r.status)); }
                    return r.json();
                })
                .then(function (data) {
                    // The receipt must land against the same supplier and
                    // warehouse the order was raised for; leaving the old
                    // values would post stock to the wrong place.
                    if (supplierSelect && data.supplier_id) { supplierSelect.value = data.supplier_id; }
                    if (warehouseSelect && data.warehouse_code) { warehouseSelect.value = data.warehouse_code; }

                    body.replaceChildren();
                    index = 0;

                    if (data.lines.length > 0) {
                        data.lines.forEach(function (line) { addLine(line); });
                    } else {
                        addLine(null);
                    }

                    recalc();
                })
                .catch(function () {
                    // Leave whatever is on screen alone and say so — silently
                    // doing nothing reads as "this order has no lines", which
                    // would get a receipt posted for nothing.
                    const note = document.getElementById('grnPoError');
                    if (note) { note.hidden = false; }
                })
                .finally(function () {
                    poSelect.disabled = false;
                });
        });
    }
})();
</script>
@endpush
@endsection
