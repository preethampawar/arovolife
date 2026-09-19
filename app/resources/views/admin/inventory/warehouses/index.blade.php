@extends('admin.layouts.admin')
@section('title', 'Warehouses')
@section('heading', 'Warehouses')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">Physical and collection locations stock can be received into, transferred between, or shipped from.</p>
    <x-ui.button href="{{ route('admin.inventory.warehouses.create') }}">
        {{ svg('lucide-plus', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} New warehouse
    </x-ui.button>
</div>

<x-filter-bar :filters="$filters" />

<x-ui.card flush>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Code</th>
                <th class="px-4 py-3 font-semibold">Name</th>
                <th class="px-4 py-3 font-semibold">Type</th>
                <th class="px-4 py-3 font-semibold">City</th>
                <th class="px-4 py-3 font-semibold">Fulfils orders</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($warehouses as $warehouse)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ $warehouse->code }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $warehouse->name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ ucfirst($warehouse->type) }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $warehouse->city ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $warehouse->fulfils_orders ? 'Yes' : 'No' }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $warehouse->status === 'active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-100 text-gray-700 border-gray-200' }}">{{ ucfirst($warehouse->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.inventory.warehouses.edit', $warehouse) }}" class="text-brand-700 hover:text-brand-800 font-medium">Edit</a>
                    </td>
                </tr>
            @empty
                <x-ui.empty-state colspan="7" title="No warehouses yet beyond the default hub." />
            @endforelse
        </tbody>
    </table>
    </div>
</x-ui.card>

<div class="mt-4">{{ $warehouses->links() }}</div>
@endsection
