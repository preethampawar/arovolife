@extends('layouts.app')
@section('title', 'Announcements')

@section('content')
<h1 class="mb-1 text-xl font-bold text-gray-900">Announcements</h1>
<p class="mb-6 text-sm text-gray-600">Notices from arovolife. The newest are at the top.</p>

@if($announcements->isEmpty())
    <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-600">
        There are no announcements right now.
    </div>
@else
    <div class="space-y-3">
        @foreach($announcements as $announcement)
            @php $isUnread = ! in_array((int) $announcement->id, $readIds, true); @endphp
            <a href="{{ route('announcements.show', ['announcement' => $announcement->id]) }}"
               class="block rounded-xl border bg-white p-5 transition-colors hover:border-brand-300 {{ $isUnread ? 'border-brand-200' : 'border-gray-200' }}">
                <div class="mb-1 flex items-start justify-between gap-3">
                    <h2 class="text-sm font-semibold text-gray-900">
                        @if($announcement->pinned)
                            <x-lucide-pin class="mr-1 inline h-3.5 w-3.5 text-gray-500" />
                        @endif
                        {{ $announcement->title }}
                    </h2>
                    @if($isUnread)
                        <span class="shrink-0 rounded-full bg-brand-100 px-2 py-0.5 text-[11px] font-semibold text-brand-800">New</span>
                    @endif
                </div>
                <p class="line-clamp-2 text-sm text-gray-700">{{ \Illuminate\Support\Str::limit($announcement->body, 180) }}</p>
                <p class="mt-2 text-[11px] text-gray-500">{{ $announcement->published_at?->format('d M Y') }}</p>
            </a>
        @endforeach
    </div>
@endif
@endsection
