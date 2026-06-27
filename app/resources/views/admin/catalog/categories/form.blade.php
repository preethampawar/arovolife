@extends('admin.layouts.admin')
@section('title', $category->exists ? 'Edit category' : 'New category')
@section('heading', $category->exists ? 'Edit: '.$category->name : 'New category')

@section('content')
@php
    $isEdit = $category->exists;
    $action = $isEdit ? route('admin.catalog.categories.update', $category) : route('admin.catalog.categories.store');
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="max-w-2xl space-y-6">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Name <x-help-tip text="The category name shown to customers on the shop." /></span>
                <input type="text" name="name" value="{{ old('name', $category->name) }}" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Slug <x-help-tip text="The category's URL segment on the shop. Use lowercase words joined by hyphens." /></span>
                <input type="text" name="slug" value="{{ old('slug', $category->slug) }}" required placeholder="lowercase-with-hyphens"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Parent category <x-help-tip text="Nests this category under another. Leave as top level for a main category." /></span>
                <select name="parent_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">— top level —</option>
                    @foreach($parents as $parent)
                        <option value="{{ $parent->id }}" @selected((int) old('parent_id', $category->parent_id) === $parent->id)>{{ $parent->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Sort order <x-help-tip text="Controls the display order of categories. Lower numbers appear first." /></span>
                <input type="number" step="1" min="0" name="sort" value="{{ old('sort', $category->sort ?? 0) }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            </label>
            <label class="block">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Status <x-help-tip text="Active shows the category on the shop; Archived hides it." /></span>
                <select name="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="active" @selected(old('status', $category->status) === 'active')>Active</option>
                    <option value="archived" @selected(old('status', $category->status) === 'archived')>Archived</option>
                </select>
            </label>
            <label class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Description <x-help-tip text="Optional text shown on the category page (up to 1000 characters)." /></span>
                <textarea name="description" rows="3" maxlength="1000"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">{{ old('description', $category->description) }}</textarea>
            </label>
            <div class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Tile image <x-help-tip text="The thumbnail image shown for this category on the shop. JPG or PNG, up to 5 MB." /></span>
                @if($category->imageUrl())
                    <img src="{{ $category->imageUrl() }}" alt="" class="w-24 h-24 object-cover rounded-lg border border-gray-200 mb-2">
                @endif
                <input type="file" name="image" accept="image/jpeg,image/png"
                    class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-slate-900 file:text-white file:text-sm file:font-medium hover:file:bg-slate-800">
            </div>

            <div class="block sm:col-span-2">
                <span class="block text-xs text-gray-700 mb-1 font-medium">Category banner <x-help-tip text="A wide banner shown at the top of this category's page. Recommended 1280×290 px. Upload a file or paste a URL below." /></span>
                <div class="rounded-lg bg-brand-50 border border-brand-200 px-3 py-2 text-xs text-brand-800 mb-2">
                    Category banner — recommended <strong>1280&nbsp;×&nbsp;290&nbsp;px</strong> (JPG or PNG, up to 5&nbsp;MB). Upload a file <em>or</em> paste a hosted URL below.
                </div>
                @if($category->bannerUrl())
                    <img src="{{ $category->bannerUrl() }}" alt="" class="w-full max-w-md aspect-[1280/290] object-cover rounded-lg border border-gray-200 mb-2">
                @endif
                <input type="file" name="banner" accept="image/jpeg,image/png"
                    class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-slate-900 file:text-white file:text-sm file:font-medium hover:file:bg-slate-800">
                <input type="url" name="banner_external_url" value="{{ old('banner_external_url', $category->banner_external_url) }}" maxlength="500" placeholder="…or a hosted image URL https://…"
                    class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">
            {{ $isEdit ? 'Save changes' : 'Create category' }}
        </button>
        <a href="{{ route('admin.catalog.categories.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
        @if($isEdit)
        {{-- Submits the separate archive form below via form= (forms must not nest). --}}
        <button type="submit" form="categoryArchiveForm" class="ml-auto text-sm text-red-600 hover:text-red-700 font-medium">Archive</button>
        @endif
    </div>
</form>

@if($isEdit)
<form id="categoryArchiveForm" method="POST" action="{{ route('admin.catalog.categories.archive', $category) }}" class="hidden"
    data-confirm-impact="Archive this category?">
    @csrf
</form>
@endif
@endsection
