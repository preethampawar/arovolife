@extends('layouts.app')
@section('title', 'Chat with '.($other->full_name ?: $other->email))

@section('content')
@php
    $myId = auth()->id();
    $otherName = $other->full_name ?: $other->email;
@endphp

<div class="mb-4 flex items-center justify-between gap-3">
    <div>
        <a href="{{ route('messages.index') }}" class="inline-flex items-center gap-1 text-xs text-gray-700 hover:text-gray-900 mb-1">
            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/>
            </svg>
            All conversations
        </a>
        <h1 class="text-xl font-bold text-gray-900">{{ $otherName }}</h1>
    </div>
</div>

<div class="rounded-2xl border border-gray-200 bg-white flex flex-col" style="height: min(72vh, 700px);">
    {{-- Scrollable thread ──────────────────────────────────────────── --}}
    <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-3" id="chatThread">
        @if($messages->isEmpty())
            <p class="text-center text-sm text-gray-600 py-8">No messages yet. Send the first one below.</p>
        @else
            @php $lastDate = null; @endphp
            @foreach($messages as $msg)
                @php
                    $isMine = $msg->from_user_id === $myId;
                    $dayLabel = $msg->created_at->format('d M Y');
                    $showDateSeparator = $dayLabel !== $lastDate;
                    $lastDate = $dayLabel;
                @endphp

                @if($showDateSeparator)
                    <div class="flex items-center gap-3 py-1">
                        <div class="flex-1 h-px bg-gray-200"></div>
                        <span class="text-[11px] uppercase tracking-wider text-gray-600 font-semibold">{{ $dayLabel }}</span>
                        <div class="flex-1 h-px bg-gray-200"></div>
                    </div>
                @endif

                <div class="flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[75%] sm:max-w-[60%]">
                        <div class="rounded-2xl px-3.5 py-2 text-sm shadow-sm
                            {{ $isMine
                                ? 'bg-brand-500 text-white rounded-br-md'
                                : 'bg-gray-100 text-gray-900 rounded-bl-md' }}">
                            <p class="whitespace-pre-wrap break-words">{{ $msg->body }}</p>
                        </div>
                        <p class="text-[11px] text-gray-600 mt-1 {{ $isMine ? 'text-right' : 'text-left' }}">
                            {{ $msg->created_at->format('h:i A') }}
                            @if($isMine && $msg->read_at !== null)
                                <span class="text-leaf-600">· seen</span>
                            @endif
                        </p>
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    {{-- Compose row pinned at the bottom ──────────────────────────── --}}
    <form method="POST" action="{{ route('messages.store', ['user' => $other->id]) }}"
        class="border-t border-gray-200 p-3 sm:p-4 flex items-end gap-2 bg-gray-50">
        @csrf
        <textarea name="body"
            rows="2"
            required
            maxlength="4000"
            placeholder="Write your message…"
            class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500"
            onkeydown="if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) { this.form.submit(); }"></textarea>
        <button type="submit"
            class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">
            Send
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
            </svg>
        </button>
    </form>
</div>

@error('body')
    <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
@enderror

<script>
    // Auto-scroll to bottom on every page load — newest message
    // visible without the user having to scroll.
    (() => {
        const thread = document.getElementById('chatThread');
        if (thread) thread.scrollTop = thread.scrollHeight;
    })();
</script>
@endsection
