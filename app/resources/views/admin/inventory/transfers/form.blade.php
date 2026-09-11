@extends('admin.layouts.admin')
@section('title', 'New stock transfer')
@section('heading', 'New stock transfer')

@section('content')
<form method="POST" action="{{ route('admin.inventory.transfers.store') }}" class="max-w-5xl space-y-6">
    @csrf

    <p class="text-sm text-gray-600">Dispatch takes stock out of the source the moment it leaves. Receiving is a separate step at the destination — nothing is added anywhere until then.</p>

    @if($errors->has('transfer'))
        <div class="rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-3">{{ $errors->first('transfer') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">From warehouse</span>
                <select name="from_warehouse_code" id="fromWarehouse" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Choose a warehouse…</option>
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->code }}" @selected(old('from_warehouse_code') === $wh->code)>{{ $wh->name }} ({{ $wh->code }})</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">To warehouse</span>
                <select name="to_warehouse_code" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Choose a warehouse…</option>
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->code }}" @selected(old('to_warehouse_code') === $wh->code)>{{ $wh->name }} ({{ $wh->code }})</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4 overflow-x-auto">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Line items</h2>
        <p class="text-xs text-gray-500">Choose the source warehouse first — its batches with stock on hand will appear here.</p>
        <table class="w-full text-sm min-w-[700px]">
            <thead class="text-gray-600 text-left">
                <tr>
                    <th class="py-2 pr-2 font-semibold">Batch</th>
                    <th class="py-2 pr-2 font-semibold w-32">Available</th>
                    <th class="py-2 pr-2 font-semibold w-32">Qty to transfer</th>
                    <th class="py-2 w-10"></th>
                </tr>
            </thead>
            <tbody id="transferLinesBody" class="divide-y divide-gray-100"></tbody>
        </table>
        <button type="button" id="transferAddLine" class="text-sm text-brand-700 hover:text-brand-800 font-medium">+ Add line</button>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Create transfer</button>
        <a href="{{ route('admin.inventory.transfers.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</form>

<template id="transferLineTemplate">
    <tr class="transfer-line">
        <td class="py-2 pr-2">
            <select name="lines[__INDEX__][stock_batch_id]" class="transfer-batch w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500" required>
                <option value="">Choose a source warehouse first…</option>
            </select>
            <input type="hidden" name="lines[__INDEX__][product_variant_id]" class="transfer-variant-id">
        </td>
        <td class="py-2 pr-2 transfer-available text-gray-600">—</td>
        <td class="py-2 pr-2"><input type="number" min="1" step="1" name="lines[__INDEX__][qty]" class="transfer-qty w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500" required></td>
        <td class="py-2 text-right"><button type="button" class="transfer-remove-line text-red-600 hover:text-red-700 text-xs font-medium">Remove</button></td>
    </tr>
</template>

@push('scripts')
<script>
(function () {
    const body = document.getElementById('transferLinesBody');
    const template = document.getElementById('transferLineTemplate');
    const fromSelect = document.getElementById('fromWarehouse');
    const batches = @json($batches);
    let index = 0;

    function batchesForWarehouse(code) {
        return batches.filter(function (b) { return b.warehouse_code === code; });
    }

    function populateBatchSelect(select) {
        const code = fromSelect.value;
        const options = batchesForWarehouse(code);
        select.innerHTML = '';

        if (!code) {
            select.appendChild(new Option('Choose a source warehouse first…', ''));
            return;
        }

        select.appendChild(new Option('Choose a batch…', ''));
        options.forEach(function (b) {
            const label = b.sku + ' — ' + b.name + ' — batch ' + b.batch_no + ' (' + b.qty_on_hand + ' on hand)';
            const opt = new Option(label, b.id);
            opt.dataset.variantId = b.product_variant_id;
            opt.dataset.qtyOnHand = b.qty_on_hand;
            select.appendChild(opt);
        });
    }

    function addLine() {
        const html = template.innerHTML.replaceAll('__INDEX__', index++);
        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = html.trim();
        const row = wrapper.firstElementChild;
        body.appendChild(row);

        const batchSelect = row.querySelector('.transfer-batch');
        populateBatchSelect(batchSelect);

        batchSelect.addEventListener('change', function () {
            const opt = batchSelect.selectedOptions[0];
            row.querySelector('.transfer-variant-id').value = opt && opt.dataset.variantId ? opt.dataset.variantId : '';
            row.querySelector('.transfer-available').textContent = opt && opt.dataset.qtyOnHand ? opt.dataset.qtyOnHand + ' on hand' : '—';
        });

        row.querySelector('.transfer-remove-line').addEventListener('click', function () {
            row.remove();
        });
    }

    fromSelect.addEventListener('change', function () {
        body.querySelectorAll('.transfer-batch').forEach(populateBatchSelect);
        body.querySelectorAll('.transfer-variant-id').forEach(function (el) { el.value = ''; });
        body.querySelectorAll('.transfer-available').forEach(function (el) { el.textContent = '—'; });
    });

    document.getElementById('transferAddLine').addEventListener('click', addLine);

    addLine();
})();
</script>
@endpush
@endsection
