@extends('admin.layouts.admin')
@section('title', $supplier->exists ? 'Edit supplier' : 'New supplier')
@section('heading', $supplier->exists ? 'Edit supplier: '.$supplier->name : 'New supplier')

@section('content')
@php
    $isEdit = $supplier->exists;
    $action = $isEdit ? route('admin.inventory.suppliers.update', $supplier) : route('admin.inventory.suppliers.store');
@endphp

<form method="POST" action="{{ $action }}" class="max-w-3xl space-y-6">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <p class="text-sm text-gray-600">Suppliers carry no stock effect on their own — they're the counterparty on purchase orders and goods receipts.</p>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Name <x-help-tip text="The supplier's registered business name." /></span>
                <input type="text" name="name" value="{{ old('name', $supplier->name) }}" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">GSTIN <x-help-tip text="The supplier's 15-character GST registration number, if they have one." /></span>
                <input type="text" name="gstin" value="{{ old('gstin', $supplier->gstin) }}" maxlength="15"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono uppercase focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Contact name <x-help-tip text="The person to reach at this supplier." /></span>
                <input type="text" name="contact_name" value="{{ old('contact_name', $supplier->contact_name) }}" maxlength="100"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Phone <x-help-tip text="The supplier's contact phone number." /></span>
                <input type="text" name="phone_e164" value="{{ old('phone_e164', $supplier->phone_e164) }}" maxlength="20"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Email <x-help-tip text="The supplier's contact email address." /></span>
                <input type="email" name="email" value="{{ old('email', $supplier->email) }}" maxlength="150"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wider">Address</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Address line</span>
                <input type="text" name="line1" value="{{ old('line1', $supplier->line1) }}" maxlength="255"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">City</span>
                <input type="text" name="city" value="{{ old('city', $supplier->city) }}" maxlength="100"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">State</span>
                <input type="text" name="state" value="{{ old('state', $supplier->state) }}" maxlength="64"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Pincode</span>
                <input type="text" name="pincode" value="{{ old('pincode', $supplier->pincode) }}" maxlength="10"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Status</span>
                <select name="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="active" @selected(old('status', $supplier->status ?? 'active') === 'active')>Active</option>
                    <option value="archived" @selected(old('status', $supplier->status) === 'archived')>Archived</option>
                </select>
            </label>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
            {{ $isEdit ? 'Save changes' : 'Create supplier' }}
        </button>
        <a href="{{ route('admin.inventory.suppliers.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
        @if($isEdit && $supplier->status === 'active')
        <form method="POST" action="{{ route('admin.inventory.suppliers.archive', $supplier) }}" class="ml-auto" data-confirm-impact="Archive this supplier?">
            @csrf
            <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium">Archive</button>
        </form>
        @elseif($isEdit)
        <form method="POST" action="{{ route('admin.inventory.suppliers.reactivate', $supplier) }}" class="ml-auto">
            @csrf
            <button type="submit" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Reactivate</button>
        </form>
        @endif
    </div>
</form>
@endsection
