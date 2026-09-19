@extends('admin.layouts.admin')
@section('title', 'Banners')
@section('heading', 'Shopping Mall Banners')

@section('content')
{{-- Flash is rendered once by the admin layout (admin.blade.php). --}}
<div class="flex items-center justify-between mb-5">
    <p class="text-sm text-gray-600">Carousel banners shown at the top of the shop. Recommended 1520&nbsp;×&nbsp;350&nbsp;px.</p>
    <x-ui.button href="{{ route('admin.catalog.banners.create') }}">{{ svg('lucide-plus', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} New banner</x-ui.button>
</div>

<x-filter-bar :filters="$filters" />

<x-ui.card flush>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold w-12">S.No</th>
                <th class="px-4 py-3 font-semibold">Preview</th>
                <th class="px-4 py-3 font-semibold">Title</th>
                <th class="px-4 py-3 font-semibold">Placement</th>
                <th class="px-4 py-3 font-semibold">Source</th>
                <th class="px-4 py-3 font-semibold text-right">Sort</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($banners as $banner)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-600">{{ $loop->iteration }}</td>
                    <td class="px-4 py-3">
                        @if($banner->hasImage())
                            <img src="{{ $banner->url() }}" alt="" class="w-40 aspect-[1520/350] object-cover rounded border border-gray-200">
                        @else
                            <span class="text-gray-600">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-900">{{ $banner->title ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-700 text-xs">
                        @if($banner->category)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-brand-50 text-brand-700 border border-brand-200">{{ $banner->category->name }}</span>
                        @else
                            <span class="text-gray-600">Shopping Mall</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-600 text-xs">{{ $banner->external_url ? 'URL' : ($banner->s3_key ? 'Uploaded' : '—') }}</td>
                    <td class="px-4 py-3 text-right text-gray-700">{{ $banner->sort }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $banner->status === 'active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-100 text-gray-600 border-gray-200' }}">{{ ucfirst($banner->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.catalog.banners.edit', $banner) }}" class="text-brand-700 hover:text-brand-800 font-medium">Edit</a>
                    </td>
                </tr>
            @empty
                <x-ui.empty-state colspan="8" title="No banners yet.">
                    <x-ui.button href="{{ route('admin.catalog.banners.create') }}" variant="secondary" size="sm" icon="plus">Create one</x-ui.button>
                </x-ui.empty-state>
            @endforelse
        </tbody>
    </table>
    </div>
</x-ui.card>

<div class="mt-4">{{ $banners->links() }}</div>
@endsection
