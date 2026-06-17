@extends('admin.layouts.admin')
@section('title', 'Help & Reference')
@section('heading', 'Help & Reference')

@section('content')

<p class="text-sm text-gray-600 mb-6 max-w-3xl">
    Internal reference material for the operations team. These render the project
    documentation directly inside the panel — read-only, always current with the
    deployed build.
</p>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-4xl">
    @foreach($docs as $slug => $doc)
    <a href="{{ route('admin.help.show', $slug) }}"
       class="group block bg-white rounded-2xl border border-gray-200 hover:border-brand-400 hover:shadow-md transition-all p-5">
        <div class="flex items-start gap-3">
            <span class="text-2xl shrink-0">📘</span>
            <div class="min-w-0">
                <h2 class="font-semibold text-gray-900 group-hover:text-brand-700">{{ $doc['title'] }}</h2>
                <p class="text-sm text-gray-600 mt-1">{{ $doc['description'] }}</p>
                <span class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 mt-3">Open reference →</span>
            </div>
        </div>
    </a>
    @endforeach
</div>

@endsection
