@extends('admin.layouts.admin')
@section('title', 'New stock adjustment')
@section('heading', 'New stock adjustment')

@section('content')
<form method="POST" action="{{ route('admin.inventory.adjustments.store') }}" class="max-w-2xl space-y-6">
    @csrf

    <p class="text-sm text-gray-600">Physical count, damage, expiry, theft, samples — anything that isn't a purchase, sale, transfer or return. Every adjustment needs a reason.</p>

    @if($errors->has('adjustment'))
        <div class="rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-3">{{ $errors->first('adjustment') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <label class="block">
            <span class="block text-xs text-gray-700 mb-1 font-medium">Warehouse</span>
            <select name="warehouse_code" id="adjWarehouse" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                <option value="">Choose a warehouse…</option>
                @foreach($warehouses as $wh)
                    <option value="{{ $wh->code }}" @selected(old('warehouse_code', $prefill['warehouse_code'] ?? null) === $wh->code)>{{ $wh->name }} ({{ $wh->code }})</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="block text-xs text-gray-700 mb-1 font-medium">Batch <x-help-tip text="The specific batch being corrected. Only batches with any history at the chosen warehouse are shown." /></span>
            <select name="stock_batch_id" id="adjBatch" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                <option value="">Choose a warehouse first…</option>
            </select>
            <input type="hidden" name="product_variant_id" id="adjVariantId" value="{{ old('product_variant_id', $prefill['product_variant_id'] ?? '') }}">
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Quantity change <x-help-tip text="Positive to add stock, negative to remove it. Cannot be zero." /></span>
                <input type="number" step="1" name="qty_delta" value="{{ old('qty_delta') }}" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Reason</span>
                <select name="reason" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Choose a reason…</option>
                    @foreach(\App\Modules\Inventory\Models\StockAdjustment::REASONS as $reason)
                        <option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ str_replace('_', ' ', ucfirst($reason)) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <label class="block">
            <span class="block text-xs text-gray-700 mb-1 font-medium">Notes <x-help-tip text="Required. What happened, and how it was found." /></span>
            <textarea name="notes" rows="3" maxlength="1000" required
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">{{ old('notes') }}</textarea>
        </label>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Record adjustment</button>
        <a href="{{ route('admin.inventory.adjustments.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
(function () {
    const warehouseSelect = document.getElementById('adjWarehouse');
    const batchSelect = document.getElementById('adjBatch');
    const variantInput = document.getElementById('adjVariantId');
    const batches = @json($batches);
    const prefillVariantId = @json($prefill['product_variant_id'] ?? null);

    function populate() {
        const code = warehouseSelect.value;
        batchSelect.innerHTML = '';

        if (!code) {
            batchSelect.appendChild(new Option('Choose a warehouse first…', ''));
            return;
        }

        batchSelect.appendChild(new Option('Choose a batch…', ''));
        batches.filter(function (b) { return b.warehouse_code === code; }).forEach(function (b) {
            const label = b.sku + ' — ' + b.name + ' — batch ' + b.batch_no + ' (' + b.qty_on_hand + ' on hand)';
            const opt = new Option(label, b.id);
            opt.dataset.variantId = b.product_variant_id;
            if (prefillVariantId && String(b.product_variant_id) === String(prefillVariantId)) {
                opt.selected = true;
            }
            batchSelect.appendChild(opt);
        });

        const selected = batchSelect.selectedOptions[0];
        variantInput.value = selected && selected.dataset.variantId ? selected.dataset.variantId : '';
    }

    batchSelect.addEventListener('change', function () {
        const opt = batchSelect.selectedOptions[0];
        variantInput.value = opt && opt.dataset.variantId ? opt.dataset.variantId : '';
    });

    warehouseSelect.addEventListener('change', populate);

    if (warehouseSelect.value) {
        populate();
    }
})();
</script>
@endpush
@endsection
