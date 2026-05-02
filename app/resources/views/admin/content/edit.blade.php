@extends('admin.layouts.admin')
@section('title', 'Edit — ' . $page->title)
@section('heading', 'Edit: ' . $page->title)

@section('content')

<div class="max-w-4xl">

    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('admin.content.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Back to Content Pages</a>
        @if($page->status === 'published')
        <a href="{{ route('content.show', $page->slug) }}" target="_blank"
           class="text-sm text-brand-600 hover:text-brand-700">View live ↗</a>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.content.update', $page) }}" class="bg-white rounded-2xl border border-gray-200 p-8">
        @method('PATCH')
        @include('admin.content._form', ['submitLabel' => 'Save Changes'])
    </form>

    @if($page->status !== 'archived')
    <form method="POST" action="{{ route('admin.content.destroy', $page) }}" class="mt-8 bg-white rounded-2xl border border-red-200 p-6">
        @csrf
        @method('DELETE')
        <h3 class="text-sm font-semibold text-red-700 mb-2">Archive this page</h3>
        <p class="text-xs text-gray-600 mb-4">Archived pages are hidden from the public but kept in the database and audit log. This does not delete the row.</p>
        <button type="submit"
            onclick="return confirm('Archive this page? It will no longer be publicly visible.')"
            class="px-4 py-2 rounded-lg bg-white border border-red-200 text-red-700 hover:bg-red-50 text-sm font-medium transition-colors">
            Archive Page
        </button>
    </form>
    @endif

</div>

@endsection
