@extends('layouts.app')
@section('title', 'Frequently asked questions')

@section('content')
<h1 class="mb-1 text-xl font-bold text-gray-900">Frequently asked questions</h1>
<p class="mb-6 text-sm text-gray-600">Answers from arovolife. If what you need is not here, raise it with us and we will answer it — and add it.</p>

<form method="GET" class="mb-6">
    <label for="faqSearch" class="sr-only">Search the answers</label>
    <input type="search" id="faqSearch" name="q" value="{{ $search }}"
        placeholder="Search the answers…"
        class="w-full max-w-lg rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
</form>

@if($total === 0)
    <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-600">
        @if($search !== '')
            Nothing matches “{{ $search }}”. Try a different word, or ask us directly.
        @else
            There are no answers here yet.
        @endif
    </div>
@else
    <div class="space-y-8">
        @foreach($grouped as $category => $entries)
            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-600">{{ $category }}</h2>
                <div class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white">
                    @foreach($entries as $entry)
                        <details class="group">
                            <summary class="flex cursor-pointer items-center justify-between gap-3 px-5 py-4 text-sm font-medium text-gray-900 hover:bg-gray-50">
                                {{ $entry->title }}
                                <x-lucide-chevron-down class="h-4 w-4 shrink-0 text-gray-500 transition-transform group-open:rotate-180" />
                            </summary>
                            <div class="px-5 pb-5 text-sm leading-relaxed text-gray-700">
                                {{-- Same table, same editor, same render as
                                     every other content page: the body is
                                     HTML from the rich-text editor that AdminContentPageController
                                     runs through Purifier on the way in. Escaping
                                     it here instead would show a reader the raw
                                     tags, and would mean one stored row rendering
                                     two different ways on two surfaces. --}}
                                <div class="page-body break-words">{!! $entry->body !!}</div>
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
@endsection
