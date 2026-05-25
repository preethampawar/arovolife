@extends('admin.layouts.admin')
@section('title', 'Edit — ' . $page->title)
@section('heading', 'Edit: ' . $page->title)

@section('content')

<div class="max-w-4xl">

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">Edit content page</p>
        <p class="leading-relaxed">Update the title and body of this public page. Changes are live immediately once saved if the page is published.</p>
    </div>

    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('admin.content.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Back to Content Pages</a>
        @if($page->status === 'published')
        <a href="{{ route('content.show', $page->slug) }}" target="_blank"
           class="text-sm text-brand-600 hover:text-brand-700">View live ↗</a>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.content.update', $page) }}" class="bg-white rounded-2xl border border-gray-200 p-8"
        data-confirm="Save these changes?"
        data-confirm-title="Confirm save"
        data-confirm-impact="Saves your edits to this content page. If it is published, the live page updates immediately. You can edit it again later.">
        @method('PATCH')
        @include('admin.content._form', ['submitLabel' => 'Save Changes'])
    </form>

    @if($page->status !== 'archived')
    <form method="POST" action="{{ route('admin.content.destroy', $page) }}" class="mt-8 bg-white rounded-2xl border border-red-200 p-6"
        data-confirm="Archive this page?"
        data-confirm-title="Confirm archive"
        data-confirm-impact="The page is hidden from the public but kept in the database and audit log. This is reversible — the row is not deleted.">
        @csrf
        @method('DELETE')
        <h3 class="text-sm font-semibold text-red-700 mb-2">Archive this page</h3>
        <p class="text-xs text-gray-600 mb-4">Archived pages are hidden from the public but kept in the database and audit log. This does not delete the row.</p>
        <button type="submit"
            class="px-4 py-2 rounded-lg bg-white border border-red-200 text-red-700 hover:bg-red-50 text-sm font-medium transition-colors">
            Archive Page
        </button>
    </form>
    @endif

</div>

@endsection
