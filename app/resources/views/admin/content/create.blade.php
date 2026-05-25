@extends('admin.layouts.admin')
@section('title', 'New Content Page')
@section('heading', 'New Content Page')

@section('content')

<form method="POST" action="{{ route('admin.content.store') }}" class="max-w-4xl bg-white rounded-2xl border border-gray-200 p-8"
    data-confirm="Create this content page?"
    data-confirm-title="Confirm create"
    data-confirm-impact="Creates the content page with the status you selected. If published, it becomes publicly visible. You can edit or archive it later.">
    @include('admin.content._form', ['submitLabel' => 'Create Page'])
</form>

@endsection
