@extends('admin.layouts.admin')
@section('title', 'Categories')
@section('heading', 'Product Categories')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">The storefront category master (Atomy-style). Supports a parent hierarchy.</p>
    <a href="{{ route('admin.catalog.categories.create') }}"
        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
        + New category
    </a>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Name</th>
                <th class="px-4 py-3 font-semibold">Slug</th>
                <th class="px-4 py-3 font-semibold">Parent</th>
                <th class="px-4 py-3 font-semibold text-right">Products</th>
                <th class="px-4 py-3 font-semibold text-right">Sort</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($categories as $cat)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $cat->name }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $cat->slug }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $cat->parent?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right text-gray-700">{{ $cat->products_count }}</td>
                    <td class="px-4 py-3 text-right text-gray-700">{{ $cat->sort }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $cat->status === 'active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-100 text-gray-600 border-gray-200' }}">{{ ucfirst($cat->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.catalog.categories.edit', $cat) }}" class="text-brand-600 hover:text-brand-700 font-medium">Edit</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-gray-500">No categories yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $categories->links() }}</div>
@endsection
