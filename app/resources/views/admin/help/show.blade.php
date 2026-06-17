@extends('admin.layouts.admin')
@section('title', $doc['title'])
@section('heading', 'Help & Reference')

@section('content')

<div class="mb-5 flex items-center gap-2 text-sm text-gray-500">
    <a href="{{ route('admin.help.index') }}" class="hover:text-gray-800">Help &amp; Reference</a>
    <span>/</span>
    <span class="text-gray-800 font-medium">{{ $doc['title'] }}</span>
</div>

<div class="grid grid-cols-1 lg:grid-cols-[14rem_1fr] gap-6 items-start">
    {{-- Doc switcher (when more than one reference exists). --}}
    @if(count($docs) > 1)
    <nav class="bg-white rounded-2xl border border-gray-200 p-3 lg:sticky lg:top-6">
        <p class="px-2 pb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400">References</p>
        @foreach($docs as $s => $d)
        <a href="{{ route('admin.help.show', $s) }}"
           class="block px-3 py-2 rounded-lg text-sm {{ $s === $slug ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' }}">
            {{ $d['title'] }}
        </a>
        @endforeach
    </nav>
    @else
    <div class="hidden lg:block"></div>
    @endif

    <article class="bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 min-w-0">
        {{-- Trusted, repo-controlled markdown rendered to HTML (raw HTML stripped
             in the controller). Styled via the .markdown-doc rules in app.css. --}}
        <div class="markdown-doc">{!! $html !!}</div>
    </article>
</div>

@endsection
