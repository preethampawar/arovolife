@extends('admin.layouts.admin')
@section('title', $banner->exists ? 'Edit banner' : 'New banner')
@section('heading', $banner->exists ? 'Edit banner' : 'New banner')

@section('content')
@php
    $isEdit = $banner->exists;
    $action = $isEdit ? route('admin.catalog.banners.update', $banner) : route('admin.catalog.banners.store');
@endphp

@if($errors->any())
    <div class="mb-5 max-w-2xl rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="max-w-2xl space-y-6">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        {{-- Recommended-size note (Shopping Mall banner). --}}
        <div class="rounded-lg bg-brand-50 border border-brand-200 px-3 py-2 text-xs text-brand-800">
            Shopping&nbsp;Mall banner — recommended image size <strong>1520&nbsp;×&nbsp;350&nbsp;px</strong> (JPG or PNG, up to 5&nbsp;MB). Upload a file <em>or</em> paste a hosted image URL below.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Title (optional)</span>
                <input type="text" name="title" value="{{ old('title', $banner->title) }}" maxlength="150"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Caption (optional)</span>
                <input type="text" name="caption" value="{{ old('caption', $banner->caption) }}" maxlength="255"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Link URL (optional — where the banner clicks through)</span>
                <input type="url" name="link_url" value="{{ old('link_url', $banner->link_url) }}" maxlength="500" placeholder="https://…"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>

            <div class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Banner image</span>
                @if($banner->hasImage())
                    <img src="{{ $banner->url() }}" alt="" class="w-full max-w-md aspect-[1520/350] object-cover rounded-lg border border-gray-200 mb-2">
                @endif
                <input type="file" name="image" accept="image/jpeg,image/png"
                    class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-slate-900 file:text-white file:text-sm file:font-medium hover:file:bg-slate-800">
                <span class="block text-xs text-gray-500 mt-1">Upload to S3. Leave empty to keep the current image or use the URL below.</span>
            </div>
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">…or image URL</span>
                <input type="url" name="external_url" value="{{ old('external_url', $banner->external_url) }}" maxlength="500" placeholder="https://cdn…/banner.jpg"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <span class="block text-xs text-gray-500 mt-1">A hosted/CDN image URL. Ignored if a file is uploaded above.</span>
            </label>

            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Sort order</span>
                <input type="number" step="1" min="0" name="sort" value="{{ old('sort', $banner->sort ?? 0) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Status</span>
                <select name="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="active" @selected(old('status', $banner->status) === 'active')>Active</option>
                    <option value="archived" @selected(old('status', $banner->status) === 'archived')>Archived</option>
                </select>
            </label>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
            {{ $isEdit ? 'Save changes' : 'Create banner' }}
        </button>
        <a href="{{ route('admin.catalog.banners.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
        @if($isEdit)
        <form method="POST" action="{{ route('admin.catalog.banners.destroy', $banner) }}" class="ml-auto"
            data-confirm="Delete this banner?" data-confirm-title="Delete banner" data-confirm-impact="Removes the banner from the storefront carousel. This cannot be undone.">
            @csrf @method('DELETE')
            <button type="submit" class="text-sm text-red-600 hover:text-red-700 font-medium">Delete</button>
        </form>
        @endif
    </div>
</form>
@endsection
