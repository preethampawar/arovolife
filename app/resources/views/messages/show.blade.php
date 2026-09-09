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
            <x-lucide-chevron-left class="w-3.5 h-3.5" />
            All conversations
        </a>
        <h1 class="text-xl font-bold text-gray-900">{{ $otherName }}</h1>
    </div>

    @if($blockingEnabled)
        @if($hasBlocked)
            <form method="POST" action="{{ route('messages.unblock', ['user' => $other->id]) }}"
                data-confirm="Start receiving messages from {{ $otherName }} again?"
                data-confirm-title="Unblock this person">
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    <x-lucide-user-check class="w-3.5 h-3.5" />
                    Unblock
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('messages.block', ['user' => $other->id]) }}"
                data-confirm="Stop receiving messages from {{ $otherName }}?"
                data-confirm-title="Block this person"
                data-confirm-impact="They will not be told they were blocked. You can undo this at any time from this page. Company accounts can never be blocked.">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    <x-lucide-user-x class="w-3.5 h-3.5" />
                    Block
                </button>
            </form>
        @endif
    @endif
</div>

@if(session('status'))
    <div class="mb-4 rounded-lg border border-leaf-200 bg-leaf-50 px-4 py-3 text-sm text-leaf-800">{{ session('status') }}</div>
@endif

@if($hasBlocked)
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        You have blocked this person. They cannot send you new messages, and they have not been told.
    </div>
@endif

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
                                ? 'bg-brand-700 text-white rounded-br-md'
                                : 'bg-gray-100 text-gray-900 rounded-bl-md' }}">
                            <p class="whitespace-pre-wrap break-words">{{ $msg->body }}</p>
                        </div>
                        <p class="text-[11px] text-gray-600 mt-1 {{ $isMine ? 'text-right' : 'text-left' }}">
                            {{ $msg->created_at->format('h:i A') }}
                            @if($isMine && $msg->read_at !== null)
                                <span class="text-leaf-600">· seen</span>
                            @endif
                            @if(! $isMine && $reportingEnabled)
                                <button type="button"
                                    class="js-report-message text-gray-500 underline hover:text-gray-800"
                                    data-message-id="{{ $msg->id }}">· report</button>
                            @endif
                        </p>
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    {{-- Compose row pinned at the bottom ──────────────────────────── --}}
    @if(! $canMessage)
        <div class="border-t border-gray-200 bg-gray-50 p-4 text-center text-sm text-gray-700">
            You can no longer send messages in this conversation. You can still read what was said.
        </div>
    @else
    <div class="border-t border-gray-200 bg-gray-50 px-3 pt-3 sm:px-4">
        {{-- DPDP §4/§5: the person typing is told, before they type, that what
             they send can be read by staff if it is reported. The recipient is
             told the same thing in the report dialog. --}}
        <p class="text-[11px] leading-relaxed text-gray-500">
            @if($reportingEnabled)
                {{ $otherName }} can report a message you send here. If they do, our team reads that message and a few either side of it.
            @endif
            Please do not send a full PAN or Aadhaar number — the last four digits are enough.
        </p>
    </div>
    <form method="POST" action="{{ route('messages.store', ['user' => $other->id]) }}"
        class="p-3 sm:p-4 pt-2 flex items-end gap-2 bg-gray-50">
        @csrf
        <textarea name="body"
            rows="2"
            required
            maxlength="{{ $maxBodyChars }}"
            placeholder="Write your message…"
            class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500"
            onkeydown="if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) { this.form.submit(); }"></textarea>
        <button type="submit"
            class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors">
            Send
            <x-lucide-send class="w-4 h-4" />
        </button>
    </form>
    @endif
</div>

@error('body')
    <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
@enderror

@if($reportingEnabled)
    {{-- Report dialog. One form, retargeted at whichever message was clicked,
         so the page carries a single copy rather than one per bubble. --}}
    <div id="reportDialog" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
        <form method="POST" id="reportForm" action="" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            @csrf
            <h2 class="text-base font-semibold text-gray-900 mb-1">Report this message</h2>
            <p class="text-xs text-gray-600 mb-4">Our team will read the reported message and a few either side of it for context. {{ $otherName }} is not told who reported it.</p>

            <label class="block text-xs font-medium text-gray-700 mb-1">What is wrong with it?</label>
            <select name="category" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-4">
                @foreach($reportCategories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <label class="block text-xs font-medium text-gray-700 mb-1">Anything to add? <span class="text-gray-500">(optional)</span></label>
            <textarea name="reason" rows="3" maxlength="2000"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-1"
                placeholder="Tell us anything that helps us understand it."></textarea>
            <p class="text-[11px] text-gray-500 mb-5">Please do not include a full PAN or Aadhaar number — the last four digits are enough.</p>

            <div class="flex justify-end gap-3">
                <button type="button" id="reportCancel" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">Send report</button>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const dialog = document.getElementById('reportDialog');
            const form = document.getElementById('reportForm');
            const template = @json(route('messages.report', ['message' => '__ID__']));

            const close = () => { dialog.classList.add('hidden'); dialog.classList.remove('flex'); };

            document.querySelectorAll('.js-report-message').forEach((button) => {
                button.addEventListener('click', () => {
                    form.action = template.replace('__ID__', button.dataset.messageId);
                    dialog.classList.remove('hidden');
                    dialog.classList.add('flex');
                });
            });

            document.getElementById('reportCancel').addEventListener('click', close);
            dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
        })();
    </script>
@endif

<script>
    // Auto-scroll to bottom on every page load — newest message
    // visible without the user having to scroll.
    (() => {
        const thread = document.getElementById('chatThread');
        if (thread) thread.scrollTop = thread.scrollHeight;
    })();
</script>
@endsection
