@extends('layouts.app')
@section('title', $announcement->title)

@section('content')
<a href="{{ route('announcements.index') }}" class="mb-4 inline-flex items-center gap-1 text-xs text-gray-700 hover:text-gray-900">
    <x-lucide-chevron-left class="h-3.5 w-3.5" />
    All announcements
</a>

<article class="rounded-xl border border-gray-200 bg-white p-6">
    <h1 class="mb-1 text-xl font-bold text-gray-900">{{ $announcement->title }}</h1>
    <p class="mb-5 text-xs text-gray-500">{{ $announcement->published_at?->format('d M Y') }}</p>
    {{-- Authored in a textarea by an administrator, so it is rendered as the
         plain text it is. Never {!! !!} here: that would make one admin
         account an XSS vector against every distributor at once. --}}
    <div class="whitespace-pre-wrap break-words text-sm leading-relaxed text-gray-900">{{ $announcement->body }}</div>
</article>
@endsection
