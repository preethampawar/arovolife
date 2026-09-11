@extends('admin.layouts.admin')
@section('title', 'Warehouses')
@section('heading', 'Warehouses')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">Physical and franchise locations stock can be received into, transferred between, or shipped from.</p>
    <a href="{{ route('admin.inventory.warehouses.create') }}"
        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
        + New warehouse
    </a>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
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
                <tr><td colspan="7" class="px-4 py-10 text-center text-gray-600">No warehouses yet beyond the default hub.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $warehouses->links() }}</div>
@endsection
