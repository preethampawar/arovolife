@extends('admin.layouts.admin')
@section('title', 'Suppliers')
@section('heading', 'Suppliers')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">The counterparties on purchase orders and goods receipts.</p>
    <x-ui.button href="{{ route('admin.inventory.suppliers.create') }}">
        {{ svg('lucide-plus', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} New supplier
    </x-ui.button>
</div>

<x-filter-bar :filters="$filters" />

<x-ui.card flush>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Name</th>
                <th class="px-4 py-3 font-semibold">GSTIN</th>
                <th class="px-4 py-3 font-semibold">Contact</th>
                <th class="px-4 py-3 font-semibold">City</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($suppliers as $supplier)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-semibold text-gray-900">{{ $supplier->name }}</td>
                    <td class="px-4 py-3 font-mono text-gray-700">{{ $supplier->gstin ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $supplier->contact_name ?? '—' }}{{ $supplier->phone_e164 ? ' · '.$supplier->phone_e164 : '' }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $supplier->city ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $supplier->status === 'active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-100 text-gray-600 border-gray-200' }}">{{ ucfirst($supplier->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.inventory.suppliers.edit', $supplier) }}" class="text-brand-700 hover:text-brand-800 font-medium">Edit</a>
                    </td>
                </tr>
            @empty
                <x-ui.empty-state colspan="6" title="No suppliers yet.">
                    <x-ui.button href="{{ route('admin.inventory.suppliers.create') }}" variant="secondary" size="sm" icon="plus">Create one</x-ui.button>
                </x-ui.empty-state>
            @endforelse
        </tbody>
    </table>
</x-ui.card>

<div class="mt-4">{{ $suppliers->links() }}</div>
@endsection
