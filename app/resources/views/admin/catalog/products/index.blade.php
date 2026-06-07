@extends('admin.layouts.admin')
@section('title', 'Products')
@section('heading', 'Products')

@section('content')
<div class="flex items-center justify-between gap-3 mb-6 flex-wrap">
    <p class="text-sm text-gray-600">Manage the product catalog — pricing, attributes, images and descriptions.</p>
    <a href="{{ route('admin.catalog.products.create') }}"
        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
        + New product
    </a>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold w-12">S.No</th>
                <th class="px-4 py-3 font-semibold">Product</th>
                <th class="px-4 py-3 font-semibold">SKU</th>
                <th class="px-4 py-3 font-semibold">Category</th>
                <th class="px-4 py-3 font-semibold text-right">MRP</th>
                <th class="px-4 py-3 font-semibold text-right">Sale</th>
                <th class="px-4 py-3 font-semibold text-right">BV</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($products as $product)
                @php $v = $product->variants->sortBy('id')->first(); @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500">{{ $loop->iteration }}</td>
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $product->name }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $product->sku }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $product->productCategory?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right text-gray-700">{{ $v ? '₹'.number_format($v->mrp_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-3 text-right text-gray-900 font-medium">{{ $v ? '₹'.number_format($v->sale_price_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-3 text-right text-gray-700">{{ $v ? number_format($v->bv_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-3">
                        @php
                            $cls = match ($product->status) {
                                'active' => 'bg-green-50 text-green-700 border-green-200',
                                'draft' => 'bg-amber-50 text-amber-700 border-amber-200',
                                default => 'bg-gray-100 text-gray-600 border-gray-200',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $cls }}">{{ ucfirst($product->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.catalog.products.edit', $product) }}" class="text-brand-600 hover:text-brand-700 font-medium">Edit</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-10 text-center text-gray-500">No products yet. <a href="{{ route('admin.catalog.products.create') }}" class="text-brand-600 underline">Create the first one</a>.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $products->links() }}</div>
@endsection
