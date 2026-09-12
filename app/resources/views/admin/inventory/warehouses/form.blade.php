@extends('admin.layouts.admin')
@section('title', $warehouse->exists ? 'Edit warehouse' : 'New warehouse')
@section('heading', $warehouse->exists ? 'Edit warehouse: '.$warehouse->name : 'New warehouse')

@section('content')
@php
    $isEdit = $warehouse->exists;
    $action = $isEdit ? route('admin.inventory.warehouses.update', $warehouse) : route('admin.inventory.warehouses.store');
    $isDefault = $warehouse->code === \App\Modules\Inventory\Models\Warehouse::DEFAULT_CODE;
@endphp

<form method="POST" action="{{ $action }}" class="max-w-3xl space-y-6">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Code <x-help-tip text="Short, permanent identifier used by every stock document. Cannot be changed after creation." /></span>
                @if($isEdit)
                    <input type="text" value="{{ $warehouse->code }}" disabled
                        class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-mono text-gray-500">
                @else
                    <input type="text" name="code" value="{{ old('code') }}" maxlength="32" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
                @endif
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Name</span>
                <input type="text" name="name" value="{{ old('name', $warehouse->name) }}" maxlength="120" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Type</span>
                <select name="type" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="hub" @selected(old('type', $warehouse->type ?? 'warehouse') === 'hub')>Hub</option>
                    <option value="warehouse" @selected(old('type', $warehouse->type ?? 'warehouse') === 'warehouse')>Warehouse</option>
                    <option value="franchise" @selected(old('type', $warehouse->type ?? 'warehouse') === 'franchise')>Franchise</option>
                </select>
            </label>
            <label class="block flex items-end gap-2 pb-2">
                <input type="hidden" name="fulfils_orders" value="0">
                <input type="checkbox" name="fulfils_orders" value="1" id="fulfils_orders" @checked(old('fulfils_orders', $warehouse->fulfils_orders ?? true))
                    class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                <label for="fulfils_orders" class="text-sm text-gray-700">Can fulfil orders <x-help-tip text="Off means storage only — never chosen to pack a customer order." /></label>
            </label>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Address</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Address line</span>
                <input type="text" name="line1" value="{{ old('line1', $warehouse->line1) }}" maxlength="255"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">City</span>
                <input type="text" name="city" value="{{ old('city', $warehouse->city) }}" maxlength="100"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">State</span>
                <input type="text" name="state" value="{{ old('state', $warehouse->state) }}" maxlength="64"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Pincode</span>
                <input type="text" name="pincode" value="{{ old('pincode', $warehouse->pincode) }}" maxlength="10"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Contact phone</span>
                <input type="text" name="contact_phone_e164" value="{{ old('contact_phone_e164', $warehouse->contact_phone_e164) }}" maxlength="20"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Status</span>
                <select name="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500" @disabled($isDefault)>
                    <option value="active" @selected(old('status', $warehouse->status ?? 'active') === 'active')>Active</option>
                    <option value="archived" @selected(old('status', $warehouse->status) === 'archived')>Archived</option>
                </select>
                @if($isDefault)
                    <input type="hidden" name="status" value="active">
                    <span class="block text-xs text-gray-500 mt-1">The default hub cannot be archived.</span>
                @endif
            </label>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
            {{ $isEdit ? 'Save changes' : 'Create warehouse' }}
        </button>
        <a href="{{ route('admin.inventory.warehouses.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
        @if($isEdit && ! $isDefault && $warehouse->status === 'active')
        <form method="POST" action="{{ route('admin.inventory.warehouses.archive', $warehouse) }}" class="ml-auto" data-confirm="Archive this warehouse?" data-confirm-title="Confirm archive" data-confirm-impact="Archive this warehouse?">
            @csrf
            <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium">Archive</button>
        </form>
        @elseif($isEdit && $warehouse->status === 'archived')
        <form method="POST" action="{{ route('admin.inventory.warehouses.reactivate', $warehouse) }}" class="ml-auto">
            @csrf
            <button type="submit" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Reactivate</button>
        </form>
        @endif
    </div>
</form>
@endsection
