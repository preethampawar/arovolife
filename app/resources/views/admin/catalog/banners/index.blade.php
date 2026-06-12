@extends('admin.layouts.admin')
@section('title', 'Banners')
@section('heading', 'Shopping Mall Banners')

@section('content')
{{-- Flash is rendered once by the admin layout (admin.blade.php). --}}
<div class="flex items-center justify-between mb-5">
    <p class="text-sm text-gray-600">Carousel banners shown at the top of the shop. Recommended 1520&nbsp;×&nbsp;350&nbsp;px.</p>
    <a href="{{ route('admin.catalog.banners.create') }}" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold">+ New banner</a>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold w-12">S.No</th>
                <th class="px-4 py-3 font-semibold">Preview</th>
                <th class="px-4 py-3 font-semibold">Title</th>
                <th class="px-4 py-3 font-semibold">Source</th>
                <th class="px-4 py-3 font-semibold text-right">Sort</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($banners as $banner)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500">{{ $loop->iteration }}</td>
                    <td class="px-4 py-3">
                        @if($banner->hasImage())
                            <img src="{{ $banner->url() }}" alt="" class="w-40 aspect-[1520/350] object-cover rounded border border-gray-200">
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-900">{{ $banner->title ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-600 text-xs">{{ $banner->external_url ? 'URL' : ($banner->s3_key ? 'Uploaded' : '—') }}</td>
                    <td class="px-4 py-3 text-right text-gray-700">{{ $banner->sort }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $banner->status === 'active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-100 text-gray-600 border-gray-200' }}">{{ ucfirst($banner->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.catalog.banners.edit', $banner) }}" class="text-brand-600 hover:text-brand-700 font-medium">Edit</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-gray-500">No banners yet. <a href="{{ route('admin.catalog.banners.create') }}" class="text-brand-600 underline">Create one</a>.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $banners->links() }}</div>
@endsection
