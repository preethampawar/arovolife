@extends('admin.layouts.admin')
@section('title', $coupon->exists ? 'Edit coupon' : 'New coupon')
@section('heading', $coupon->exists ? 'Edit coupon: '.$coupon->code : 'New coupon')

@section('content')
@php
    $isEdit = $coupon->exists;
    $action = $isEdit ? route('admin.commerce.coupons.update', $coupon) : route('admin.commerce.coupons.store');
    $valuePrefill = old('value', $isEdit ? ($coupon->type === 'percent' ? $coupon->value : number_format($coupon->value / 100, 2, '.', '')) : '');
    $maxPrefill = old('max_discount', $isEdit && $coupon->max_discount_paise ? number_format($coupon->max_discount_paise / 100, 2, '.', '') : '');
    $minPrefill = old('min_purchase', $isEdit && $coupon->min_purchase_paise ? number_format($coupon->min_purchase_paise / 100, 2, '.', '') : '');
    $type = old('type', $coupon->type ?? 'percent');
    $scope = old('scope', $coupon->scope ?? 'all');
@endphp

<form method="POST" action="{{ $action }}" class="max-w-3xl space-y-6">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Code</span>
                <input type="text" name="code" value="{{ old('code', $coupon->code) }}" required placeholder="WELCOME10"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Status</span>
                <select name="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="active" @selected(old('status', $coupon->status) === 'active')>Active</option>
                    <option value="archived" @selected(old('status', $coupon->status) === 'archived')>Archived</option>
                </select>
            </label>
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Description</span>
                <input type="text" name="description" value="{{ old('description', $coupon->description) }}" maxlength="255"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Discount</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Type</span>
                <select name="type" id="couponType" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="percent" @selected($type === 'percent')>Percent (%)</option>
                    <option value="fixed" @selected($type === 'fixed')>Fixed (₹)</option>
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium"><span id="valueLabel">{{ $type === 'percent' ? 'Percent' : 'Amount (₹)' }}</span></span>
                <input type="number" step="0.01" min="0" name="value" value="{{ $valuePrefill }}" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block" id="maxDiscountField">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Max discount (₹)</span>
                <input type="number" step="0.01" min="0" name="max_discount" value="{{ $maxPrefill }}" placeholder="no cap"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
        </div>
        <label class="block max-w-xs">
            <span class="block text-xs text-gray-700 mb-1 font-medium">Minimum purchase (₹)</span>
            <input type="number" step="0.01" min="0" name="min_purchase" value="{{ $minPrefill }}" placeholder="0"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
        </label>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Eligibility</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Applies to</span>
                <select name="scope" id="couponScope" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="all" @selected($scope === 'all')>Whole cart</option>
                    <option value="category" @selected($scope === 'category')>A category</option>
                    <option value="product" @selected($scope === 'product')>A product</option>
                </select>
            </label>
            <label class="block scope-target" data-scope="category">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Category</span>
                <select name="scope_id" data-scope-select="category" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500" disabled>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" @selected($scope === 'category' && (int) old('scope_id', $coupon->scope_id) === $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block scope-target" data-scope="product">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Product</span>
                <select name="scope_id" data-scope-select="product" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500" disabled>
                    @foreach($products as $prod)
                        <option value="{{ $prod->id }}" @selected($scope === 'product' && (int) old('scope_id', $coupon->scope_id) === $prod->id)>{{ $prod->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Window &amp; limits</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Starts at</span>
                <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $coupon->starts_at?->format('Y-m-d\TH:i')) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Ends at</span>
                <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $coupon->ends_at?->format('Y-m-d\TH:i')) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Total usage limit</span>
                <input type="number" step="1" min="1" name="usage_limit" value="{{ old('usage_limit', $coupon->usage_limit) }}" placeholder="unlimited"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Per-customer limit</span>
                <input type="number" step="1" min="1" name="per_customer_limit" value="{{ old('per_customer_limit', $coupon->per_customer_limit) }}" placeholder="unlimited"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
        </div>
        <p class="text-xs text-gray-500">A "new user" coupon = per-customer limit of 1.</p>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
            {{ $isEdit ? 'Save changes' : 'Create coupon' }}
        </button>
        <a href="{{ route('admin.commerce.coupons.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
        @if($isEdit)
        <form method="POST" action="{{ route('admin.commerce.coupons.archive', $coupon) }}" class="ml-auto" data-confirm-impact="Archive this coupon?">
            @csrf
            <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium">Archive</button>
        </form>
        @endif
    </div>
</form>

@push('scripts')
<script>
    (function () {
        const typeSel = document.getElementById('couponType');
        const scopeSel = document.getElementById('couponScope');
        const maxField = document.getElementById('maxDiscountField');
        const valueLabel = document.getElementById('valueLabel');

        function syncType() {
            const isPercent = typeSel.value === 'percent';
            maxField.style.display = isPercent ? '' : 'none';
            valueLabel.textContent = isPercent ? 'Percent' : 'Amount (₹)';
        }
        function syncScope() {
            document.querySelectorAll('.scope-target').forEach(function (el) {
                const match = el.dataset.scope === scopeSel.value;
                el.style.display = match ? '' : 'none';
                const sel = el.querySelector('select');
                if (sel) sel.disabled = !match; // disabled inputs don't submit → only one scope_id is sent
            });
        }
        typeSel.addEventListener('change', syncType);
        scopeSel.addEventListener('change', syncScope);
        syncType();
        syncScope();
    })();
</script>
@endpush
@endsection
